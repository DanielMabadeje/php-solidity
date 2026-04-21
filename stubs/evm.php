<?php

/**
 * EVM Type Stubs for PHP-Solidity
 *
 * These are PHP representations of Solidity/EVM types.
 * They exist purely for IDE autocompletion and type checking.
 * The transpiler maps these to their Solidity equivalents.
 *
 * DO NOT use these in regular PHP — only inside #[Contract] classes.
 */

declare(strict_types=1);

namespace PhpSolidity\Stubs;

// ─────────────────────────────────────────────────────────────
// Integer Types
// ─────────────────────────────────────────────────────────────

/** Solidity: uint256 — unsigned 256-bit integer */
class uint256 {
    public function __construct(public readonly string $value = '0') {}
    public function __toString(): string { return $this->value; }
}

/** Solidity: uint128 */
class uint128 extends uint256 {}

/** Solidity: uint64 */
class uint64 extends uint256 {}

/** Solidity: uint32 */
class uint32 extends uint256 {}

/** Solidity: uint16 */
class uint16 extends uint256 {}

/** Solidity: uint8 */
class uint8 extends uint256 {}

/** Solidity: int256 — signed 256-bit integer */
class int256 {
    public function __construct(public readonly string $value = '0') {}
    public function __toString(): string { return $this->value; }
}

/** Solidity: int128 */
class int128 extends int256 {}

/** Solidity: int64 */
class int64 extends int256 {}

/** Solidity: int32 */
class int32 extends int256 {}

// ─────────────────────────────────────────────────────────────
// Address Type
// ─────────────────────────────────────────────────────────────

/**
 * Solidity: address
 * Represents a 20-byte Ethereum account address.
 */
class address {
    public function __construct(public readonly string $value = '0x0000000000000000000000000000000000000000') {}

    public function __toString(): string
    {
        return $this->value;
    }

    /** Solidity: address.balance */
    public function balance(): uint256
    {
        return new uint256('0');
    }

    /** Solidity: address.transfer(uint256 amount) */
    public function transfer(uint256 $amount): void {}

    /** Solidity: address.send(uint256 amount) */
    public function send(uint256 $amount): bool { return true; }

    /** Solidity: address(0) — the zero address */
    public static function zero(): self
    {
        return new self('0x0000000000000000000000000000000000000000');
    }
}

// ─────────────────────────────────────────────────────────────
// Bytes Types
// ─────────────────────────────────────────────────────────────

/** Solidity: bytes32 */
class bytes32 {
    public function __construct(public readonly string $value = '0x') {}
    public function __toString(): string { return $this->value; }
}

/** Solidity: bytes — dynamic byte array */
class bytes {
    public function __construct(public readonly string $value = '0x') {}
    public function __toString(): string { return $this->value; }
}

// ─────────────────────────────────────────────────────────────
// Mapping Type
// ─────────────────────────────────────────────────────────────

/**
 * Solidity: mapping(KeyType => ValueType)
 *
 * @template TKey
 * @template TValue
 */
class mapping {
    private array $data = [];

    public function offsetGet(mixed $key): mixed
    {
        return $this->data[(string) $key] ?? null;
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->data[(string) $key] = $value;
    }
}

// ─────────────────────────────────────────────────────────────
// Global EVM Context
// ─────────────────────────────────────────────────────────────

/**
 * Solidity global: msg
 * Represents the current transaction message context.
 */
class msg {
    /** msg.sender — address of the caller */
    public static function sender(): address
    {
        return new address();
    }

    /** msg.value — amount of wei sent with the transaction */
    public static function value(): uint256
    {
        return new uint256('0');
    }

    /** msg.data — raw calldata */
    public static function data(): bytes
    {
        return new bytes();
    }
}

/**
 * Solidity global: block
 * Represents current block information.
 */
class block {
    /** block.timestamp */
    public static function timestamp(): uint256
    {
        return new uint256((string) time());
    }

    /** block.number */
    public static function number(): uint256
    {
        return new uint256('0');
    }

    /** block.chainid */
    public static function chainid(): uint256
    {
        return new uint256('1');
    }
}

/**
 * Solidity global: tx
 * Represents the originating transaction.
 */
class tx {
    /** tx.origin — original external sender */
    public static function origin(): address
    {
        return new address();
    }

    /** tx.gasprice */
    public static function gasprice(): uint256
    {
        return new uint256('0');
    }
}

// ─────────────────────────────────────────────────────────────
// EVM Builtins
// ─────────────────────────────────────────────────────────────

/**
 * Solidity: Event.emit() helper
 * Maps to Solidity emit keyword.
 */
class Event {
    public static function emit(string $eventName, mixed ...$args): void {}
}

/**
 * EVM built-in functions: require(), revert(), assert()
 *
 * PHP does not allow shadowing the `require` language construct with a
 * function, so these are exposed as static methods on the EVM class.
 *
 * The transpiler maps:
 *   EVM::require(cond, msg)  →  require(cond, msg)
 *   EVM::revert(msg)         →  revert(msg)
 *   EVM::assert(cond)        →  assert(cond)
 *
 * Usage inside contracts:
 *   EVM::require($this->balances[$owner] >= $amount, "Insufficient balance");
 *   EVM::revert("Something went wrong");
 */
class EVM
{
    /**
     * Solidity: require(condition, message)
     * Reverts the transaction if condition is false.
     */
    public static function require(mixed $condition, string $message = ''): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Solidity: revert(message)
     * Unconditionally reverts the transaction.
     */
    public static function revert(string $message = ''): never
    {
        throw new \RuntimeException($message);
    }

    /**
     * Solidity: assert(condition)
     * Used for internal invariant checks (uses all gas on failure).
     */
    public static function assert(mixed $condition): void
    {
        if (! $condition) {
            throw new \RuntimeException('Assertion failed: invariant violation');
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Type Aliases (convenience)
// ─────────────────────────────────────────────────────────────

// These let users write just `uint256` without a namespace
// when the stubs are imported via the autoloader.
class_alias(uint256::class, 'uint256');
class_alias(uint128::class, 'uint128');
class_alias(uint64::class,  'uint64');
class_alias(uint32::class,  'uint32');
class_alias(uint16::class,  'uint16');
class_alias(uint8::class,   'uint8');
class_alias(int256::class,  'int256');
class_alias(int128::class,  'int128');
class_alias(int64::class,   'int64');
class_alias(int32::class,   'int32');
class_alias(address::class, 'address');
class_alias(bytes32::class, 'bytes32');
class_alias(bytes::class,   'bytes');
class_alias(mapping::class, 'mapping');
class_alias(msg::class,     'msg');
class_alias(block::class,   'block');
class_alias(tx::class,      'tx');
class_alias(Event::class,   'Event');
class_alias(EVM::class,     'EVM');
