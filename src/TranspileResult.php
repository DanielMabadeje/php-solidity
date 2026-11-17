<?php

declare(strict_types=1);

namespace PhpSolidity;

use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\SourceUnitNode;
use PhpSolidity\Diagnostics\Diagnostic;

class TranspileResult
{
    /** @param Diagnostic[] $warnings */
    public function __construct(
        public readonly string         $contractName,
        public readonly string         $soliditySource,
        public readonly ContractNode   $contractNode,
        public readonly ?string        $outputPath = null,
        public readonly ?SourceUnitNode $sourceUnit = null,
        public readonly array          $warnings = [],
    ) {}

    public function withOutputPath(string $path): self
    {
        return new self(
            contractName:   $this->contractName,
            soliditySource: $this->soliditySource,
            contractNode:   $this->contractNode,
            outputPath:     $path,
            sourceUnit:     $this->sourceUnit,
            warnings:       $this->warnings,
        );
    }
}
