<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpSolidity\Parser\AttributeReader;
use PhpSolidity\Validator\ValidationContext;

/** E501 constant without value · E502 immutable assigned outside the constructor · E503 constant reassigned · E504 constant and immutable */
final class StateRule extends AbstractRule
{
    public function check(array $ast, ValidationContext $context): void
    {
        foreach ($this->contractClasses($ast) as $class) {
            $immutable = [];
            $constant  = [];

            foreach ($class->stmts as $stmt) {
                if (! $stmt instanceof Property) {
                    continue;
                }

                $isConstant  = AttributeReader::has($stmt, 'Constant');
                $isImmutable = AttributeReader::has($stmt, 'Immutable');

                if ($isConstant && $isImmutable) {
                    $context->error('E504', 'A state variable cannot be both #[Constant] and #[Immutable].', $stmt);
                }

                foreach ($stmt->props as $prop) {
                    if ($isConstant) {
                        $constant[] = $prop->name->toString();
                        if ($prop->default === null) {
                            $context->error(
                                'E501',
                                "Constant \${$prop->name->toString()} has no value.",
                                $stmt,
                                "Give it a value: #[Constant] private uint8 \${$prop->name->toString()} = 18;",
                            );
                        }
                    }
                    if ($isImmutable) {
                        $immutable[] = $prop->name->toString();
                    }
                }
            }

            $this->checkAssignments($class, $immutable, $constant, $context);
        }
    }

    /**
     * @param string[] $immutable
     * @param string[] $constant
     */
    private function checkAssignments(Class_ $class, array $immutable, array $constant, ValidationContext $context): void
    {
        foreach ($this->methodsOf($class) as $method) {
            $inConstructor = $method->name->toString() === '__construct' || AttributeReader::has($method, 'Constructor');

            $this->walk($method->stmts ?? [], function (Node $node) use ($immutable, $constant, $inConstructor, $context): void {
                if (! $node instanceof Expr\Assign && ! $node instanceof Expr\AssignOp) {
                    return;
                }

                $target = $node->var;
                while ($target instanceof Expr\ArrayDimFetch) {
                    $target = $target->var;
                }

                if (! $target instanceof Expr\PropertyFetch || ! $target->var instanceof Expr\Variable
                    || $target->var->name !== 'this' || ! $target->name instanceof Node\Identifier) {
                    return;
                }

                $name = $target->name->toString();

                if (in_array($name, $constant, true)) {
                    $context->error('E503', "Constant \${$name} cannot be assigned.", $node);
                } elseif (in_array($name, $immutable, true) && ! $inConstructor) {
                    $context->error('E502', "Immutable \${$name} can only be assigned in the constructor.", $node);
                }
            });
        }
    }
}
