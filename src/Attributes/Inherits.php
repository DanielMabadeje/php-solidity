<?php
declare(strict_types=1);
namespace PhpSolidity\Attributes;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Inherits
{
    /** @var string[] */
    public readonly array $contracts;

    public function __construct(string ...$contracts)
    {
        $this->contracts = $contracts;
    }
}
