<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/**
 * Emits a Solidity `import "<path>";` line.
 *
 *     #[Import('@openzeppelin/contracts/access/Ownable.sol')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Import
{
    public function __construct(public readonly string $path) {}
}
