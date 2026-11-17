<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class EnumNode
{
    /** @param string[] $cases */
    public function __construct(
        public readonly string $name,
        public array $cases = [],
        public readonly ?int $line = null,
    ) {}
}
