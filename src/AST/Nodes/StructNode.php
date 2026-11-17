<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class StructNode
{
    /** @param ParameterNode[] $members */
    public function __construct(
        public readonly string $name,
        public array $members = [],
        public readonly ?int $line = null,
    ) {}
}
