<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpSolidity\TypeSystem\TypeMapper;
use PhpSolidity\Validator\ValidationContext;

/**
 * E101 blocked type · E102 nullable type · E103 union type · E104 mapping without types
 * E105 missing type · E106 promoted property · E107 default parameter value
 */
final class TypeRule extends AbstractRule
{
    public function check(array $ast, ValidationContext $context): void
    {
        foreach ($this->classLikes($ast) as $class) {
            $isStruct = $this->isStruct($class);

            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Property) {
                    $this->checkHolder($stmt, $stmt->type, "property \${$this->propertyName($stmt)}", $context, true);
                }

                if ($stmt instanceof ClassMethod) {
                    $this->checkMethod($stmt, $class instanceof Class_ && $isStruct, $context);
                }
            }
        }
    }

    private function propertyName(Property $property): string
    {
        return $property->props[0]->name->toString();
    }

    private function checkMethod(ClassMethod $method, bool $inStruct, ValidationContext $context): void
    {
        foreach ($method->params as $param) {
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '?';

            if ($param->flags !== 0 && ! $inStruct) {
                $context->error(
                    'E106',
                    "Constructor property promotion (\${$name}) is not supported in contracts.",
                    $param,
                    'Declare the property separately and assign it in the constructor.',
                );
            }

            if ($param->default !== null && ! $inStruct) {
                $context->error(
                    'E107',
                    "Parameter \${$name} has a default value. Solidity has no default parameters.",
                    $param,
                    'Remove the default or write an overload with a different name.',
                );
            }

            $this->checkHolder($param, $param->type, "parameter \${$name}", $context, false);
        }

        if ($method->returnType !== null) {
            $this->checkHolder($method, $method->returnType, "return type of {$method->name->toString()}()", $context, false, true);
        }
    }

    private function checkHolder(
        Node $holder,
        ?Node $type,
        string $what,
        ValidationContext $context,
        bool $isProperty,
        bool $isReturn = false,
    ): void {
        $overridden = $this->hasTypeOverride($holder) || ($isProperty && $this->hasDocblockType($holder));

        if ($type === null) {
            if (! $overridden && ! $isReturn) {
                $context->error('E105', "The {$what} has no type. Every value in a contract needs an explicit type.", $holder);
            }

            return;
        }

        if ($type instanceof Node\NullableType) {
            $context->error('E102', "The {$what} is nullable. Solidity has no null.", $holder, 'Use the plain type and its zero value.');

            return;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $context->error('E103', "The {$what} uses a union or intersection type, which Solidity cannot express.", $holder);

            return;
        }

        $name = $type instanceof Node\Name ? $type->getLast() : ($type instanceof Node\Identifier ? $type->toString() : '');
        $key  = strtolower($name);

        if ($name === 'mapping' && ! $overridden) {
            $context->error(
                'E104',
                "The {$what} is a mapping without key and value types.",
                $holder,
                "Add #[Mapping('address', 'uint256')] above it.",
            );

            return;
        }

        if (isset(TypeMapper::BLOCKED[$key]) && ! $overridden && $key !== 'void') {
            $context->error(
                'E101',
                "Blocked type '{$name}' in {$what}: " . TypeMapper::BLOCKED[$key],
                $holder,
            );
        }
    }

    private function hasDocblockType(Node $node): bool
    {
        $doc = $node->getDocComment();

        return $doc !== null && str_contains($doc->getText(), '@sol-type');
    }
}
