<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

use PhpParser\Node\Expr;

class StateVariableNode
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $solidityType,
        public readonly string  $visibility,   // public | private | internal
        public readonly string  $mutability,   // '' | constant | immutable
        public readonly ?Expr   $defaultValue = null,
        public readonly ?int    $line = null,
    ) {}
}
