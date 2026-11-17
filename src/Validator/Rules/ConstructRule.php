<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpSolidity\Validator\ValidationContext;

/**
 * Rejects PHP constructs that have no Solidity equivalent.
 *
 * E201 closures · E202 foreach · E203 try/catch/throw · E204 switch/match · E205 output and include
 * E206 forbidden function or class · E207 superglobals · E208 float or oversized literal
 * E209 isset/empty · E210 string interpolation · E211 casts · E212 global/static variables
 * E213 other unsupported expressions
 */
final class ConstructRule extends AbstractRule
{
    private const FORBIDDEN_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open',
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread', 'unlink', 'file',
        'header', 'setcookie', 'var_dump', 'print_r', 'var_export', 'error_log', 'printf', 'sleep', 'usleep',
        'json_encode', 'json_decode', 'serialize', 'unserialize', 'md5', 'sha1', 'hash',
    ];

    private const FORBIDDEN_PREFIXES = ['curl_', 'mysqli_', 'pg_', 'sqlite_'];

    private const FORBIDDEN_CLASSES = ['PDO', 'mysqli', 'DateTime', 'DateTimeImmutable', 'Exception', 'RuntimeException'];

    private const SUPERGLOBALS = ['_GET', '_POST', '_SESSION', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', 'GLOBALS'];

    public function check(array $ast, ValidationContext $context): void
    {
        $this->walk($ast, function (Node $node) use ($context): void {
            match (true) {
                $node instanceof Expr\Closure,
                $node instanceof Expr\ArrowFunction  => $context->error('E201', 'Closures and arrow functions do not exist in Solidity.', $node),
                $node instanceof Stmt\Foreach_       => $context->error('E202', 'foreach is not supported.', $node, 'Use for ($i = 0; $i < count($items); $i++).'),
                $node instanceof Stmt\TryCatch,
                $node instanceof Expr\Throw_         => $context->error('E203', 'try/catch and throw are not supported.', $node, 'Use EVM::revert("message") or EVM::revertWith(\'CustomError\', ...).'),
                $node instanceof Stmt\Switch_,
                $node instanceof Expr\Match_         => $context->error('E204', 'switch and match are not supported.', $node, 'Use if / else if.'),
                $node instanceof Stmt\Echo_,
                $node instanceof Expr\Print_,
                $node instanceof Expr\Exit_,
                $node instanceof Expr\Include_,
                $node instanceof Expr\Eval_,
                $node instanceof Stmt\InlineHTML     => $context->error('E205', 'Output, include and eval are not allowed in smart contracts.', $node, 'Emit an event instead of printing.'),
                $node instanceof Expr\Isset_,
                $node instanceof Expr\Empty_         => $context->error('E209', 'isset() and empty() are not supported.', $node, 'Compare with the zero value, e.g. $this->balances[$a] == 0.'),
                $node instanceof Scalar\InterpolatedString => $context->error('E210', 'String interpolation is not supported.', $node, 'Concatenate with the . operator.'),
                $node instanceof Expr\Cast           => $context->error('E211', 'PHP casts like (int) are not supported.', $node, 'Use conversions such as uint256($x) or address($x).'),
                $node instanceof Stmt\Global_,
                $node instanceof Stmt\Static_        => $context->error('E212', 'global and static variables are not supported.', $node, 'Use contract state variables.'),
                $node instanceof Scalar\Float_       => $this->floatLiteral($node, $context),
                $node instanceof Expr\FuncCall       => $this->functionCall($node, $context),
                $node instanceof Expr\New_           => $this->newExpression($node, $context),
                $node instanceof Expr\Variable       => $this->variable($node, $context),
                $node instanceof Expr\BinaryOp\Coalesce,
                $node instanceof Expr\AssignOp\Coalesce,
                $node instanceof Expr\BinaryOp\Spaceship,
                $node instanceof Expr\Instanceof_,
                $node instanceof Expr\ErrorSuppress,
                $node instanceof Expr\Clone_,
                $node instanceof Expr\Array_         => $context->error('E213', 'Unsupported expression: ' . $node->getType() . '.', $node, $this->hint($node)),
                default                              => null,
            };
        });
    }

    private function hint(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Array_              => "Use #[SolType('uint256[]')] storage and ->push(); array literals are not supported.",
            $node instanceof Expr\BinaryOp\Coalesce,
            $node instanceof Expr\AssignOp\Coalesce   => 'Mappings already return the zero value for missing keys.',
            default                                   => null,
        };
    }

    private function floatLiteral(Scalar\Float_ $node, ValidationContext $context): void
    {
        $raw = (string) $node->getAttribute('rawValue', (string) $node->value);

        if (preg_match('/^(0[xXbB][0-9a-fA-F_]+|[0-9_]+)$/', $raw) === 1) {
            $context->error(
                'E208',
                "Integer literal {$raw} does not fit in a PHP integer.",
                $node,
                'Build large numbers with 10 ** 18 or shifts (1 << 200).',
            );

            return;
        }

        $context->error(
            'E208',
            "Float literal '{$raw}' detected. Solidity has no floating-point.",
            $node,
            'Use integer arithmetic and scale by 10 ** 18.',
        );
    }

    private function functionCall(Expr\FuncCall $call, ValidationContext $context): void
    {
        if (! $call->name instanceof Node\Name) {
            return;
        }

        $name  = strtolower($call->name->toString());
        $block = in_array($name, self::FORBIDDEN_FUNCTIONS, true);

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            $block = $block || str_starts_with($name, $prefix);
        }

        if ($block) {
            $context->error('E206', "{$name}() is not allowed in smart contracts: contracts cannot do I/O or call PHP libraries.", $call);
        }
    }

    private function newExpression(Expr\New_ $new, ValidationContext $context): void
    {
        if ($new->class instanceof Node\Name && in_array($new->class->getLast(), self::FORBIDDEN_CLASSES, true)) {
            $context->error('E206', "new {$new->class->getLast()} is not allowed in smart contracts.", $new);
        }
    }

    private function variable(Expr\Variable $variable, ValidationContext $context): void
    {
        if (is_string($variable->name) && in_array($variable->name, self::SUPERGLOBALS, true)) {
            $context->error('E207', "PHP superglobal \${$variable->name} is not allowed in smart contracts.", $variable, 'Use msg::sender() for transaction context.');
        }
    }
}
