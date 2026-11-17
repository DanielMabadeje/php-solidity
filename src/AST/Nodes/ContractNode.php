<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

/**
 * A Solidity contract, abstract contract, interface or library.
 */
class ContractNode
{
    /**
     * @param string                $kind   contract | abstract contract | interface | library
     * @param StateVariableNode[]   $stateVariables
     * @param EventNode[]           $events
     * @param ErrorNode[]           $errors
     * @param ModifierNode[]        $modifiers
     * @param FunctionNode[]        $functions
     * @param string[]              $inherits
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
        public array $errors         = [],
        public readonly string $kind = 'contract',
        public readonly ?int $line   = null,
    ) {}
}
