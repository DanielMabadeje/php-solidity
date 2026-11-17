<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node\Stmt\Interface_;
use PhpSolidity\Validator\ValidationContext;

/** E001: the file must declare something that can become Solidity. */
final class ContractPresenceRule extends AbstractRule
{
    public function check(array $ast, ValidationContext $context): void
    {
        if ($this->contractClasses($ast) !== []) {
            return;
        }

        foreach ($this->classLikes($ast) as $class) {
            if ($class instanceof Interface_) {
                return;
            }
        }

        $context->error(
            'E001',
            'Missing #[Contract] attribute. Your contract class must be decorated with #[Contract].',
            null,
            'Add #[Contract] above the class (use PhpSolidity\Attributes\Contract).',
        );
    }
}
