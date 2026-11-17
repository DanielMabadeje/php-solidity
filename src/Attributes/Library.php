<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/** Marks a PHP class (static methods only) as a Solidity `library`. */
#[Attribute(Attribute::TARGET_CLASS)]
class Library
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $license = 'MIT',
        public readonly string $version = '^0.8.20',
    ) {}
}
