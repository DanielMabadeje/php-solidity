<?php

declare(strict_types=1);

namespace PhpSolidity\Parser;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;

/**
 * Small helper for reading PHP 8 attributes from php-parser nodes.
 *
 * Attribute arguments are evaluated statically: only literals (strings, ints,
 * booleans, arrays of those and `Foo::class`) are understood.
 */
final class AttributeReader
{
    /** Positional parameter names for every attribute that takes arguments. */
    private const SIGNATURES = [
        'Contract'      => ['name', 'license', 'version'],
        'Library'       => ['name', 'license', 'version'],
        'Storage'       => ['public', 'name'],
        'SolidityEvent' => ['name', 'params'],
        'SolidityError' => ['name', 'params'],
        'SolType'       => ['type'],
        'Mapping'       => ['key', 'value'],
        'Import'        => ['path'],
    ];

    public static function find(Node $node, string $name): ?Attribute
    {
        foreach (self::all($node, $name) as $attribute) {
            return $attribute;
        }

        return null;
    }

    public static function has(Node $node, string $name): bool
    {
        return self::find($node, $name) !== null;
    }

    /** @return Attribute[] */
    public static function all(Node $node, string $name): array
    {
        $found = [];

        foreach ($node->attrGroups ?? [] as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === $name) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    /**
     * Arguments of an attribute keyed by parameter name (positional and named
     * arguments are merged). Variadic attributes (Guarded, Inherits) use ->values().
     *
     * @return array<string, mixed>
     */
    public static function arguments(Attribute $attribute): array
    {
        $names  = self::SIGNATURES[$attribute->name->getLast()] ?? [];
        $result = [];

        foreach ($attribute->args as $position => $arg) {
            $key = $arg->name !== null ? $arg->name->toString() : ($names[$position] ?? 'arg' . $position);
            $result[$key] = self::literal($arg->value);
        }

        return $result;
    }

    /**
     * All argument values in order (for variadic attributes like #[Guarded('a', 'b')]).
     *
     * @return list<mixed>
     */
    public static function values(Attribute $attribute): array
    {
        return array_map(static fn ($arg) => self::literal($arg->value), $attribute->args);
    }

    public static function argument(Node $node, string $attributeName, string $argument): mixed
    {
        $attribute = self::find($node, $attributeName);

        return $attribute === null ? null : (self::arguments($attribute)[$argument] ?? null);
    }

    /** Statically evaluate a literal expression. */
    public static function literal(Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_          => $expr->value,
            $expr instanceof Node\Scalar\Int_             => $expr->value,
            $expr instanceof Node\Scalar\Float_           => $expr->value,
            $expr instanceof Expr\ConstFetch              => match (strtolower($expr->name->toString())) {
                'true'  => true,
                'false' => false,
                default => null,
            },
            $expr instanceof Expr\ClassConstFetch
                && $expr->name instanceof Node\Identifier
                && strtolower($expr->name->toString()) === 'class'
                && $expr->class instanceof Node\Name      => $expr->class->getLast(),
            $expr instanceof Expr\Array_                  => self::arrayLiteral($expr),
            default                                       => null,
        };
    }

    /** @return array<int|string, mixed> */
    private static function arrayLiteral(Expr\Array_ $array): array
    {
        $result = [];

        foreach ($array->items as $item) {
            $value = self::literal($item->value);
            if ($item->key !== null) {
                $result[self::literal($item->key)] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }
}
