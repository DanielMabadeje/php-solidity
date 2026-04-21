<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class EventNode
{
    /** @param EventParamNode[] $params */
    public function __construct(
        public readonly string $name,
        public array $params = [],
    ) {}
}
