<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/**
 * Overrides the Solidity type of a property, parameter or method return value.
 *
 * PHP cannot express types like `uint256[]` or `mapping(address => uint256)` in a type
 * declaration, so write the Solidity type as a string:
 *
 *     #[SolType('uint256[]')]
 *     private array $ids;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD)]
class SolType
{
    public function __construct(public readonly string $type) {}
}
