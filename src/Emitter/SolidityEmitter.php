<?php

declare(strict_types=1);

namespace PhpSolidity\Emitter;

use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EventNode;
use PhpSolidity\AST\Nodes\FunctionNode;
use PhpSolidity\AST\Nodes\ModifierNode;
use PhpSolidity\AST\Nodes\ParameterNode;
use PhpSolidity\AST\Nodes\StateVariableNode;

class SolidityEmitter
{
    private const INDENT = '    '; // 4 spaces

    /**
     * Emit a complete Solidity source file from a ContractNode.
     */
    public function emit(ContractNode $contract): string
    {
        $parts = [];

        // SPDX License + Pragma
        $parts[] = "// SPDX-License-Identifier: {$contract->license}";
        $parts[] = "pragma solidity {$contract->solidityVersion};";
        $parts[] = '';

        // Contract declaration
        $contractLine = "contract {$contract->name}";
        if (! empty($contract->inherits)) {
            $contractLine .= ' is ' . implode(', ', $contract->inherits);
        }
        $contractLine .= ' {';
        $parts[] = $contractLine;

        $body = [];

        // State variables
        if (! empty($contract->stateVariables)) {
            foreach ($contract->stateVariables as $var) {
                $body[] = $this->emitStateVariable($var);
            }
            $body[] = '';
        }

        // Events
        if (! empty($contract->events)) {
            foreach ($contract->events as $event) {
                $body[] = $this->emitEvent($event);
            }
            $body[] = '';
        }

        // Modifiers
        if (! empty($contract->modifiers)) {
            foreach ($contract->modifiers as $modifier) {
                foreach ($this->emitModifier($modifier) as $line) {
                    $body[] = $line;
                }
                $body[] = '';
            }
        }

        // Functions (constructor first)
        $constructor = null;
        $functions   = [];

        foreach ($contract->functions as $fn) {
            if ($fn->isConstructor) {
                $constructor = $fn;
            } else {
                $functions[] = $fn;
            }
        }

        if ($constructor !== null) {
            foreach ($this->emitFunction($constructor) as $line) {
                $body[] = $line;
            }
            $body[] = '';
        }

        foreach ($functions as $fn) {
            foreach ($this->emitFunction($fn) as $line) {
                $body[] = $line;
            }
            $body[] = '';
        }

        // Remove trailing blank lines inside the contract
        while (! empty($body) && $body[count($body) - 1] === '') {
            array_pop($body);
        }

        // Indent all body lines
        foreach ($body as $line) {
            $parts[] = $line === '' ? '' : self::INDENT . $line;
        }

        $parts[] = '}';

        return implode("\n", $parts) . "\n";
    }

    // ─────────────────────────────────────────────────────────
    // State Variable
    // ─────────────────────────────────────────────────────────

    private function emitStateVariable(StateVariableNode $var): string
    {
        $parts = [$var->solidityType];

        if ($var->mutability !== '') {
            $parts[] = $var->mutability;
        }

        if ($var->visibility !== 'private' || $var->mutability !== '') {
            $parts[] = $var->visibility;
        }

        $parts[] = $var->name;

        $line = implode(' ', $parts);

        if ($var->defaultValue !== null) {
            $line .= " = {$var->defaultValue}";
        }

        return $line . ';';
    }

    // ─────────────────────────────────────────────────────────
    // Event
    // ─────────────────────────────────────────────────────────

    private function emitEvent(EventNode $event): string
    {
        if (empty($event->params)) {
            return "event {$event->name}();";
        }

        $params = array_map(function ($param) {
            $parts = [$param->solidityType];
            if ($param->indexed) {
                $parts[] = 'indexed';
            }
            $parts[] = $param->name;
            return implode(' ', $parts);
        }, $event->params);

        return "event {$event->name}(" . implode(', ', $params) . ');';
    }

    // ─────────────────────────────────────────────────────────
    // Modifier
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function emitModifier(ModifierNode $modifier): array
    {
        $paramStr = $this->emitParams($modifier->params);
        $lines    = ["modifier {$modifier->name}({$paramStr}) {"];

        if ($modifier->body !== '') {
            foreach (explode("\n", $modifier->body) as $bodyLine) {
                $lines[] = self::INDENT . $bodyLine;
            }
        }

        // Every Solidity modifier must have _; (placeholder)
        $lines[] = self::INDENT . '_;';
        $lines[] = '}';

        return $lines;
    }

    // ─────────────────────────────────────────────────────────
    // Function
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function emitFunction(FunctionNode $fn): array
    {
        $lines = [];

        // Signature line
        $sig = $this->buildFunctionSignature($fn);
        $lines[] = $sig . ' {';

        // Body
        if ($fn->body !== '') {
            foreach (explode("\n", $fn->body) as $bodyLine) {
                $lines[] = self::INDENT . $bodyLine;
            }
        }

        $lines[] = '}';

        return $lines;
    }

    private function buildFunctionSignature(FunctionNode $fn): string
    {
        $parts = [];

        if ($fn->isConstructor) {
            $parts[] = 'constructor';
        } else {
            $parts[] = "function {$fn->name}";
        }

        // Parameters
        $paramStr = $this->emitParams($fn->params);
        $parts[count($parts) - 1] .= "({$paramStr})";

        // Visibility (not for constructor)
        if (! $fn->isConstructor && $fn->visibility !== '') {
            $parts[] = $fn->visibility;
        }

        // Mutability
        if ($fn->mutability !== '') {
            $parts[] = $fn->mutability;
        }

        // Applied modifiers
        foreach ($fn->modifiers as $mod) {
            $parts[] = $mod;
        }

        // Return type
        if ($fn->returnType !== '') {
            $parts[] = "returns ({$fn->returnType})";
        }

        return implode(' ', $parts);
    }

    // ─────────────────────────────────────────────────────────
    // Parameter helpers
    // ─────────────────────────────────────────────────────────

    /** @param ParameterNode[] $params */
    private function emitParams(array $params): string
    {
        return implode(', ', array_map(
            fn(ParameterNode $p) => "{$p->solidityType} {$p->name}",
            $params
        ));
    }
}
