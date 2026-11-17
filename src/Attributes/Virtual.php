<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/** Emits the Solidity `virtual` keyword so child contracts can override the function. */
#[Attribute(Attribute::TARGET_METHOD)]
class Virtual {}
