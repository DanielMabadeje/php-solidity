<?php

declare(strict_types=1);

namespace PhpSolidity\TypeSystem;

use PhpSolidity\Exceptions\UnsupportedTypeException;

class TypeMapper
{
    /**
     * PHP type name → Solidity type string
     */
    private const MAP = [
        // Integer types
        'uint256'    => 'uint256',
        'uint128'    => 'uint128',
        'uint64'     => 'uint64',
        'uint32'     => 'uint32',
        'uint16'     => 'uint16',
        'uint8'      => 'uint8',
        'int256'     => 'int256',
        'int128'     => 'int128',
        'int64'      => 'int64',
        'int32'      => 'int32',
        'int16'      => 'int16',
        'int8'       => 'int8',

        // Native PHP int (maps to int256 by default)
        'int'        => 'int256',

        // Address
        'address'    => 'address',

        // Boolean
        'bool'       => 'bool',

        // Bytes
        'bytes32'    => 'bytes32',
        'bytes16'    => 'bytes16',
        'bytes8'     => 'bytes8',
        'bytes4'     => 'bytes4',
        'bytes'      => 'bytes',

        // String
        'string'     => 'string',

        // Void / no return
        'void'       => '',
        'never'      => '',

        // Mapping (generic — full parsing handled separately)
        'mapping'    => 'mapping',
    ];

    /**
     * Types that are BLOCKED in smart contracts.
     * Using these throws a compile-time error.
     */
    private const BLOCKED = [
        'float'    => 'Solidity has no floating-point type. Use fixed-point arithmetic with uint256.',
        'double'   => 'Solidity has no floating-point type.',
        'array'    => 'Use mapping or a dynamic array type (uint256[]) instead of PHP arrays.',
        'object'   => 'Objects are not supported. Use mappings or struct patterns.',
        'mixed'    => 'All types must be explicitly typed in smart contracts.',
        'null'     => 'Null values are not supported in Solidity.',
        'resource' => 'PHP resources are not supported.',
        'callable' => 'Callables are not supported in smart contracts.',
        'closure'  => 'Closures are not supported in smart contracts.',
        'iterable' => 'PHP iterables are not supported. Use arrays or mappings explicitly.',
    ];

    /**
     * Resolve a PHP type string to its Solidity equivalent.
     *
     * @throws UnsupportedTypeException
     */
    public function toSolidity(string $phpType): string
    {
        $normalized = strtolower(ltrim($phpType, '\\'));

        if (isset(self::BLOCKED[$normalized])) {
            throw new UnsupportedTypeException(
                "Type '{$phpType}' is not supported in smart contracts: " . self::BLOCKED[$normalized]
            );
        }

        if (isset(self::MAP[$phpType])) {
            return self::MAP[$phpType];
        }

        if (isset(self::MAP[$normalized])) {
            return self::MAP[$normalized];
        }

        // Dynamic array types: uint256[], address[], etc.
        if (str_ends_with($phpType, '[]')) {
            $baseType = substr($phpType, 0, -2);
            return $this->toSolidity($baseType) . '[]';
        }

        // Fixed-size array types: uint256[3], address[10], etc.
        if (preg_match('/^(.+)\[(\d+)\]$/', $phpType, $matches)) {
            $solidityBase = $this->toSolidity($matches[1]);
            return "{$solidityBase}[{$matches[2]}]";
        }

        // Treat unknown types as potential struct names (PascalCase)
        if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $phpType)) {
            return $phpType; // Passed through as a struct/interface name
        }

        throw new UnsupportedTypeException(
            "Cannot map PHP type '{$phpType}' to a Solidity type. " .
            "Use EVM types (uint256, address, bool, bytes32, string, mapping) or structs."
        );
    }

    /**
     * Returns true if the given PHP type is a known EVM integer type.
     */
    public function isInteger(string $phpType): bool
    {
        return in_array($phpType, [
            'uint256','uint128','uint64','uint32','uint16','uint8',
            'int256','int128','int64','int32','int16','int8','int',
        ], true);
    }

    /**
     * Returns true if the given PHP type is a mapping.
     */
    public function isMapping(string $phpType): bool
    {
        return str_starts_with($phpType, 'mapping');
    }

    /**
     * Returns true if this is a void return type.
     */
    public function isVoid(string $phpType): bool
    {
        return in_array($phpType, ['void', 'never', ''], true);
    }

    /**
     * Returns all supported type names.
     *
     * @return string[]
     */
    public function supportedTypes(): array
    {
        return array_keys(self::MAP);
    }
}
