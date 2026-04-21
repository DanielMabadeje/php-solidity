<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class ParameterNode
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $solidityType,
        public readonly ?string $defaultValue = null,
    ) {}
}
