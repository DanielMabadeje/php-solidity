<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node\Stmt;
use PhpSolidity\Validator\ValidationContext;

/**
 * A validator rule inspects the PHP syntax tree of one file and reports findings.
 *
 * To add a rule: implement this interface, pick unused diagnostic codes, document them in
 * docs/validator.md and register the class in ContractValidator::defaultRules().
 */
interface RuleInterface
{
    /** @param Stmt[] $ast */
    public function check(array $ast, ValidationContext $context): void;
}
