<?php
declare(strict_types=1);
namespace PhpSolidity\Attributes;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Storage
{
    public function __construct(
        public readonly bool $public = false,
        public readonly ?string $name = null,
    ) {}
}
