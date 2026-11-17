<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

use PhpParser\Node\Stmt;

class FunctionNode
{
    /**
     * @param string          $kind       function | constructor | receive | fallback
     * @param ParameterNode[] $params
     * @param string[]        $modifiers  Names of applied Solidity modifiers (from #[Guarded])
     * @param Stmt[]|null     $body       php-parser statements; null for interface functions
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $visibility,    // public | external | internal | private
        public readonly string  $mutability,    // '' | view | pure | payable
        public readonly string  $returnType,    // Solidity type, '' when void
        public readonly bool    $isConstructor,
        public array $params    = [],
        public array $modifiers = [],
        public readonly ?array $body = [],
        public readonly string $kind = 'function',
        public readonly bool $isVirtual = false,
        public readonly bool $isOverride = false,
        public readonly ?int $line = null,
    ) {}
}
