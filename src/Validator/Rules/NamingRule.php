<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Property;
use PhpSolidity\TypeSystem\Naming;
use PhpSolidity\Validator\ValidationContext;

/** W701: a parameter shadows a state variable or is a reserved Solidity word, so it will be renamed `name_`. */
final class NamingRule extends AbstractRule
{
    public function check(array $ast, ValidationContext $context): void
    {
        foreach ($this->contractClasses($ast) as $class) {
            $state = [];
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Property) {
                    foreach ($stmt->props as $prop) {
                        $state[] = $prop->name->toString();
                    }
                }
            }

            foreach ($this->methodsOf($class) as $method) {
                foreach ($method->params as $param) {
                    if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                        continue;
                    }

                    $name = $param->var->name;
                    if (Naming::safe($name, $state) !== $name) {
                        $context->warning(
                            'W701',
                            "Parameter \${$name} clashes with a state variable or Solidity keyword and is emitted as '{$name}_'.",
                            $param,
                            'Rename the parameter if you want a different name in the ABI.',
                        );
                    }
                }
            }
        }
    }
}
