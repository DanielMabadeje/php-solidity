<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/** Emits the Solidity `override` keyword. */
#[Attribute(Attribute::TARGET_METHOD)]
class Override {}
