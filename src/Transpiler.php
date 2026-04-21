<?php

declare(strict_types=1);

namespace PhpSolidity;

use PhpSolidity\Emitter\SolidityEmitter;
use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\Parser\ContractParser;

class Transpiler
{
    public function __construct(
        private readonly ContractParser  $parser  = new ContractParser(),
        private readonly SolidityEmitter $emitter = new SolidityEmitter(),
    ) {}

    /**
     * Transpile a PHP contract file to a TranspileResult.
     *
     * @throws ParseException
     * @throws CompileException
     */
    public function transpileFile(string $phpFilePath): TranspileResult
    {
        if (! file_exists($phpFilePath)) {
            throw new ParseException("File not found: {$phpFilePath}");
        }

        return $this->transpile(file_get_contents($phpFilePath));
    }

    /**
     * Transpile PHP source code to a TranspileResult.
     *
     * @throws ParseException
     * @throws CompileException
     */
    public function transpile(string $phpSource): TranspileResult
    {
        $contractNode   = $this->parser->parseSource($phpSource);
        $soliditySource = $this->emitter->emit($contractNode);

        return new TranspileResult(
            contractName:   $contractNode->name,
            soliditySource: $soliditySource,
            contractNode:   $contractNode,
        );
    }

    /**
     * Transpile a PHP contract file and write the .sol file to disk.
     *
     * @throws ParseException
     * @throws CompileException
     */
    public function transpileAndSave(string $phpFilePath, string $outputDir = '.'): TranspileResult
    {
        $result  = $this->transpileFile($phpFilePath);
        $outPath = rtrim($outputDir, '/') . '/' . $result->contractName . '.sol';

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        file_put_contents($outPath, $result->soliditySource);

        return $result->withOutputPath($outPath);
    }
}