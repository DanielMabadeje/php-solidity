<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

use PhpParser\Node\Expr;

class ParameterNode
{
    public function __construct(
        public readonly string $name,
        public readonly string $solidityType,
        public readonly ?Expr  $defaultValue = null,
    ) {}
}
