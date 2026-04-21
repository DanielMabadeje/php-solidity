<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

// ─────────────────────────────────────────────────────────────
// Root Contract Node
// ─────────────────────────────────────────────────────────────

class ContractNode
{
    /** @param StateVariableNode[] $stateVariables */
    /** @param EventNode[]         $events */
    /** @param ModifierNode[]      $modifiers */
    /** @param FunctionNode[]      $functions */
    /** @param string[]            $inherits */
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

// ─────────────────────────────────────────────────────────────
// State Variable
// ─────────────────────────────────────────────────────────────

class StateVariableNode
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $solidityType,
        public readonly string  $visibility,    // public | private | internal
        public readonly string  $mutability,    // '' | constant | immutable
        public readonly ?string $defaultValue = null,
    ) {}
}

// ─────────────────────────────────────────────────────────────
// Event
// ─────────────────────────────────────────────────────────────

class EventNode
{
    /** @param EventParamNode[] $params */
    public function __construct(
        public readonly string $name,
        public array $params = [],
    ) {}
}

class EventParamNode
{
    public function __construct(
        public readonly string $solidityType,
        public readonly string $name,
        public readonly bool   $indexed = false,
    ) {}
}

// ─────────────────────────────────────────────────────────────
// Modifier
// ─────────────────────────────────────────────────────────────

class ModifierNode
{
    /** @param ParameterNode[] $params */
    public function __construct(
        public readonly string $name,
        public array $params = [],
        public readonly string $body = '',
    ) {}
}

// ─────────────────────────────────────────────────────────────
// Function
// ─────────────────────────────────────────────────────────────

class FunctionNode
{
    /**
     * @param ParameterNode[] $params
     * @param string[]        $modifiers  Names of applied Solidity modifiers
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $visibility,    // public | external | internal | private
        public readonly string  $mutability,    // '' | view | pure | payable
        public readonly string  $returnType,    // Solidity return type string
        public readonly bool    $isConstructor,
        public array $params    = [],
        public array $modifiers = [],
        public readonly string $body = '',
    ) {}
}

// ─────────────────────────────────────────────────────────────
// Parameter
// ─────────────────────────────────────────────────────────────

class ParameterNode
{
    public function __construct(
        public readonly string $name,
        public readonly string $solidityType,
        public readonly ?string $defaultValue = null,
    ) {}
}