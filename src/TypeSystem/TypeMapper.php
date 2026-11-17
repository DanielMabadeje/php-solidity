<?php

declare(strict_types=1);

namespace PhpSolidity\TypeSystem;

use PhpSolidity\Exceptions\UnsupportedTypeException;

/**
 * Maps PHP type names to Solidity types and answers questions about them.
 */
class TypeMapper
{
    /** Types that are blocked in smart contracts, with the reason shown to the developer. */
    public const BLOCKED = [
        'float'    => 'Solidity has no floating-point type. Use fixed-point arithmetic with uint256 (multiply by 10 ** 18).',
        'double'   => 'Solidity has no floating-point type.',
        'array'    => "Use a mapping or a typed array: add #[SolType('uint256[]')] or #[Mapping('address', 'uint256')].",
        'object'   => 'Objects are not supported. Use a #[Struct] class instead.',
        'mixed'    => 'All types must be explicit in smart contracts.',
        'null'     => 'Null values are not supported in Solidity.',
        'resource' => 'PHP resources are not supported.',
        'callable' => 'Callables are not supported in smart contracts.',
        'closure'  => 'Closures are not supported in smart contracts.',
        'iterable' => 'PHP iterables are not supported. Use a typed array or a mapping.',
        'self'     => 'Use the contract name instead of self.',
        'static'   => 'Use the contract name instead of static.',
    ];

    /** Fixed names that need no pattern matching. */
    private const SIMPLE = [
        'address' => 'address',
        'bool'    => 'bool',
        'bytes'   => 'bytes',
        'string'  => 'string',
        'int'     => 'int256',
        'void'    => '',
        'never'   => '',
        'mapping' => 'mapping',
    ];

    /**
     * Resolve a PHP type string to its Solidity equivalent.
     *
     * @throws UnsupportedTypeException
     */
    public function toSolidity(string $phpType): string
    {
        $type       = ltrim($phpType, '\\');
        $normalized = strtolower($type);

        if (isset(self::BLOCKED[$normalized])) {
            throw new UnsupportedTypeException(
                "Type '{$phpType}' is not supported in smart contracts: " . self::BLOCKED[$normalized]
            );
        }

        if (isset(self::SIMPLE[$normalized])) {
            return self::SIMPLE[$normalized];
        }

        if ($this->isElementary($type)) {
            return $type;
        }

        // Arrays written as a string by the developer: uint256[], address[3] ...
        if (preg_match('/^(.+)\[(\d*)\]$/', $type, $m)) {
            return $this->toSolidity($m[1]) . '[' . $m[2] . ']';
        }

        // User-defined types: struct, enum, contract and interface names.
        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $type)) {
            return $type;
        }

        throw new UnsupportedTypeException(
            "Cannot map PHP type '{$phpType}' to a Solidity type. " .
            'Use EVM types (uint256, address, bool, bytes32, string) or a struct, enum or interface name.'
        );
    }

    /** uint8..uint256, int8..int256 (steps of 8) and bytes1..bytes32. */
    public function isElementary(string $type): bool
    {
        if (preg_match('/^u?int(\d+)$/', $type, $m)) {
            $bits = (int) $m[1];
            return $bits >= 8 && $bits <= 256 && $bits % 8 === 0;
        }

        if (preg_match('/^bytes(\d+)$/', $type, $m)) {
            $size = (int) $m[1];
            return $size >= 1 && $size <= 32;
        }

        return false;
    }

    public function isInteger(string $type): bool
    {
        return $type === 'int' || preg_match('/^u?int\d+$/', $type) === 1 && $this->isElementary($type);
    }

    public function isMapping(string $type): bool
    {
        return str_starts_with($type, 'mapping');
    }

    public function isVoid(string $type): bool
    {
        return in_array($type, ['void', 'never', ''], true);
    }

    /**
     * Does this Solidity type need a data location (`memory`) when used as a
     * parameter, return value or local variable?
     *
     * @param string[] $structNames structs declared in the same source file
     */
    public function needsDataLocation(string $solidityType, array $structNames = []): bool
    {
        if ($solidityType === 'string' || $solidityType === 'bytes') {
            return true;
        }

        if (str_ends_with($solidityType, ']')) {
            return true;
        }

        return in_array($solidityType, $structNames, true);
    }

    /** `mapping(address => uint256)` → `uint256`; `null` when the type is not a mapping. */
    public function mappingValueType(string $type): ?string
    {
        $parts = $this->splitMapping($type);

        return $parts['value'] ?? null;
    }

    /** @return array{key: string, value: string}|null */
    public function splitMapping(string $type): ?array
    {
        $type = trim($type);
        if (! preg_match('/^mapping\s*\(/', $type)) {
            return null;
        }

        $start = strpos($type, '(') + 1;
        $depth = 1;
        $arrow = null;
        $len   = strlen($type);

        for ($i = $start; $i < $len; $i++) {
            $char = $type[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $arrow === null && substr($type, $i, 2) === '=>') {
                $arrow = $i;
            }
        }

        if ($arrow === null || $depth !== 0) {
            return null;
        }

        return [
            'key'   => trim(substr($type, $start, $arrow - $start)),
            'value' => trim(substr($type, $arrow + 2, $i - $arrow - 2)),
        ];
    }

    /** Element type of `T[]` or `T[n]`; `null` for anything else. */
    public function arrayElementType(string $type): ?string
    {
        if (preg_match('/^(.+)\[\d*\]$/', $type, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return string[] */
    public function supportedTypes(): array
    {
        $types = array_keys(self::SIMPLE);

        for ($bits = 8; $bits <= 256; $bits += 8) {
            $types[] = "uint{$bits}";
            $types[] = "int{$bits}";
        }

        for ($size = 1; $size <= 32; $size++) {
            $types[] = "bytes{$size}";
        }

        return $types;
    }
}
