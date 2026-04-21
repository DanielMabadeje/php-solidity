<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class StateVariableNode
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $solidityType,
        public readonly string  $visibility,
        public readonly string  $mutability,
        public readonly ?string $defaultValue = null,
    ) {}
}
