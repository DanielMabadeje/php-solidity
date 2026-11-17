<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/**
 * Declares the key and value types of a `mapping` property.
 *
 *     #[Mapping('address', 'uint256')]
 *     private mapping $balances;                 // mapping(address => uint256)
 *
 *     #[Mapping('address', 'mapping(address => uint256)')]
 *     private mapping $allowances;               // nested mapping
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Mapping
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {}
}
