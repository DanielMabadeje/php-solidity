<?php

declare(strict_types=1);

namespace PhpSolidity\Attributes;

use Attribute;

/**
 * Marks a method as the contract's `fallback() external` function.
 * Combine with #[Payable] to emit `fallback() external payable`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Fallback {}
