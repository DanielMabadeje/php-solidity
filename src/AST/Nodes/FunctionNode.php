<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class FunctionNode
{
    /**
     * @param ParameterNode[] $params
     * @param string[]        $modifiers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,
        public readonly string $mutability,
        public readonly string $returnType,
        public readonly bool   $isConstructor,
        public array $params    = [],
        public array $modifiers = [],
        public readonly string $body = '',
    ) {}
}
