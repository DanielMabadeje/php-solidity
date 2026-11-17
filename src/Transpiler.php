<?php

declare(strict_types=1);

namespace PhpSolidity;

use PhpSolidity\Emitter\SolidityEmitter;
use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\Exceptions\ValidationException;
use PhpSolidity\Parser\ContractParser;
use PhpSolidity\Validator\ContractValidator;

/**
 * The pipeline in one place:
 *
 *   PHP source ──validate──▶ ──ContractParser──▶ Contract IR ──SolidityEmitter──▶ Solidity text
 */
class Transpiler
{
    public function __construct(
        private readonly ContractParser   $parser    = new ContractParser(),
        private readonly SolidityEmitter  $emitter   = new SolidityEmitter(),
        private readonly ContractValidator $validator = new ContractValidator(),
        private readonly bool             $validate  = true,
    ) {}

    /**
     * @throws ParseException
     * @throws CompileException
     * @throws ValidationException
     */
    public function transpileFile(string $phpFilePath): TranspileResult
    {
        if (! is_file($phpFilePath)) {
            throw new ParseException("File not found: {$phpFilePath}");
        }

        return $this->transpile((string) file_get_contents($phpFilePath));
    }

    /**
     * Validate (unless disabled) and transpile PHP source.
     *
     * @throws ParseException
     * @throws CompileException
     * @throws ValidationException
     */
    public function transpile(string $phpSource): TranspileResult
    {
        $warnings = [];

        if ($this->validate) {
            $this->validator->validate($phpSource, throwOnError: true);
            $warnings = $this->validator->getWarningDiagnostics();
        }

        $unit    = $this->parser->parseSourceUnit($phpSource);
        $primary = $unit->primary();

        if ($primary === null) {
            throw new ParseException('No #[Contract] class found. Mark your class with the #[Contract] attribute.');
        }

        return new TranspileResult(
            contractName:   $primary->name,
            soliditySource: $this->emitter->emit($unit),
            contractNode:   $primary,
            sourceUnit:     $unit,
            warnings:       $warnings,
        );
    }

    /**
     * Transpile a PHP contract file and write `<ContractName>.sol` to $outputDir.
     *
     * @throws ParseException
     * @throws CompileException
     * @throws ValidationException
     */
    public function transpileAndSave(string $phpFilePath, string $outputDir = '.'): TranspileResult
    {
        $result  = $this->transpileFile($phpFilePath);
        $outPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $result->contractName . '.sol';

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new CompileException("Cannot create output directory: {$outputDir}");
        }

        file_put_contents($outPath, $result->soliditySource);

        return $result->withOutputPath($outPath);
    }
}
