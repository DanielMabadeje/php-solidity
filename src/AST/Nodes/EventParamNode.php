<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class EventParamNode
{
    public function __construct(
        public readonly string $solidityType,
        public readonly string $name,
        public readonly bool   $indexed = false,
    ) {}
}
