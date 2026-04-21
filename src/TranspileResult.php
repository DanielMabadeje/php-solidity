<?php

declare(strict_types=1);

namespace PhpSolidity;

use PhpSolidity\AST\Nodes\ContractNode;

class TranspileResult
{
    public function __construct(
        public readonly string       $contractName,
        public readonly string       $soliditySource,
        public readonly ContractNode $contractNode,
        public readonly ?string      $outputPath = null,
    ) {}

    public function withOutputPath(string $path): self
    {
        return new self(
            contractName:   $this->contractName,
            soliditySource: $this->soliditySource,
            contractNode:   $this->contractNode,
            outputPath:     $path,
        );
    }

    public function dump(): void
    {
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║  Contract : {$this->contractName}\n";

        if ($this->outputPath) {
            echo "║  Saved to : {$this->outputPath}\n";
        }

        echo "╚══════════════════════════════════════════════════════════╝\n\n";
        echo $this->soliditySource;
    }
}