<?php
declare(strict_types=1);
namespace PhpSolidity\Attributes;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class SolidityEvent
{
    /**
     * @param string[] $params e.g. ['address indexed from', 'uint256 value']
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params = [],
    ) {}
}
