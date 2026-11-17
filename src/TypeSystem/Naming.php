<?php

declare(strict_types=1);

namespace PhpSolidity\TypeSystem;

/**
 * Keeps PHP identifiers from colliding with Solidity keywords or state variables.
 *
 * In PHP `$owner` and `$this->owner` are different things. In Solidity both would
 * be plain `owner`, so a parameter silently shadows the state variable. We rename
 * the parameter to `owner_` (the same convention OpenZeppelin uses).
 */
final class Naming
{
    /** Reserved or future-reserved Solidity words that are legal PHP variable names. */
    private const RESERVED = [
        'after', 'alias', 'apply', 'auto', 'byte', 'case', 'copyof', 'default', 'define',
        'final', 'implements', 'in', 'inline', 'let', 'macro', 'match', 'mutable', 'null',
        'of', 'partial', 'promise', 'reference', 'relocatable', 'sealed', 'sizeof',
        'static', 'supports', 'switch', 'typedef', 'typeof', 'var', 'abstract', 'address',
        'anonymous', 'as', 'assembly', 'bool', 'break', 'bytes', 'calldata', 'catch',
        'constant', 'constructor', 'continue', 'contract', 'delete', 'do', 'else', 'emit',
        'enum', 'error', 'event', 'external', 'fallback', 'false', 'for', 'function', 'if',
        'immutable', 'import', 'indexed', 'interface', 'internal', 'is', 'library', 'mapping',
        'memory', 'modifier', 'new', 'override', 'payable', 'pragma', 'private', 'public',
        'pure', 'receive', 'return', 'returns', 'storage', 'string', 'struct', 'throw',
        'true', 'try', 'type', 'unchecked', 'using', 'view', 'virtual', 'while',
        'msg', 'block', 'tx', 'abi', 'this', 'super', 'now', 'selfdestruct',
    ];

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED, true);
    }

    /**
     * @param string[] $taken names that must not be reused (state variables, constants...)
     */
    public static function safe(string $name, array $taken = []): string
    {
        if (self::isReserved($name) || in_array($name, $taken, true)) {
            return $name . '_';
        }

        return $name;
    }
}
