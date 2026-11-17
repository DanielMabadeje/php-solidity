<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Golden;

use PhpSolidity\Tests\TestCase;
use PhpSolidity\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Golden-file tests: every PHP contract under tests/fixtures/contracts and examples/ must
 * transpile to exactly the .sol file stored in tests/fixtures/expected.
 *
 * The stored files are also compiled with solc in CI (see scripts/solc-check.js), so a
 * golden file is proof that the output is *valid Solidity*, not merely stable text.
 *
 * To accept an intentional change:   UPDATE_GOLDEN=1 vendor/bin/phpunit --testsuite golden
 */
final class GoldenTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function contracts(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/tests/fixtures/contracts/*.php') ?: [],
            glob($root . '/examples/*.php') ?: [],
        );
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file, '.php')] = [$file];
        }

        return $cases;
    }

    #[DataProvider('contracts')]
    public function testTranspilesToTheStoredSolidity(string $file): void
    {
        $result   = (new Transpiler())->transpileFile($file);
        $expected = dirname(__DIR__) . '/fixtures/expected/' . basename($file, '.php') . '.sol';

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($expected, $result->soliditySource);
        }

        $this->assertFileExists($expected, 'No golden file yet. Run with UPDATE_GOLDEN=1 to create it.');
        $this->assertSame(file_get_contents($expected), $result->soliditySource);
    }

    public function testEveryGoldenFileHasASourceContract(): void
    {
        $sources = array_map(static fn (array $c) => basename($c[0], '.php'), self::contracts());

        foreach (glob(dirname(__DIR__) . '/fixtures/expected/*.sol') ?: [] as $golden) {
            $this->assertContains(basename($golden, '.sol'), $sources, basename($golden) . ' is orphaned');
        }
    }
}
