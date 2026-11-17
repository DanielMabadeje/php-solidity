<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpSolidity\Parser\AttributeReader;
use PhpSolidity\Validator\ValidationContext;

/** W401 function without visibility attribute · E108 returns a value but declares no return type · E109 payable misuse */
final class FunctionRule extends AbstractRule
{
    private const VISIBILITY = ['External', 'Internal', 'SolidityPrivate', 'SolidityPublic', 'Modifier', 'Constructor', 'Receive', 'Fallback'];

    public function check(array $ast, ValidationContext $context): void
    {
        foreach ($this->contractClasses($ast) as $class) {
            foreach ($this->methodsOf($class) as $method) {
                if (! AttributeReader::has($class, 'Library')) {
                    $this->checkVisibility($method, $context);
                }
                $this->checkReturn($method, $context);
                $this->checkPayable($method, $context);
            }
        }
    }

    private function checkVisibility(ClassMethod $method, ValidationContext $context): void
    {
        $name = $method->name->toString();

        if ($name === '__construct' || ! $method->isPublic()) {
            return;
        }

        foreach (self::VISIBILITY as $attribute) {
            if (AttributeReader::has($method, $attribute)) {
                return;
            }
        }

        $context->warning(
            'W401',
            "Function '{$name}' has no visibility attribute (#[External], #[Internal], ...). It will be emitted as 'public'.",
            $method,
            'Add #[External] for functions only called from outside the contract.',
        );
    }

    private function checkReturn(ClassMethod $method, ValidationContext $context): void
    {
        if ($method->returnType !== null || AttributeReader::has($method, 'SolType')) {
            return;
        }

        $this->walk($method->stmts ?? [], static function (Node $node) use ($method, $context): void {
            if ($node instanceof Stmt\Return_ && $node->expr !== null) {
                $context->error(
                    'E108',
                    "Function '{$method->name->toString()}' returns a value but has no return type.",
                    $node,
                    'Add a return type, e.g. ): uint256.',
                );
            }
        });
    }

    /** msg::value() in a non-payable function that anyone can call does not compile in Solidity. */
    private function checkPayable(ClassMethod $method, ValidationContext $context): void
    {
        $payable = AttributeReader::has($method, 'Payable') || AttributeReader::has($method, 'Receive');
        $hidden  = AttributeReader::has($method, 'Internal') || AttributeReader::has($method, 'SolidityPrivate')
            || AttributeReader::has($method, 'Modifier') || $method->isPrivate() || $method->isProtected();

        if ($payable || $hidden) {
            return;
        }

        $this->walk($method->stmts ?? [], static function (Node $node) use ($method, $context): void {
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
                && $node->class->getLast() === 'msg' && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'value') {
                $context->error(
                    'E109',
                    "Function '{$method->name->toString()}' reads msg::value() but is not #[Payable].",
                    $node,
                    'Add #[Payable] to the function, or make it #[Internal].',
                );
            }
        });
    }
}
