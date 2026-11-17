<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpSolidity\Parser\AttributeReader;
use PhpSolidity\Validator\ValidationContext;

/**
 * Heuristic security warnings. These never block compilation.
 *
 * W601 tx.origin · W602 state change without any access check · W603 state update after an external call
 * W604 address.transfer()/send() (2300 gas stipend)
 */
final class SecurityRule extends AbstractRule
{
    /** Method names that hand control to another account. */
    private const EXTERNAL_CALLS = ['transfer', 'send', 'call', 'delegatecall', 'transferFrom', 'safeTransfer', 'safeTransferFrom'];

    public function check(array $ast, ValidationContext $context): void
    {
        $this->walk($ast, static function (Node $node) use ($context): void {
            if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'tx'
                && $node->name instanceof Node\Identifier && $node->name->toString() === 'origin') {
                $context->warning(
                    'W601',
                    'tx::origin is used. Never use it for authorization: a malicious contract can relay a call from the real owner.',
                    $node,
                    'Compare msg::sender() instead.',
                );
            }
        });

        foreach ($this->contractClasses($ast) as $class) {
            $addressProps = $this->addressProperties($class);

            foreach ($this->methodsOf($class) as $method) {
                $this->checkAccessControl($method, $context);
                $this->checkReentrancy($method, $context);
                $this->checkTransfer($method, $addressProps, $context);
            }
        }
    }

    /** @return string[] */
    private function addressProperties(Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property && $stmt->type instanceof Node\Name && $stmt->type->getLast() === 'address') {
                foreach ($stmt->props as $prop) {
                    $names[] = $prop->name->toString();
                }
            }
        }

        return $names;
    }

    private function isExternallyCallable(ClassMethod $method): bool
    {
        $name = $method->name->toString();

        if ($name === '__construct' || AttributeReader::has($method, 'Constructor') || AttributeReader::has($method, 'Modifier')) {
            return false;
        }

        if (AttributeReader::has($method, 'Internal') || AttributeReader::has($method, 'SolidityPrivate')) {
            return false;
        }

        return AttributeReader::has($method, 'External')
            || AttributeReader::has($method, 'SolidityPublic')
            || AttributeReader::has($method, 'Receive')
            || AttributeReader::has($method, 'Fallback')
            || $method->isPublic();
    }

    private function checkAccessControl(ClassMethod $method, ValidationContext $context): void
    {
        if (! $this->isExternallyCallable($method) || AttributeReader::has($method, 'Guarded')
            || AttributeReader::has($method, 'View') || AttributeReader::has($method, 'Pure')
            || AttributeReader::has($method, 'Receive') || AttributeReader::has($method, 'Fallback')) {
            return;
        }

        $writes  = false;
        $senders = false;

        $this->walk($method->stmts ?? [], function (Node $node) use (&$writes, &$senders): void {
            if ($this->writesState($node)) {
                $writes = true;
            }
            if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'msg'
                && $node->name instanceof Node\Identifier && $node->name->toString() === 'sender') {
                $senders = true;
            }
        });

        if ($writes && ! $senders) {
            $context->warning(
                'W602',
                "Function '{$method->name->toString()}' changes contract state but has no access check (no #[Guarded] and no use of msg::sender()).",
                $method,
                'If anyone may call it, ignore this warning. Otherwise add #[Guarded(\'onlyOwner\')].',
            );
        }
    }

    private function checkReentrancy(ClassMethod $method, ValidationContext $context): void
    {
        $externalLine = null;

        $this->walk($method->stmts ?? [], function (Node $node) use (&$externalLine, $context): void {
            if ($this->isExternalCall($node)) {
                $externalLine ??= $node->getStartLine();

                return;
            }

            if ($externalLine !== null && $this->writesState($node) && $node->getStartLine() > $externalLine) {
                $context->warning(
                    'W603',
                    "State is changed after an external call (line {$externalLine}). This is the pattern behind reentrancy attacks.",
                    $node,
                    'Update state first, then make the external call (checks-effects-interactions), or add a reentrancy guard modifier.',
                );
            }
        });
    }

    /** @param string[] $addressProps */
    private function checkTransfer(ClassMethod $method, array $addressProps, ValidationContext $context): void
    {
        $addressParams = [];
        foreach ($method->params as $param) {
            if ($param->type instanceof Node\Name && $param->type->getLast() === 'address'
                && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $addressParams[] = $param->var->name;
            }
        }

        $this->walk($method->stmts ?? [], static function (Node $node) use ($addressParams, $addressProps, $context): void {
            if (! $node instanceof Expr\MethodCall || ! $node->name instanceof Node\Identifier
                || ! in_array($node->name->toString(), ['transfer', 'send'], true)) {
                return;
            }

            $target  = $node->var;
            $isAddr  = ($target instanceof Expr\Variable && is_string($target->name) && in_array($target->name, $addressParams, true))
                || ($target instanceof Expr\PropertyFetch && $target->name instanceof Node\Identifier
                    && in_array($target->name->toString(), $addressProps, true))
                || ($target instanceof Expr\StaticCall && $target->class instanceof Node\Name && $target->class->getLast() === 'msg');

            if ($isAddr) {
                $context->warning(
                    'W604',
                    "address.{$node->name->toString()}() forwards only 2300 gas and can fail for contract wallets.",
                    $node,
                    'Prefer EVM::sendValue($to, $amount), which uses call{value: ...} and reverts on failure.',
                );
            }
        });
    }

    private function writesState(Node $node): bool
    {
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp
            || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            $target = $node instanceof Expr\Assign || $node instanceof Expr\AssignOp ? $node->var : $node->var;

            return $this->rootIsStateProperty($target);
        }

        if ($node instanceof \PhpParser\Node\Stmt\Unset_) {
            foreach ($node->vars as $var) {
                if ($this->rootIsStateProperty($var)) {
                    return true;
                }
            }
        }

        return false;
    }

        /** Is this assignment target `$this->x`, `$this->x[...]` or a member reached through one of those? */
    private function rootIsStateProperty(Expr $target): bool
    {
        while (true) {
            if ($target instanceof Expr\ArrayDimFetch) {
                $target = $target->var;
                continue;
            }

            if ($target instanceof Expr\PropertyFetch) {
                if ($target->var instanceof Expr\Variable && $target->var->name === 'this') {
                    return true;
                }

                $target = $target->var;
                continue;
            }

            return false;
        }
    }

    private function isExternalCall(Node $node): bool
    {
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'EVM'
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'sendValue') {
            return true;
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), self::EXTERNAL_CALLS, true)) {
            return ! ($node->var instanceof Expr\Variable && $node->var->name === 'this');
        }

        return false;
    }
}
