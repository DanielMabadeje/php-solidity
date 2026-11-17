<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

/** A custom error declaration: `error InsufficientBalance(uint256 available, uint256 required);` */
class ErrorNode
{
    /** @param EventParamNode[] $params (reuses EventParamNode: type + name, never indexed) */
    public function __construct(
        public readonly string $name,
        public array $params = [],
    ) {}
}
