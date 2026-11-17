<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpSolidity\Validator\ValidationContext;

/** E301 unsafe randomness · W301 PHP time functions */
final class RandomnessRule extends AbstractRule
{
    private const RANDOM = ['rand', 'mt_rand', 'random_int', 'random_bytes', 'mt_srand', 'srand', 'shuffle', 'array_rand', 'uniqid'];
    private const TIME   = ['time', 'microtime', 'date', 'strtotime', 'hrtime'];

    public function check(array $ast, ValidationContext $context): void
    {
        $this->walk($ast, function (Node $node) use ($context): void {
            if (! $node instanceof Expr\FuncCall || ! $node->name instanceof Node\Name) {
                return;
            }

            $name = strtolower($node->name->toString());

            if (in_array($name, self::RANDOM, true)) {
                $context->error(
                    'E301',
                    "{$name}() is not safe in smart contracts: block data can be manipulated and every node must compute the same result.",
                    $node,
                    'Use a verifiable randomness oracle such as Chainlink VRF.',
                );
            }

            if (in_array($name, self::TIME, true)) {
                $context->warning('W301', "PHP {$name}() detected.", $node, 'Use block::timestamp() for on-chain time.');
            }
        });
    }
}
