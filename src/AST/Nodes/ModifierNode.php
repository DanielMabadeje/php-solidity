<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

use PhpParser\Node\Stmt;

class ModifierNode
{
    /**
     * @param ParameterNode[] $params
     * @param Stmt[]          $body
     */
    public function __construct(
        public readonly string $name,
        public array $params = [],
        public readonly array $body = [],
        public readonly ?int $line = null,
    ) {}
}
