<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/** Marks a plain PHP class as a Solidity `struct`. Its typed properties become the struct members. */
#[Attribute(Attribute::TARGET_CLASS)]
class Struct {}
