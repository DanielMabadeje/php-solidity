<?php

declare(strict_types=1);

namespace PhpSolidity\Emitter;

use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EnumNode;
use PhpSolidity\AST\Nodes\ErrorNode;
use PhpSolidity\AST\Nodes\EventNode;
use PhpSolidity\AST\Nodes\EventParamNode;
use PhpSolidity\AST\Nodes\FunctionNode;
use PhpSolidity\AST\Nodes\ModifierNode;
use PhpSolidity\AST\Nodes\SourceUnitNode;
use PhpSolidity\AST\Nodes\StateVariableNode;
use PhpSolidity\AST\Nodes\StructNode;
use PhpSolidity\TypeSystem\TypeMapper;

/**
 * Stage 2 of the pipeline: Contract IR → Solidity text.
 *
 * This class decides the *shape* of the file (declaration order, signatures, indentation).
 * Statement and expression translation is delegated to BodyEmitter.
 */
class SolidityEmitter
{
    private const INDENT = '    ';

    public function __construct(
        private readonly TypeMapper $types = new TypeMapper(),
    ) {}

    /**
     * Emit a complete Solidity source file.
     *
     * Passing a single ContractNode is still supported for backwards compatibility.
     */
    public function emit(ContractNode|SourceUnitNode $node): string
    {
        $unit = $node instanceof SourceUnitNode
            ? $node
            : new SourceUnitNode($node->license, $node->solidityVersion, [], [$node]);

        $context = SourceContext::fromUnit($unit);

        $out   = [];
        $out[] = "// SPDX-License-Identifier: {$unit->license}";
        $out[] = "pragma solidity {$unit->solidityVersion};";
        $out[] = '';

        foreach ($unit->imports as $import) {
            $out[] = 'import "' . addcslashes($import, '"\\') . '";';
        }
        if ($unit->imports !== []) {
            $out[] = '';
        }

        $blocks = [];
        foreach ($unit->declarations as $declaration) {
            $blocks[] = match (true) {
                $declaration instanceof EnumNode   => $this->emitEnum($declaration),
                $declaration instanceof StructNode => $this->emitStruct($declaration),
                default                            => $this->emitContract($declaration, $context),
            };
        }

        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $out[] = '';
            }
            array_push($out, ...$block);
        }

        return implode("\n", $out) . "\n";
    }

    // ─────────────────────────────────────────────────────────
    // Enums and structs
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function emitEnum(EnumNode $enum): array
    {
        $lines = ["enum {$enum->name} {"];
        foreach ($enum->cases as $i => $case) {
            $lines[] = self::INDENT . $case . ($i < count($enum->cases) - 1 ? ',' : '');
        }
        $lines[] = '}';

        return $lines;
    }

    /** @return string[] */
    private function emitStruct(StructNode $struct): array
    {
        $lines = ["struct {$struct->name} {"];
        foreach ($struct->members as $member) {
            $lines[] = self::INDENT . "{$member->solidityType} {$member->name};";
        }
        $lines[] = '}';

        return $lines;
    }

    // ─────────────────────────────────────────────────────────
    // Contracts
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function emitContract(ContractNode $contract, SourceContext $context): array
    {
        $header = "{$contract->kind} {$contract->name}";
        if ($contract->inherits !== []) {
            $header .= ' is ' . implode(', ', $contract->inherits);
        }

        $body = new BodyEmitter(
            $this->types,
            $context,
            $context->stateVariablesFor($contract),
            $context->functionReturnsFor($contract),
        );

        $members = [];

        // Custom errors, state variables and events, each group separated by a blank line
        $errors = [];
        foreach ($contract->errors as $error) {
            $errors[] = $this->emitError($error);
        }
        $variables = [];
        if ($contract->kind !== 'interface') {
            foreach ($contract->stateVariables as $variable) {
                $variables[] = $this->emitStateVariable($variable, $body);
            }
        }
        $events = [];
        foreach ($contract->events as $event) {
            $events[] = $this->emitEvent($event);
        }
        foreach ([$errors, $variables, $events] as $group) {
            if ($group !== []) {
                array_push($members, ...$group);
                $members[] = '';
            }
        }

        foreach ($contract->modifiers as $modifier) {
            array_push($members, ...$this->emitModifier($modifier, $body));
            $members[] = '';
        }

        // Constructor, then receive/fallback, then everything else
        $ordered = [];
        foreach (['constructor', 'receive', 'fallback', 'function'] as $kind) {
            foreach ($contract->functions as $fn) {
                if ($fn->kind === $kind) {
                    $ordered[] = $fn;
                }
            }
        }

        foreach ($ordered as $fn) {
            array_push($members, ...$this->emitFunction($fn, $body, $contract->kind === 'interface'));
            $members[] = '';
        }

        while ($members !== [] && end($members) === '') {
            array_pop($members);
        }

        $lines = [$header . ' {'];
        foreach ($members as $line) {
            $lines[] = $line === '' ? '' : self::INDENT . $line;
        }
        $lines[] = '}';

        return $lines;
    }

    // ─────────────────────────────────────────────────────────
    // Members
    // ─────────────────────────────────────────────────────────

    private function emitStateVariable(StateVariableNode $var, BodyEmitter $body): string
    {
        $parts = [$var->solidityType, $var->visibility];

        if ($var->mutability !== '') {
            $parts[] = $var->mutability;
        }

        $parts[] = $var->name;
        $line    = implode(' ', $parts);

        if ($var->defaultValue !== null) {
            $body->beginScope();
            $line .= ' = ' . $body->expression($var->defaultValue);
        }

        return $line . ';';
    }

    private function emitEvent(EventNode $event): string
    {
        return "event {$event->name}(" . $this->signatureParams($event->params) . ');';
    }

    private function emitError(ErrorNode $error): string
    {
        return "error {$error->name}(" . $this->signatureParams($error->params) . ');';
    }

    /** @param EventParamNode[] $params */
    private function signatureParams(array $params): string
    {
        return implode(', ', array_map(static function (EventParamNode $param): string {
            $parts = [$param->solidityType];
            if ($param->indexed) {
                $parts[] = 'indexed';
            }
            $parts[] = $param->name;

            return implode(' ', $parts);
        }, $params));
    }

    /** @return string[] */
    private function emitModifier(ModifierNode $modifier, BodyEmitter $body): array
    {
        $body->beginScope();
        $params = $body->parameterList($modifier->params);
        $inner  = $body->statements($modifier->body);

        $lines = ["modifier {$modifier->name}({$params}) {"];
        foreach ($inner as $line) {
            $lines[] = self::INDENT . $line;
        }

        // A modifier runs its body and then the function. Unless the author placed
        // EVM::placeholder() explicitly, the function runs after the checks.
        if (! in_array('_;', array_map('trim', $inner), true)) {
            $lines[] = self::INDENT . '_;';
        }

        $lines[] = '}';

        return $lines;
    }

    /** @return string[] */
    private function emitFunction(FunctionNode $fn, BodyEmitter $body, bool $inInterface): array
    {
        $body->beginScope();
        $params = $body->parameterList($fn->params);

        $head = match ($fn->kind) {
            'constructor' => "constructor({$params})",
            'receive'     => 'receive()',
            'fallback'    => "fallback({$params})",
            default       => "function {$fn->name}({$params})",
        };

        $parts = [$head];

        if ($fn->kind !== 'constructor' && $fn->visibility !== '') {
            $parts[] = $fn->visibility;
        }
        if ($fn->mutability !== '') {
            $parts[] = $fn->mutability;
        }
        if ($fn->isVirtual) {
            $parts[] = 'virtual';
        }
        if ($fn->isOverride) {
            $parts[] = 'override';
        }
        foreach ($fn->modifiers as $modifier) {
            $parts[] = $modifier;
        }
        if ($fn->returnType !== '') {
            $parts[] = 'returns (' . $body->withLocation($fn->returnType) . ')';
        }

        $signature = implode(' ', $parts);

        if ($inInterface || $fn->body === null) {
            return [$signature . ';'];
        }

        $lines = [$signature . ' {'];
        foreach ($body->statements($fn->body) as $line) {
            $lines[] = self::INDENT . $line;
        }
        $lines[] = '}';

        return $lines;
    }
}
