<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Cli\Application;
use PhpSolidity\Tests\TestCase;

final class CliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpsolidity-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/contracts/nested', 0777, true);
        file_put_contents($this->dir . '/contracts/Good.php', $this->contract("    #[External, View]\n    public function one(): uint256\n    {\n        return 1;\n    }"));
        file_put_contents($this->dir . '/contracts/nested/Other.php', str_replace('class T', 'class Other', $this->contract('')));
        file_put_contents($this->dir . '/contracts/helper.php', "<?php\nfunction helper() {}\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    /** @return array{int, string, string} exit code, stdout, stderr */
    private function cli(string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $this->assertNotFalse($err);

        $code = (new Application($out, $err))->run(array_values($args));

        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testCompilesASingleFile(): void
    {
        [$code, $out] = $this->cli('compile', $this->dir . '/contracts/Good.php', '--output', $this->dir . '/out');

        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/out/T.sol');
        $this->assertStringContainsString('1 of 1 file(s) compiled', $out);
    }

    public function testCompilesDirectoriesRecursivelyAndSkipsNonContracts(): void
    {
        [$code, $out] = $this->cli('compile', $this->dir . '/contracts', '-o', $this->dir . '/out');

        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/out/T.sol');
        $this->assertFileExists($this->dir . '/out/Other.sol');
        $this->assertStringContainsString('2 of 2 file(s) compiled', $out);
        $this->assertStringNotContainsString('helper.php', $out);
    }

    public function testValidationErrorsStopCompilationAndSetExitCode(): void
    {
        file_put_contents($this->dir . '/contracts/Bad.php', $this->contract('    private float $x;'));

        [$code, , $err] = $this->cli('compile', $this->dir . '/contracts/Bad.php', '--output', $this->dir . '/out');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[E101]', $err);
        $this->assertStringContainsString('Bad.php:', $err, 'errors name the file and line');
        $this->assertFileDoesNotExist($this->dir . '/out/T.sol');
    }

    public function testCompileErrorsShowFileAndLine(): void
    {
        file_put_contents($this->dir . '/contracts/Odd.php', $this->contract("    #[External]\n    public function f(): uint256\n    {\n        return \$missing;\n    }"));

        [$code, , $err] = $this->cli('compile', $this->dir . '/contracts/Odd.php', '--output', $this->dir . '/out');

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Odd\.php:\d+\s+Variable \$missing is used before it is declared/', $err);
    }

    public function testWarningsDoNotFailTheBuildAndQuietHidesThem(): void
    {
        file_put_contents($this->dir . '/contracts/Warn.php', $this->contract("    public function f(): uint256\n    {\n        return 1;\n    }"));

        [$code, $out] = $this->cli('compile', $this->dir . '/contracts/Warn.php', '-o', $this->dir . '/out');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[W401]', $out);

        [, $quiet] = $this->cli('compile', $this->dir . '/contracts/Warn.php', '-o', $this->dir . '/out', '--quiet');
        $this->assertStringNotContainsString('W401', $quiet);
    }

    public function testValidateCommand(): void
    {
        [$ok, $out] = $this->cli('validate', $this->dir . '/contracts');
        $this->assertSame(0, $ok);
        $this->assertStringContainsString('ok  ', $out);

        file_put_contents($this->dir . '/contracts/Bad.php', $this->contract('    private float $x;'));
        [$bad] = $this->cli('validate', $this->dir . '/contracts');
        $this->assertSame(1, $bad);
    }

    public function testAstCommandPrintsBothTrees(): void
    {
        [$code, $tree] = $this->cli('ast', $this->dir . '/contracts/Good.php');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Contract T', $tree);
        $this->assertStringContainsString('Function one() [external view] returns uint256', $tree);

        [, $php] = $this->cli('ast', $this->dir . '/contracts/Good.php', '--php');
        $this->assertStringContainsString('Stmt_Class', $php);
    }

    public function testConfigFileSuppliesInputAndOutput(): void
    {
        file_put_contents($this->dir . '/phpsolidity.json', json_encode([
            'input'  => [$this->dir . '/contracts'],
            'output' => $this->dir . '/from-config',
        ]));

        [$code] = $this->cli('compile', '--config', $this->dir . '/phpsolidity.json');

        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/from-config/T.sol');
    }

    public function testInitWritesAConfigFile(): void
    {
        $cwd = getcwd();
        chdir($this->dir);

        try {
            [$code] = $this->cli('init');
            $this->assertSame(0, $code);
            $this->assertFileExists($this->dir . '/phpsolidity.json');

            [$again] = $this->cli('init');
            $this->assertSame(1, $again, 'init must not overwrite an existing config');
        } finally {
            chdir((string) $cwd);
        }
    }

    public function testWatchCompilesOnceAndExitsAfterTheRequestedCycles(): void
    {
        [$code, $out] = $this->cli('compile', $this->dir . '/contracts/Good.php', '-o', $this->dir . '/out', '--watch', '--watch-cycles=1');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Watching for changes', $out);
        $this->assertFileExists($this->dir . '/out/T.sol');
    }

    public function testBuildReportsAMissingSolc(): void
    {
        [$code, , $err] = $this->cli('build', $this->dir . '/contracts/Good.php', '-o', $this->dir . '/out', '--solc', $this->dir . '/does-not-exist');

        $this->assertNotSame(0, $code);
        $this->assertTrue(str_contains($err, 'solc') || str_contains($err, 'Could not start'));
    }

    public function testUsageErrors(): void
    {
        $this->assertSame(2, $this->cli('compile')[0]);
        $this->assertSame(2, $this->cli('frobnicate')[0]);
        $this->assertSame(2, $this->cli('ast')[0]);
        $this->assertSame(1, $this->cli('compile', $this->dir . '/no-such-dir')[0]);
    }

    public function testHelpAndVersion(): void
    {
        [$code, $out] = $this->cli('help');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('compile <path>...', $out);

        [, $version] = $this->cli('version');
        $this->assertSame('phpsolidity ' . Application::VERSION . "\n", $version);

        $this->assertSame(0, $this->cli()[0], 'no arguments shows help');
    }
}
