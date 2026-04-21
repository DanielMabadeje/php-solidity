<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class ModifierNode
{
    /** @param ParameterNode[] $params */
    public function __construct(
        public readonly string $name,
        public array $params = [],
        public readonly string $body = '',
    ) {}
}
