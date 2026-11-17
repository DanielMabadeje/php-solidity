<?php

declare(strict_types=1);

namespace PhpSolidity\Cli;

use PhpSolidity\Config\Config;
use PhpSolidity\Diagnostics\Diagnostic;
use PhpSolidity\Exceptions\PhpSolidityException;
use PhpSolidity\Parser\ContractParser;
use PhpSolidity\Transpiler;
use PhpSolidity\Validator\ContractValidator;

/**
 * The command line interface. `bin/phpsolidity` is a thin wrapper around run(),
 * which keeps every command testable without spawning a process.
 *
 * Exit codes: 0 success · 1 compile or validation failure · 2 usage error · 3 solc not found
 */
final class Application
{
    public const VERSION = '0.1.0';

    /** Options that take a value. */
    private const VALUE_OPTIONS = ['output' => 'output', 'o' => 'output', 'config' => 'config', 'solc' => 'solc', 'watch-cycles' => 'watch-cycles'];

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param string[] $args arguments without the script name */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $rest    = array_slice($args, 1);

        try {
            return match ($command) {
                'compile'                     => $this->compileCommand($rest, build: false),
                'build'                       => $this->compileCommand($rest, build: true),
                'validate'                    => $this->validateCommand($rest),
                'ast'                         => $this->astCommand($rest),
                'init'                        => $this->initCommand(),
                'version', '--version', '-V'  => $this->version(),
                'help', '--help', '-h'        => $this->help(),
                default                       => $this->usageError("Unknown command '{$command}'. Run 'phpsolidity help' for usage."),
            };
        } catch (PhpSolidityException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    // ─────────────────────────────────────────────────────────
    // compile / build
    // ─────────────────────────────────────────────────────────

    /** @param string[] $args */
    private function compileCommand(array $args, bool $build): int
    {
        [$paths, $options] = $this->parseArgs($args);

        $config = $this->loadConfig($options);
        $paths  = $paths !== [] ? $paths : $config->input;

        if ($paths === []) {
            return $this->usageError('Usage: phpsolidity ' . ($build ? 'build' : 'compile') . ' <file-or-directory>... [--output <dir>] [--watch]');
        }

        $output = is_string($options['output'] ?? null) ? $options['output'] : $config->output;
        $files  = $this->discover($paths);

        if ($files === []) {
            $this->error('No PHP contract files found in: ' . implode(', ', $paths));

            return 1;
        }

        $quiet = isset($options['quiet']);

        if (isset($options['watch'])) {
            return $this->watch($files, $paths, $output, $quiet, $build, $config, $options);
        }

        return $this->compileAll($files, $output, $quiet, $build, $config, $options);
    }

    /**
     * @param string[]             $files
     * @param array<string, mixed> $options
     */
    private function compileAll(array $files, string $output, bool $quiet, bool $build, Config $config, array $options): int
    {
        $failed  = 0;
        $written = [];

        foreach ($files as $file) {
            $sol = $this->compileFile($file, $output, $quiet, $written);
            if ($sol === null) {
                $failed++;
                continue;
            }

            if ($build) {
                $code = $this->runSolc($sol, $output, $config, is_string($options['solc'] ?? null) ? $options['solc'] : null);
                if ($code === 3) {
                    return 3;
                }
                if ($code !== 0) {
                    $failed++;
                }
            }
        }

        $total = count($files);
        if (! $quiet) {
            $this->line('');
            $this->line(($total - $failed) . " of {$total} file(s) compiled" . ($failed > 0 ? ", {$failed} failed." : '.'));
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Validate and transpile one file. Returns the path of the written .sol file, or null on failure.
     *
     * @param array<string, string> $written contract name => source file, to detect clashes
     */
    private function compileFile(string $file, string $outputDir, bool $quiet, array &$written): ?string
    {
        $source    = (string) file_get_contents($file);
        $validator = (new ContractValidator())->validate($source, throwOnError: false);

        $this->printDiagnostics($file, $validator->getDiagnostics(), $quiet);

        if (! $validator->isValid()) {
            $this->error("{$file}: not compiled (" . count($validator->getErrorDiagnostics()) . ' validation error(s))');

            return null;
        }

        try {
            $result = (new Transpiler(validate: false))->transpileAndSave($file, $outputDir);
        } catch (PhpSolidityException $e) {
            $line = $e->getSourceLine();
            $this->error($file . ($line !== null ? ":{$line}" : '') . '  ' . $e->getMessage());

            return null;
        }

        if (isset($written[$result->contractName]) && $written[$result->contractName] !== $file) {
            $this->error("{$file}: contract '{$result->contractName}' was already written from {$written[$result->contractName]}; the output was overwritten.");
        }
        $written[$result->contractName] = $file;

        if (! $quiet) {
            $this->line("compiled {$file} -> {$result->outputPath}");
        }

        return $result->outputPath;
    }

    // ─────────────────────────────────────────────────────────
    // watch
    // ─────────────────────────────────────────────────────────

    /**
     * @param string[]             $files
     * @param string[]             $paths
     * @param array<string, mixed> $options
     */
    private function watch(array $files, array $paths, string $output, bool $quiet, bool $build, Config $config, array $options): int
    {
        $cycles = isset($options['watch-cycles']) ? (int) $options['watch-cycles'] : null;
        $seen   = $this->mtimes($files);

        $this->compileAll($files, $output, $quiet, $build, $config, $options);
        $this->line('Watching for changes (Ctrl+C to stop)...');

        for ($i = 0; $cycles === null || $i < $cycles; $i++) {
            if ($cycles === null) {
                sleep(1);
            }

            $current = $this->mtimes($this->discover($paths));
            $changed = array_keys(array_filter(
                $current,
                static fn (int $time, string $file) => ! isset($seen[$file]) || $seen[$file] !== $time,
                ARRAY_FILTER_USE_BOTH,
            ));
            $seen = $current;

            if ($changed !== []) {
                $this->line('');
                $this->line('Change detected: ' . implode(', ', $changed));
                $this->compileAll($changed, $output, $quiet, $build, $config, $options);
            }
        }

        return 0;
    }

    /**
     * @param string[] $files
     * @return array<string, int>
     */
    private function mtimes(array $files): array
    {
        clearstatcache();
        $times = [];
        foreach ($files as $file) {
            $times[$file] = (int) @filemtime($file);
        }

        return $times;
    }

    // ─────────────────────────────────────────────────────────
    // solc
    // ─────────────────────────────────────────────────────────

    private function runSolc(string $solFile, string $outputDir, Config $config, ?string $binary): int
    {
        $solc = $binary ?? $this->findSolc();

        if ($solc === null) {
            $this->error("The 'solc' compiler was not found. Install it (npm i -g solc, brew install solidity, or use Foundry/Hardhat), or pass --solc <path>.");

            return 3;
        }

        $target  = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . pathinfo($solFile, PATHINFO_FILENAME);
        $command = [$solc, '--abi', '--bin', '--overwrite', '-o', $target, $solFile];
        if ($config->optimize) {
            array_splice($command, 3, 0, ['--optimize', '--optimize-runs', (string) $config->runs]);
        }

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            $this->error("Could not start solc ({$solc}).");

            return 3;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code   = proc_close($process);

        if ($code !== 0) {
            $this->error("solc failed for {$solFile}:\n" . trim($stderr . $stdout));

            return 1;
        }

        $this->line("built {$solFile} -> {$target}/ (ABI + bytecode)");

        return 0;
    }

    private function findSolc(): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $names = DIRECTORY_SEPARATOR === '\\' ? ['solc.exe', 'solc.cmd', 'solc'] : ['solc'];

        foreach ($paths as $dir) {
            foreach ($names as $name) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────
    // validate / ast / init
    // ─────────────────────────────────────────────────────────

    /** @param string[] $args */
    private function validateCommand(array $args): int
    {
        [$paths, $options] = $this->parseArgs($args);

        if ($paths === []) {
            return $this->usageError('Usage: phpsolidity validate <file-or-directory>...');
        }

        $files  = $this->discover($paths);
        $errors = 0;

        foreach ($files as $file) {
            $validator = (new ContractValidator())->validate((string) file_get_contents($file), throwOnError: false);
            $this->printDiagnostics($file, $validator->getDiagnostics(), isset($options['quiet']));

            $count   = count($validator->getErrorDiagnostics());
            $errors += $count;

            if (! isset($options['quiet'])) {
                $this->line($count === 0 ? "ok  {$file}" : "FAIL  {$file} ({$count} error(s))");
            }
        }

        return $errors > 0 ? 1 : 0;
    }

    /** @param string[] $args */
    private function astCommand(array $args): int
    {
        [$paths, $options] = $this->parseArgs($args);
        $file = $paths[0] ?? null;

        if ($file === null || ! is_file($file)) {
            return $this->usageError('Usage: phpsolidity ast <file.php> [--php]');
        }

        $source = (string) file_get_contents($file);
        $dumper = new AstDumper();

        $this->write(isset($options['php'])
            ? $dumper->dumpPhp($source)
            : $dumper->dumpContract((new ContractParser())->parseSourceUnit($source)));

        return 0;
    }

    private function initCommand(): int
    {
        if (file_exists(Config::FILE)) {
            $this->error(Config::FILE . ' already exists.');

            return 1;
        }

        file_put_contents(Config::FILE, Config::template());
        $this->line('Created ' . Config::FILE);

        return 0;
    }

    // ─────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────

    /**
     * @param string[]             $paths
     * @return string[]
     */
    private function discover(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            if (! is_dir($path)) {
                throw new PhpSolidityException("Path not found: {$path}");
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => ! in_array($f->getFilename(), ['vendor', 'node_modules', '.git'], true),
            ));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php'
                    && $this->looksLikeContract((string) file_get_contents($file->getPathname()))) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function looksLikeContract(string $source): bool
    {
        return preg_match('/#\[\s*(Contract|Library)\b/', $source) === 1
            || preg_match('/^\s*interface\s+\w+/m', $source) === 1;
    }

    /**
     * @param string[] $args
     * @return array{0: string[], 1: array<string, string|bool>}
     */
    private function parseArgs(array $args): array
    {
        $positional = [];
        $options    = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if (! str_starts_with($arg, '-') || $arg === '-') {
                $positional[] = $arg;
                continue;
            }

            $name  = ltrim($arg, '-');
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (isset(self::VALUE_OPTIONS[$name])) {
                $value ??= $args[++$i] ?? '';
                $options[self::VALUE_OPTIONS[$name]] = $value;
            } else {
                $options[$name] = true;
            }
        }

        return [$positional, $options];
    }

    /** @param array<string, string|bool> $options */
    private function loadConfig(array $options): Config
    {
        if (is_string($options['config'] ?? null)) {
            return Config::load($options['config']);
        }

        return is_file(Config::FILE) ? Config::load(Config::FILE) : Config::default();
    }

    /** @param Diagnostic[] $diagnostics */
    private function printDiagnostics(string $file, array $diagnostics, bool $quiet): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                $this->error($diagnostic->format($file));
            } elseif (! $quiet) {
                $this->line('warning: ' . $diagnostic->format($file));
            }
        }
    }

    private function version(): int
    {
        $this->line('phpsolidity ' . self::VERSION);

        return 0;
    }

    private function help(): int
    {
        $this->write(<<<'HELP'

phpsolidity - write Ethereum smart contracts in PHP

USAGE
  phpsolidity <command> [arguments] [options]

COMMANDS
  compile <path>...     Transpile PHP contracts (files or directories) to Solidity
  build <path>...       Compile, then run solc to produce ABI and bytecode
  validate <path>...    Check contracts without writing anything
  ast <file.php>        Print the contract tree (add --php for the raw PHP syntax tree)
  init                  Create a phpsolidity.json config file
  help                  Show this message
  version               Show the installed version

OPTIONS
  -o, --output <dir>    Where to write .sol files (default: current directory)
      --config <file>   Use a specific config file (default: ./phpsolidity.json)
      --watch           Recompile whenever a contract file changes
      --solc <path>     Path to the solc binary (build only)
      --quiet           Print errors only

EXAMPLES
  phpsolidity compile contracts/MyToken.php --output build
  phpsolidity compile contracts --output build --watch
  phpsolidity validate contracts
  phpsolidity ast examples/MyToken.php

EXIT CODES
  0 success   1 compile or validation failure   2 usage error   3 solc not found

HELP);

        return 0;
    }

    private function usageError(string $message): int
    {
        $this->error($message);

        return 2;
    }

    private function line(string $text): void
    {
        $this->write($text . "\n");
    }

    private function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    private function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }
}
