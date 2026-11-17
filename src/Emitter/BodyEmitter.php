<?php

declare(strict_types=1);

namespace PhpSolidity\Emitter;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpSolidity\AST\Nodes\ParameterNode;
use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\TypeSystem\TypeMapper;

/**
 * Stage 3 of the pipeline: php-parser statements → Solidity statements.
 *
 * Unlike a find-and-replace on printed PHP, this class walks the syntax tree node by node.
 * That gives us three things text replacement cannot:
 *   1. correct operator grouping (PHP and Solidity disagree about `&` vs `==`),
 *   2. local variable declarations with inferred types (`uint256 total = ...`),
 *   3. precise error messages with a line number.
 */
final class BodyEmitter
{
    private const INDENT = '    ';

    /** Binary operators that map 1:1 onto Solidity. */
    private const BINARY = [
        BinaryOp\Plus::class           => '+',
        BinaryOp\Minus::class          => '-',
        BinaryOp\Mul::class            => '*',
        BinaryOp\Div::class            => '/',
        BinaryOp\Mod::class            => '%',
        BinaryOp\Pow::class            => '**',
        BinaryOp\BitwiseAnd::class     => '&',
        BinaryOp\BitwiseOr::class      => '|',
        BinaryOp\BitwiseXor::class     => '^',
        BinaryOp\ShiftLeft::class      => '<<',
        BinaryOp\ShiftRight::class     => '>>',
        BinaryOp\Equal::class          => '==',
        BinaryOp\Identical::class      => '==',
        BinaryOp\NotEqual::class       => '!=',
        BinaryOp\NotIdentical::class   => '!=',
        BinaryOp\Smaller::class        => '<',
        BinaryOp\SmallerOrEqual::class => '<=',
        BinaryOp\Greater::class        => '>',
        BinaryOp\GreaterOrEqual::class => '>=',
        BinaryOp\BooleanAnd::class     => '&&',
        BinaryOp\BooleanOr::class      => '||',
        BinaryOp\LogicalAnd::class     => '&&',
        BinaryOp\LogicalOr::class      => '||',
    ];

    private const COMPARISON = [
        BinaryOp\Equal::class, BinaryOp\Identical::class, BinaryOp\NotEqual::class,
        BinaryOp\NotIdentical::class, BinaryOp\Smaller::class, BinaryOp\SmallerOrEqual::class,
        BinaryOp\Greater::class, BinaryOp\GreaterOrEqual::class,
        BinaryOp\BooleanAnd::class, BinaryOp\BooleanOr::class,
        BinaryOp\LogicalAnd::class, BinaryOp\LogicalOr::class,
    ];

    /** Compound assignments that exist in Solidity as written. */
    private const ASSIGN_OPS = [
        AssignOp\Plus::class       => '+=',
        AssignOp\Minus::class      => '-=',
        AssignOp\Mul::class        => '*=',
        AssignOp\Div::class        => '/=',
        AssignOp\Mod::class        => '%=',
        AssignOp\BitwiseAnd::class => '&=',
        AssignOp\BitwiseOr::class  => '|=',
        AssignOp\BitwiseXor::class => '^=',
        AssignOp\ShiftLeft::class  => '<<=',
        AssignOp\ShiftRight::class => '>>=',
    ];

    private const GLOBALS = [
        'msg'   => ['sender' => 'msg.sender', 'value' => 'msg.value', 'data' => 'msg.data', 'sig' => 'msg.sig'],
        'block' => [
            'timestamp' => 'block.timestamp', 'number' => 'block.number', 'chainid' => 'block.chainid',
            'basefee' => 'block.basefee', 'gaslimit' => 'block.gaslimit', 'coinbase' => 'block.coinbase',
            'prevrandao' => 'block.prevrandao',
        ],
        'tx'      => ['origin' => 'tx.origin', 'gasprice' => 'tx.gasprice'],
        'address' => ['zero' => 'address(0)', 'self' => 'address(this)'],
    ];

    private const GLOBAL_TYPES = [
        'msg.sender' => 'address', 'msg.value' => 'uint256', 'msg.data' => 'bytes', 'msg.sig' => 'bytes4',
        'block.timestamp' => 'uint256', 'block.number' => 'uint256', 'block.chainid' => 'uint256',
        'block.basefee' => 'uint256', 'block.gaslimit' => 'uint256', 'block.coinbase' => 'address',
        'block.prevrandao' => 'uint256', 'tx.origin' => 'address', 'tx.gasprice' => 'uint256',
        'address(0)' => 'address', 'address(this)' => 'address',
    ];

    private Scope $scope;

    /**
     * @param array<string, string> $stateVariables  name => Solidity type
     * @param array<string, string> $functionReturns function name => Solidity return type
     */
    public function __construct(
        private readonly TypeMapper $types,
        private readonly SourceContext $source,
        private readonly array $stateVariables = [],
        private readonly array $functionReturns = [],
    ) {
        $this->scope = new Scope(array_keys($stateVariables));
    }

    // ─────────────────────────────────────────────────────────
    // Public API used by SolidityEmitter
    // ─────────────────────────────────────────────────────────

    /** Start a fresh variable scope (call once per function or modifier). */
    public function beginScope(): void
    {
        $this->scope = new Scope(array_keys($this->stateVariables));
    }

    /**
     * Declare parameters in the current scope and return the Solidity parameter list,
     * e.g. "string memory name_, uint256 amount".
     *
     * @param ParameterNode[] $params
     */
    public function parameterList(array $params): string
    {
        $parts = [];

        foreach ($params as $param) {
            $solName = $this->scope->declare($param->name, $param->solidityType);
            $parts[] = $this->withLocation($param->solidityType) . ' ' . $solName;
        }

        return implode(', ', $parts);
    }

    /** Append `memory` to reference types (string, bytes, arrays, structs). */
    public function withLocation(string $solidityType): string
    {
        if (preg_match('/\b(memory|storage|calldata)\b/', $solidityType)) {
            return $solidityType;
        }

        return $this->types->needsDataLocation($solidityType, $this->source->structNames)
            ? $solidityType . ' memory'
            : $solidityType;
    }

    /**
     * Translate a list of statements. Lines are relative to the block; the caller indents them.
     *
     * @param Stmt[] $stmts
     * @return string[]
     */
    public function statements(array $stmts): array
    {
        $lines = [];
        foreach ($stmts as $stmt) {
            foreach ($this->statement($stmt) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Translate one expression (used for state variable initial values). */
    public function expression(Expr $expr): string
    {
        return $this->expr($expr);
    }

    // ─────────────────────────────────────────────────────────
    // Statements
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function statement(Stmt $stmt): array
    {
        return match (true) {
            $stmt instanceof Stmt\Expression => $this->expressionStatement($stmt),
            $stmt instanceof Stmt\Return_    => [$stmt->expr === null ? 'return;' : 'return ' . $this->expr($stmt->expr) . ';'],
            $stmt instanceof Stmt\If_        => $this->ifStatement($stmt),
            $stmt instanceof Stmt\While_     => $this->whileStatement($stmt),
            $stmt instanceof Stmt\Do_        => $this->doStatement($stmt),
            $stmt instanceof Stmt\For_       => $this->forStatement($stmt),
            $stmt instanceof Stmt\Break_     => $this->jump($stmt, 'break', $stmt->num),
            $stmt instanceof Stmt\Continue_  => $this->jump($stmt, 'continue', $stmt->num),
            $stmt instanceof Stmt\Unset_     => $this->unsetStatement($stmt),
            $stmt instanceof Stmt\Nop        => [],
            $stmt instanceof Stmt\Block      => array_merge(['{'], $this->block($stmt->stmts), ['}']),
            default                          => $this->fail($stmt, "Unsupported statement '" . $stmt->getType() . "'.", $this->statementHint($stmt)),
        };
    }

    private function statementHint(Stmt $stmt): ?string
    {
        return match (true) {
            $stmt instanceof Stmt\Foreach_   => 'Solidity has no foreach. Use for ($i = 0; $i < count($items); $i++).',
            $stmt instanceof Stmt\Switch_    => 'Solidity has no switch. Use if / else if.',
            $stmt instanceof Stmt\TryCatch   => 'try/catch is not supported yet.',
            $stmt instanceof Stmt\Echo_      => 'Contracts cannot print. Emit an event instead.',
            default                          => null,
        };
    }

    /**
     * @param Stmt[] $stmts
     * @return string[]
     */
    private function block(array $stmts): array
    {
        $this->scope->push();
        $lines = [];
        foreach ($this->statements($stmts) as $line) {
            $lines[] = self::INDENT . $line;
        }
        $this->scope->pop();

        return $lines;
    }

    /** @return string[] */
    private function expressionStatement(Stmt\Expression $stmt): array
    {
        $expr = $stmt->expr;

        if ($expr instanceof Expr\StaticCall) {
            $macro = $this->staticCallStatement($expr);
            if ($macro !== null) {
                return $macro;
            }
        }

        if ($expr instanceof Expr\Assign) {
            // $items[] = $value;  →  items.push(value);
            if ($expr->var instanceof Expr\ArrayDimFetch && $expr->var->dim === null) {
                return [$this->expr($expr->var->var) . '.push(' . $this->expr($expr->expr) . ');'];
            }

            // First assignment to a plain variable declares a local.
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name) && $expr->var->name !== 'this'
                && $this->scope->lookup($expr->var->name) === null) {
                return [$this->declareLocal($expr, $stmt->getDocComment()?->getText()) . ';'];
            }
        }

        return [$this->expr($expr) . ';'];
    }

    private function declareLocal(Expr\Assign $assign, ?string $docblock): string
    {
        /** @var Expr\Variable $variable */
        $variable = $assign->var;
        $phpName  = is_string($variable->name) ? $variable->name : '';

        $value = $this->expr($assign->expr);          // translate first: the value cannot see the new variable
        $type  = null;

        if ($docblock !== null && preg_match('/@var\s+([^$\r\n*]+)/', $docblock, $m)) {
            $type = trim($m[1]);
        }

        $type ??= $this->typeOf($assign->expr);

        if ($type === null) {
            $this->fail(
                $assign,
                "Cannot infer the type of local variable \${$phpName}.",
                "Add a docblock above the assignment, for example: /** @var uint256 */ \${$phpName} = ...;",
            );
        }

        $solName = $this->scope->declare($phpName, $type);

        return $this->withLocation($type) . ' ' . $solName . ' = ' . $value;
    }

    /** @return string[]|null  null when the call is not a statement-level macro */
    private function staticCallStatement(Expr\StaticCall $call): ?array
    {
        $class = $call->class instanceof Node\Name ? $call->class->getLast() : '';
        $name  = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

        if ($class === 'Event' && $name === 'emit') {
            [$event, $args] = $this->nameAndArgs($call, 'Event::emit');

            return ["emit {$event}(" . implode(', ', $args) . ');'];
        }

        if ($class === 'EVM' && $name === 'revertWith') {
            [$error, $args] = $this->nameAndArgs($call, 'EVM::revertWith');

            return ["revert {$error}(" . implode(', ', $args) . ');'];
        }

        if ($class === 'EVM' && $name === 'placeholder') {
            return ['_;'];
        }

        if ($class === 'EVM' && $name === 'sendValue') {
            if (count($call->args) !== 2) {
                $this->fail($call, 'EVM::sendValue() takes exactly two arguments: the recipient and the amount.');
            }
            $to     = $this->expr($this->arg($call->args[0]));
            $amount = $this->expr($this->arg($call->args[1]));

            return [
                '{',
                self::INDENT . "(bool _sent, ) = payable({$to}).call{value: {$amount}}(\"\");",
                self::INDENT . 'require(_sent, "PhpSolidity: ETH transfer failed");',
                '}',
            ];
        }

        return null;
    }

    /**
     * First argument must be a string literal naming an event or error.
     *
     * @return array{0: string, 1: string[]}
     */
    private function nameAndArgs(Expr\StaticCall $call, string $label): array
    {
        $first = $call->args[0] ?? null;

        if (! $first instanceof Arg || ! $first->value instanceof Scalar\String_) {
            $this->fail($call, "{$label}() needs the name as a string literal in the first argument.");
        }

        $args = [];
        foreach (array_slice($call->args, 1) as $arg) {
            $args[] = $this->expr($this->arg($arg));
        }

        return [$first->value->value, $args];
    }

    /** @return string[] */
    private function ifStatement(Stmt\If_ $if): array
    {
        $lines = ['if (' . $this->expr($if->cond) . ') {'];
        array_push($lines, ...$this->block($if->stmts));

        foreach ($if->elseifs as $elseif) {
            $lines[] = '} else if (' . $this->expr($elseif->cond) . ') {';
            array_push($lines, ...$this->block($elseif->stmts));
        }

        if ($if->else !== null) {
            $lines[] = '} else {';
            array_push($lines, ...$this->block($if->else->stmts));
        }

        $lines[] = '}';

        return $lines;
    }

    /** @return string[] */
    private function whileStatement(Stmt\While_ $while): array
    {
        $lines = ['while (' . $this->expr($while->cond) . ') {'];
        array_push($lines, ...$this->block($while->stmts));
        $lines[] = '}';

        return $lines;
    }

    /** @return string[] */
    private function doStatement(Stmt\Do_ $do): array
    {
        $lines = ['do {'];
        array_push($lines, ...$this->block($do->stmts));
        $lines[] = '} while (' . $this->expr($do->cond) . ');';

        return $lines;
    }

    /** @return string[] */
    private function forStatement(Stmt\For_ $for): array
    {
        if (count($for->init) > 1 || count($for->cond) > 1 || count($for->loop) > 1) {
            $this->fail($for, 'Solidity for-loops allow one initializer, one condition and one step.', 'Move extra work into the loop body.');
        }

        $this->scope->push();

        $init = '';
        if ($for->init !== []) {
            $first = $for->init[0];
            if ($first instanceof Expr\Assign && $first->var instanceof Expr\Variable && is_string($first->var->name)
                && $this->scope->lookup($first->var->name) === null) {
                $init = $this->declareLocal($first, null);
            } else {
                $init = $this->expr($first);
            }
        }

        $cond = $for->cond === [] ? '' : $this->expr($for->cond[0]);
        $loop = $for->loop === [] ? '' : $this->expr($for->loop[0]);

        $lines = ["for ({$init}; {$cond}; {$loop}) {"];
        array_push($lines, ...$this->block($for->stmts));
        $lines[] = '}';

        $this->scope->pop();

        return $lines;
    }

    /** @return string[] */
    private function jump(Stmt $stmt, string $keyword, ?Expr $levels): array
    {
        if ($levels !== null && ! ($levels instanceof Scalar\Int_ && $levels->value === 1)) {
            $this->fail($stmt, "'{$keyword} N' with N > 1 is not supported in Solidity.");
        }

        return ["{$keyword};"];
    }

    /** @return string[] */
    private function unsetStatement(Stmt\Unset_ $unset): array
    {
        $lines = [];
        foreach ($unset->vars as $var) {
            $lines[] = 'delete ' . $this->expr($var) . ';';
        }

        return $lines;
    }

    // ─────────────────────────────────────────────────────────
    // Expressions
    // ─────────────────────────────────────────────────────────

    private function expr(Expr $e): string
    {
        return match (true) {
            $e instanceof Scalar\Int_          => $this->intLiteral($e),
            $e instanceof Scalar\String_       => $this->stringLiteral($e->value),
            $e instanceof Scalar\Float_        => $this->fail($e, 'Floating point numbers do not exist in Solidity.', 'Use integers scaled by 10 ** 18.'),
            $e instanceof Expr\ConstFetch      => $this->constFetch($e),
            $e instanceof Expr\Variable        => $this->variable($e),
            $e instanceof Expr\PropertyFetch   => $this->propertyFetch($e),
            $e instanceof Expr\ArrayDimFetch   => $this->arrayDimFetch($e),
            $e instanceof Expr\Assign          => $this->assign($e),
            $e instanceof Expr\AssignOp        => $this->assignOp($e),
            $e instanceof BinaryOp             => $this->binary($e),
            $e instanceof Expr\BooleanNot      => '!' . $this->operand($e->expr),
            $e instanceof Expr\BitwiseNot      => '~' . $this->operand($e->expr),
            $e instanceof Expr\UnaryMinus      => '-' . $this->operand($e->expr),
            $e instanceof Expr\UnaryPlus       => $this->operand($e->expr),
            $e instanceof Expr\PreInc          => '++' . $this->operand($e->var),
            $e instanceof Expr\PreDec          => '--' . $this->operand($e->var),
            $e instanceof Expr\PostInc         => $this->operand($e->var) . '++',
            $e instanceof Expr\PostDec         => $this->operand($e->var) . '--',
            $e instanceof Expr\Ternary         => $this->ternary($e),
            $e instanceof Expr\StaticCall      => $this->staticCall($e),
            $e instanceof Expr\MethodCall      => $this->methodCall($e),
            $e instanceof Expr\FuncCall        => $this->funcCall($e),
            $e instanceof Expr\New_            => $this->newExpr($e),
            $e instanceof Expr\ClassConstFetch => $this->classConstFetch($e),
            default                            => $this->fail($e, "Unsupported expression '" . $e->getType() . "'.", $this->expressionHint($e)),
        };
    }

    private function expressionHint(Expr $e): ?string
    {
        return match (true) {
            $e instanceof Expr\Array_       => "Array literals are not supported. Use #[SolType('uint256[]')] storage and ->push().",
            $e instanceof Expr\Cast         => 'Casts like (int) are not supported. Use conversions such as uint256($x) or address($x).',
            $e instanceof Expr\Closure,
            $e instanceof Expr\ArrowFunction => 'Closures do not exist in Solidity.',
            $e instanceof Expr\BinaryOp\Coalesce => 'The ?? operator does not exist. Mappings already return zero values for missing keys.',
            default                         => null,
        };
    }

    private function intLiteral(Scalar\Int_ $int): string
    {
        if ($int->getAttribute('kind') === Scalar\Int_::KIND_HEX) {
            return '0x' . dechex($int->value);
        }

        return (string) $int->value;
    }

    private function stringLiteral(string $value): string
    {
        $unicode = preg_match('/[^\x00-\x7F]/', $value) === 1;
        $escaped = '';

        foreach (str_split($value) as $char) {
            $escaped .= match ($char) {
                '"'     => '\\"',
                '\\'    => '\\\\',
                "\n"    => '\\n',
                "\r"    => '\\r',
                "\t"    => '\\t',
                default => (! $unicode && (ord($char) < 0x20 || ord($char) === 0x7F)) ? sprintf('\\x%02x', ord($char)) : $char,
            };
        }

        return ($unicode ? 'unicode' : '') . '"' . $escaped . '"';
    }

    private function constFetch(Expr\ConstFetch $const): string
    {
        $name = strtolower($const->name->toString());

        return match ($name) {
            'true', 'false' => $name,
            'null'          => $this->fail($const, 'Null does not exist in Solidity.', 'Use the zero value of the type (0, address::zero(), false).'),
            default         => $this->fail($const, "Constant '" . $const->name->toString() . "' is not supported."),
        };
    }

    private function variable(Expr\Variable $var): string
    {
        if (! is_string($var->name)) {
            $this->fail($var, 'Variable variables ($$x) are not supported.');
        }

        if ($var->name === 'this') {
            $this->fail($var, 'Use $this->member to reach state variables and functions.');
        }

        $entry = $this->scope->lookup($var->name);
        if ($entry === null) {
            $this->fail(
                $var,
                "Variable \${$var->name} is used before it is declared.",
                'Solidity scopes variables to the block they are declared in. Declare it before the if/for block that uses it.',
            );
        }

        return $entry['name'];
    }

    private function propertyFetch(Expr\PropertyFetch $fetch): string
    {
        if (! $fetch->name instanceof Node\Identifier) {
            $this->fail($fetch, 'Dynamic property names are not supported.');
        }

        $name = $fetch->name->toString();

        if ($fetch->var instanceof Expr\Variable && $fetch->var->name === 'this') {
            if (! array_key_exists($name, $this->stateVariables)) {
                $this->fail($fetch, "Unknown state variable \$this->{$name}.", 'Declare it as a property of the contract.');
            }

            return $name;
        }

        return $this->operand($fetch->var) . '.' . $name;
    }

    private function arrayDimFetch(Expr\ArrayDimFetch $fetch): string
    {
        if ($fetch->dim === null) {
            $this->fail($fetch, 'Reading with [] is not valid. Use $array[$index], or $array[] = $value to append.');
        }

        return $this->operand($fetch->var) . '[' . $this->expr($fetch->dim) . ']';
    }

    private function assign(Expr\Assign $assign): string
    {
        if ($assign->var instanceof Expr\Array_ || $assign->var instanceof Expr\List_) {
            $this->fail($assign, 'Destructuring assignment is not supported.');
        }

        // Assignment used inside another expression cannot declare a variable.
        return $this->expr($assign->var) . ' = ' . $this->expr($assign->expr);
    }

    private function assignOp(Expr\AssignOp $op): string
    {
        $target = $this->expr($op->var);
        $value  = $this->expr($op->expr);

        if ($op instanceof AssignOp\Pow) {
            return "{$target} = {$target} ** {$this->wrapIfComplex($op->expr, $value)}";
        }

        if ($op instanceof AssignOp\Concat) {
            return "{$target} = string.concat({$target}, {$value})";
        }

        $symbol = self::ASSIGN_OPS[$op::class] ?? null;
        if ($symbol === null) {
            $this->fail($op, "Unsupported assignment operator '" . $op->getType() . "'.");
        }

        return "{$target} {$symbol} {$value}";
    }

    private function binary(BinaryOp $op): string
    {
        if ($op instanceof BinaryOp\Concat) {
            return 'string.concat(' . $this->expr($op->left) . ', ' . $this->expr($op->right) . ')';
        }

        $symbol = self::BINARY[$op::class] ?? null;
        if ($symbol === null) {
            $this->fail($op, "Operator '" . $op->getOperatorSigil() . "' is not supported in Solidity.", $this->expressionHint($op));
        }

        return $this->operandOf($op, $op->left, true) . " {$symbol} " . $this->operandOf($op, $op->right, false);
    }

    /**
     * Wrap nested operations in parentheses so the Solidity grouping always matches the
     * PHP syntax tree. The only chains left bare are left-leaning runs of the same
     * associative operator (a + b + c, a && b && c).
     */
    private function operandOf(BinaryOp $parent, Expr $child, bool $isLeft): string
    {
        $text = $this->expr($child);

        if ($child instanceof BinaryOp\Concat) {
            return $text;  // string.concat(...) is already a call
        }

        if ($child instanceof BinaryOp) {
            $sameAssociative = $isLeft && $child::class === $parent::class
                && in_array($parent::class, [BinaryOp\Plus::class, BinaryOp\Mul::class, BinaryOp\BooleanAnd::class, BinaryOp\BooleanOr::class], true);

            return $sameAssociative ? $text : "({$text})";
        }

        if ($child instanceof Expr\Ternary || $child instanceof Expr\Assign || $child instanceof Expr\AssignOp) {
            return "({$text})";
        }

        return $text;
    }

    /** Wrap in parentheses unless the expression is already atomic. */
    private function operand(Expr $e): string
    {
        $text = $this->expr($e);

        if ($e instanceof BinaryOp\Concat) {
            return $text;
        }

        if ($e instanceof BinaryOp || $e instanceof Expr\Ternary || $e instanceof Expr\Assign
            || $e instanceof Expr\AssignOp || $e instanceof Expr\UnaryMinus || $e instanceof Expr\BooleanNot) {
            return "({$text})";
        }

        return $text;
    }

    private function wrapIfComplex(Expr $e, string $text): string
    {
        return ($e instanceof BinaryOp && ! $e instanceof BinaryOp\Concat) ? "({$text})" : $text;
    }

    private function ternary(Expr\Ternary $ternary): string
    {
        if ($ternary->if === null) {
            $this->fail($ternary, 'The short ternary ?: is not supported.', 'Write the condition out: $a ? $a : $b.');
        }

        return $this->operand($ternary->cond) . ' ? ' . $this->operand($ternary->if) . ' : ' . $this->operand($ternary->else);
    }

    // ─── Calls ───────────────────────────────────────────────

    private function staticCall(Expr\StaticCall $call): string
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            $this->fail($call, 'Dynamic static calls are not supported.');
        }

        $class = $call->class->getLast();
        $name  = $call->name->toString();

        if (isset(self::GLOBALS[$class])) {
            $emitted = self::GLOBALS[$class][$name] ?? null;
            if ($emitted === null) {
                $this->fail($call, "Unknown {$class}::{$name}(). Available: " . implode(', ', array_keys(self::GLOBALS[$class])) . '.');
            }

            return $emitted;
        }

        if ($class === 'EVM') {
            return $this->evmCall($call, $name);
        }

        if ($class === 'Event') {
            $this->fail($call, 'Event::emit() must be used as a statement.');
        }

        if (in_array($class, $this->source->enumNames, true)) {
            $this->fail($call, "Enum method {$class}::{$name}() is not supported.");
        }

        // Anything else is treated as a library call: MathLib::add($a, $b) → MathLib.add(a, b)
        return "{$class}.{$name}(" . implode(', ', $this->args($call->args)) . ')';
    }

    private function evmCall(Expr\StaticCall $call, string $name): string
    {
        $args = $this->args($call->args);

        return match ($name) {
            'require'      => 'require(' . implode(', ', $args) . ')',
            'revert'       => 'revert(' . implode(', ', $args) . ')',
            'assert'       => 'assert(' . implode(', ', $args) . ')',
            'keccak256'    => 'keccak256(abi.encodePacked(' . implode(', ', $args) . '))',
            'encodePacked' => 'abi.encodePacked(' . implode(', ', $args) . ')',
            'encode'       => 'abi.encode(' . implode(', ', $args) . ')',
            'sendValue', 'revertWith', 'placeholder' => $this->fail($call, "EVM::{$name}() must be used as a statement on its own line."),
            default        => $this->fail($call, "Unknown EVM::{$name}(). Available: require, revert, assert, revertWith, sendValue, keccak256, encodePacked, encode, placeholder."),
        };
    }

    private function methodCall(Expr\MethodCall $call): string
    {
        if (! $call->name instanceof Node\Identifier) {
            $this->fail($call, 'Dynamic method names are not supported.');
        }

        $name = $call->name->toString();
        $args = $this->args($call->args);

        // $this->helper($x)  →  helper(x)
        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return "{$name}(" . implode(', ', $args) . ')';
        }

        $target = $this->operand($call->var);

        if ($this->typeOf($call->var) === 'address') {
            if (in_array($name, ['transfer', 'send'], true)) {
                return "payable({$target}).{$name}(" . implode(', ', $args) . ')';
            }

            if ($name === 'balance' && $args === []) {
                return "{$target}.balance";
            }
        }

        return "{$target}.{$name}(" . implode(', ', $args) . ')';
    }

    private function funcCall(Expr\FuncCall $call): string
    {
        if (! $call->name instanceof Node\Name) {
            $this->fail($call, 'Dynamic function calls are not supported.');
        }

        $name = $call->name->toString();
        $args = $this->args($call->args);

        if (in_array(strtolower($name), ['count', 'sizeof'], true) && count($args) === 1) {
            return $this->operand($this->arg($call->args[0])) . '.length';
        }

        if ($name === 'payable' || $name === 'address' || $name === 'string' || $name === 'bytes'
            || $this->types->isElementary($name) || isset($this->source->contracts[$name])) {
            return "{$name}(" . implode(', ', $args) . ')';
        }

        $this->fail(
            $call,
            "Function {$name}() cannot be called inside a contract.",
            'Call your own functions with $this->name(), and use EVM::, msg::, block:: for EVM features.',
        );
    }

    private function newExpr(Expr\New_ $new): string
    {
        if (! $new->class instanceof Node\Name) {
            $this->fail($new, 'Only `new SomeType(...)` is supported.');
        }

        $class = $new->class->getLast();

        // new uint8(18)  →  18
        if ($this->types->isElementary($class)) {
            if (count($new->args) !== 1) {
                $this->fail($new, "new {$class}() needs exactly one value.");
            }

            return $this->expr($this->arg($new->args[0]));
        }

        // new Point(x: 1, y: 2)  →  Point({x: 1, y: 2})
        if (in_array($class, $this->source->structNames, true)) {
            $named = array_filter($new->args, static fn ($a) => $a instanceof Arg && $a->name !== null);

            if ($named !== []) {
                $pairs = [];
                foreach ($new->args as $a) {
                    if (! $a instanceof Arg || $a->name === null) {
                        $this->fail($new, 'Do not mix named and positional arguments in a struct constructor.');
                    }
                    $pairs[] = $a->name->toString() . ': ' . $this->expr($a->value);
                }

                return "{$class}({" . implode(', ', $pairs) . '})';
            }

            return "{$class}(" . implode(', ', $this->args($new->args)) . ')';
        }

        // new Token(args)  →  new Token(args)   (contract creation)
        return "new {$class}(" . implode(', ', $this->args($new->args)) . ')';
    }

    private function classConstFetch(Expr\ClassConstFetch $fetch): string
    {
        if ($fetch->class instanceof Node\Name && $fetch->name instanceof Node\Identifier) {
            $class = $fetch->class->getLast();

            if (in_array($class, $this->source->enumNames, true)) {
                return $class . '.' . $fetch->name->toString();
            }
        }

        $this->fail($fetch, 'Only enum cases (Status::Active) can be read with ::.');
    }

    /**
     * @param array<Node> $args
     * @return string[]
     */
    private function args(array $args): array
    {
        $out = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                $this->fail($arg, 'First-class callable syntax is not supported.');
            }
            if ($arg->unpack) {
                $this->fail($arg, 'Argument unpacking (...$args) is not supported.');
            }
            if ($arg->name !== null) {
                $this->fail($arg, 'Named arguments are only supported when creating structs.');
            }
            $out[] = $this->expr($arg->value);
        }

        return $out;
    }

    private function arg(Node $arg): Expr
    {
        if (! $arg instanceof Arg) {
            $this->fail($arg, 'Unsupported argument.');
        }

        return $arg->value;
    }

    // ─────────────────────────────────────────────────────────
    // Type inference
    // ─────────────────────────────────────────────────────────

    /**
     * Best-effort Solidity type of an expression, or null when it cannot be known.
     * Used to declare local variables (`uint256 total = ...`).
     */
    private function typeOf(Expr $e): ?string
    {
        switch (true) {
            case $e instanceof Scalar\Int_:
                return 'uint256';
            case $e instanceof Scalar\String_:
                return 'string';
            case $e instanceof Expr\ConstFetch:
                return in_array(strtolower($e->name->toString()), ['true', 'false'], true) ? 'bool' : null;
            case $e instanceof Expr\Variable:
                return is_string($e->name) ? ($this->scope->lookup($e->name)['type'] ?? null) : null;
            case $e instanceof Expr\PropertyFetch:
                return $this->propertyType($e);
            case $e instanceof Expr\ArrayDimFetch:
                $container = $this->typeOf($e->var);
                if ($container === null) {
                    return null;
                }

                return $this->types->mappingValueType($container) ?? $this->types->arrayElementType($container);
            case $e instanceof BinaryOp\Concat:
                return 'string';
            case $e instanceof BinaryOp:
                if (in_array($e::class, self::COMPARISON, true)) {
                    return 'bool';
                }

                return $this->arithmeticType($e);
            case $e instanceof Expr\BooleanNot:
                return 'bool';
            case $e instanceof Expr\UnaryMinus:
                return $e->expr instanceof Scalar\Int_ ? 'int256' : $this->typeOf($e->expr);
            case $e instanceof Expr\BitwiseNot:
            case $e instanceof Expr\UnaryPlus:
                return $this->typeOf($e->expr);
            case $e instanceof Expr\PreInc:
            case $e instanceof Expr\PreDec:
            case $e instanceof Expr\PostInc:
            case $e instanceof Expr\PostDec:
                return $this->typeOf($e->var);
            case $e instanceof Expr\Ternary:
                return ($e->if !== null ? $this->typeOf($e->if) : null) ?? $this->typeOf($e->else);
            case $e instanceof Expr\Assign:
                return $this->typeOf($e->expr);
            case $e instanceof Expr\StaticCall:
                return $this->staticCallType($e);
            case $e instanceof Expr\MethodCall:
                return $this->methodCallType($e);
            case $e instanceof Expr\FuncCall:
                if ($e->name instanceof Node\Name) {
                    $name = $e->name->toString();
                    if (in_array(strtolower($name), ['count', 'sizeof'], true)) {
                        return 'uint256';
                    }
                    if ($this->types->isElementary($name) || in_array($name, ['address', 'string', 'bytes'], true)
                        || isset($this->source->contracts[$name])) {
                        return $name;
                    }
                    if ($name === 'payable') {
                        return 'address';
                    }
                }

                return null;
            case $e instanceof Expr\New_:
                if ($e->class instanceof Node\Name) {
                    return $e->class->getLast();
                }

                return null;
            case $e instanceof Expr\ClassConstFetch:
                if ($e->class instanceof Node\Name && in_array($e->class->getLast(), $this->source->enumNames, true)) {
                    return $e->class->getLast();
                }

                return null;
            default:
                return null;
        }
    }

    private function propertyType(Expr\PropertyFetch $fetch): ?string
    {
        if (! $fetch->name instanceof Node\Identifier) {
            return null;
        }

        $name = $fetch->name->toString();

        if ($fetch->var instanceof Expr\Variable && $fetch->var->name === 'this') {
            return $this->stateVariables[$name] ?? null;
        }

        $owner = $this->typeOf($fetch->var);

        return $owner !== null ? ($this->source->structMembers[$owner][$name] ?? null) : null;
    }

    private function arithmeticType(BinaryOp $op): ?string
    {
        $left  = $this->typeOf($op->left);
        $right = $this->typeOf($op->right);

        // Prefer the side that is not a bare literal: `$amount + 1` is the type of $amount.
        if ($op->left instanceof Scalar\Int_) {
            return $right ?? $left;
        }

        return $left ?? $right;
    }

    private function staticCallType(Expr\StaticCall $call): ?string
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $class = $call->class->getLast();
        $name  = $call->name->toString();

        if (isset(self::GLOBALS[$class][$name])) {
            return self::GLOBAL_TYPES[self::GLOBALS[$class][$name]];
        }

        if ($class === 'EVM') {
            return match ($name) {
                'keccak256'    => 'bytes32',
                'encodePacked', 'encode' => 'bytes',
                default        => null,
            };
        }

        $return = $this->source->methodReturns[$class][$name] ?? null;

        return $return === '' ? null : $return;
    }

    private function methodCallType(Expr\MethodCall $call): ?string
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $name = $call->name->toString();

        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            $return = $this->functionReturns[$name] ?? null;

            return $return === '' ? null : $return;
        }

        $targetType = $this->typeOf($call->var);

        if ($targetType === 'address' && $name === 'balance') {
            return 'uint256';
        }

        if ($targetType !== null) {
            $return = $this->source->methodReturns[$targetType][$name] ?? null;

            return $return === '' ? null : $return;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────

    private function fail(Node $node, string $message, ?string $hint = null): never
    {
        if ($hint !== null) {
            $message .= "\n  hint: {$hint}";
        }

        throw new CompileException($message, $node->getStartLine() > 0 ? $node->getStartLine() : null);
    }
}
