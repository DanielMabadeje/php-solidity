<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

class ContractNode
{
    /**
     * @param StateVariableNode[] $stateVariables
     * @param EventNode[]         $events
     * @param ModifierNode[]      $modifiers
     * @param FunctionNode[]      $functions
     * @param string[]            $inherits
     */
    public function __construct(
        public readonly string $name,
        public readonly string $license,
        public readonly string $solidityVersion,
        public array $stateVariables = [],
        public array $events         = [],
        public array $modifiers      = [],
        public array $functions      = [],
        public array $inherits       = [],
    ) {}
}
