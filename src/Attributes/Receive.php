<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/** Marks a method as the contract's `receive() external payable` function. */
#[Attribute(Attribute::TARGET_METHOD)]
class Receive {}
