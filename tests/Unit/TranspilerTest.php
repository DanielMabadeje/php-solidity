<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\Exceptions\ValidationException;
use PhpSolidity\Tests\TestCase;
use PhpSolidity\Transpiler;

final class TranspilerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpsolidity-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testValidatesByDefault(): void
    {
        $this->expectException(ValidationException::class);
        (new Transpiler())->transpile($this->contract('    private float $x;'));
    }

    public function testValidationCanBeSwitchedOff(): void
    {
        // Without the validator the problem still surfaces, from the type mapper.
        $this->expectException(\PhpSolidity\Exceptions\UnsupportedTypeException::class);
        (new Transpiler(validate: false))->transpile($this->contract('    private float $x;'));
    }

    public function testReturnsWarningsWithTheResult(): void
    {
        $result = (new Transpiler())->transpile($this->contract("    public function f(): uint256\n    {\n        return 1;\n    }"));

        $this->assertSame(['W401'], array_map(static fn ($d) => $d->code, $result->warnings));
    }

    public function testResultDescribesTheContract(): void
    {
        $result = (new Transpiler())->transpile($this->contract(''));

        $this->assertSame('T', $result->contractName);
        $this->assertSame('T', $result->contractNode->name);
        $this->assertStringStartsWith("// SPDX-License-Identifier: MIT\npragma solidity ^0.8.20;", $result->soliditySource);
    }

    public function testTranspileAndSaveWritesTheSolFile(): void
    {
        $source = $this->dir . '/Thing.php';
        file_put_contents($source, $this->contract(''));

        $result = (new Transpiler())->transpileAndSave($source, $this->dir . '/out');

        $this->assertFileExists($this->dir . '/out/T.sol');
        $this->assertSame($result->soliditySource, file_get_contents($this->dir . '/out/T.sol'));
        $this->assertSame($this->dir . '/out' . DIRECTORY_SEPARATOR . 'T.sol', $result->outputPath);

        unlink($this->dir . '/out/T.sol');
        rmdir($this->dir . '/out');
    }

    public function testMissingFileIsAParseException(): void
    {
        $this->expectException(ParseException::class);
        (new Transpiler())->transpileFile($this->dir . '/nope.php');
    }

    public function testOutputIsDeterministic(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../examples/MyToken.php');

        $this->assertSame(
            (new Transpiler())->transpile($php)->soliditySource,
            (new Transpiler())->transpile($php)->soliditySource,
        );
    }
}
