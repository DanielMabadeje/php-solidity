<?php

declare(strict_types=1);

namespace PhpSolidity\Config;

use PhpSolidity\Exceptions\PhpSolidityException;

/**
 * Settings from `phpsolidity.json`:
 *
 *     {
 *       "input":  ["contracts"],
 *       "output": "build/solidity",
 *       "solc":   { "optimize": true, "runs": 200 }
 *     }
 */
final class Config
{
    public const FILE = 'phpsolidity.json';

    /**
     * @param string[] $input
     */
    public function __construct(
        public readonly array $input = [],
        public readonly string $output = '.',
        public readonly bool $optimize = true,
        public readonly int $runs = 200,
    ) {}

    /** @throws PhpSolidityException */
    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new PhpSolidityException("Config file not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new PhpSolidityException("Config file is not valid JSON: {$path}");
        }

        $input = $data['input'] ?? [];
        $input = is_string($input) ? [$input] : $input;

        return new self(
            input:    array_values(array_filter($input, 'is_string')),
            output:   is_string($data['output'] ?? null) ? $data['output'] : '.',
            optimize: (bool) ($data['solc']['optimize'] ?? true),
            runs:     (int) ($data['solc']['runs'] ?? 200),
        );
    }

    public static function default(): self
    {
        return new self();
    }

    /** Contents of a starter config file. */
    public static function template(): string
    {
        return json_encode([
            'input'  => ['contracts'],
            'output' => 'build/solidity',
            'solc'   => ['optimize' => true, 'runs' => 200],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
