<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/**
 * Declares a custom error (Solidity >= 0.8.4).
 *
 *     #[SolidityError('InsufficientBalance', ['uint256 available', 'uint256 required'])]
 *
 * Revert with it using `EVM::revertWith('InsufficientBalance', $available, $required);`
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class SolidityError
{
    /** @param string[] $params e.g. ['uint256 available', 'uint256 required'] */
    public function __construct(
        public readonly string $name,
        public readonly array $params = [],
    ) {}
}
