<?php

declare(strict_types=1);

namespace PhpSolidity\Cli;

use PhpParser\Node;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;
use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EnumNode;
use PhpSolidity\AST\Nodes\FunctionNode;
use PhpSolidity\AST\Nodes\SourceUnitNode;
use PhpSolidity\AST\Nodes\StructNode;

/**
 * Prints the two trees a contract goes through, for learning and debugging
 * (see docs/understanding-asts.md).
 */
final class AstDumper
{
    /** The raw PHP syntax tree produced by nikic/php-parser. */
    public function dumpPhp(string $source): string
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];

        return (new NodeDumper())->dump($ast) . "\n";
    }

    /** Our Contract IR as an indented tree. */
    public function dumpContract(SourceUnitNode $unit): string
    {
        $lines   = [];
        $lines[] = "SourceUnit (license: {$unit->license}, pragma: {$unit->solidityVersion})";

        foreach ($unit->imports as $import) {
            $lines[] = "├─ Import \"{$import}\"";
        }

        foreach ($unit->declarations as $declaration) {
            if ($declaration instanceof EnumNode) {
                $lines[] = "├─ Enum {$declaration->name} [" . implode(', ', $declaration->cases) . ']';
            } elseif ($declaration instanceof StructNode) {
                $lines[] = "├─ Struct {$declaration->name}";
                foreach ($declaration->members as $member) {
                    $lines[] = "│   └─ Member {$member->solidityType} {$member->name}";
                }
            } else {
                $this->contract($declaration, $lines);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param string[] $lines */
    private function contract(ContractNode $contract, array &$lines): void
    {
        $inherits = $contract->inherits === [] ? '' : ' is ' . implode(', ', $contract->inherits);
        $lines[]  = "├─ " . ucfirst($contract->kind) . " {$contract->name}{$inherits}";

        foreach ($contract->stateVariables as $v) {
            $flags   = trim($v->visibility . ' ' . $v->mutability);
            $default = $v->defaultValue !== null ? ' = <' . $v->defaultValue->getType() . '>' : '';
            $lines[] = "│   ├─ StateVariable {$v->solidityType} {$v->name} ({$flags}){$default}";
        }
        foreach ($contract->events as $e) {
            $lines[] = "│   ├─ Event {$e->name}(" . implode(', ', array_map(static fn ($p) => $p->solidityType . ($p->indexed ? ' indexed' : '') . ' ' . $p->name, $e->params)) . ')';
        }
        foreach ($contract->errors as $e) {
            $lines[] = "│   ├─ Error {$e->name}(" . implode(', ', array_map(static fn ($p) => $p->solidityType . ' ' . $p->name, $e->params)) . ')';
        }
        foreach ($contract->modifiers as $m) {
            $lines[] = "│   ├─ Modifier {$m->name} [" . count($m->body) . ' statement(s)]';
        }
        foreach ($contract->functions as $f) {
            $lines[] = '│   └─ ' . $this->function($f);
            foreach ($f->body ?? [] as $stmt) {
                $lines[] = '│       · ' . $this->statementSummary($stmt);
            }
        }
    }

    private function function(FunctionNode $f): string
    {
        $params = implode(', ', array_map(static fn ($p) => $p->solidityType . ' ' . $p->name, $f->params));
        $flags  = trim("{$f->visibility} {$f->mutability} " . implode(' ', $f->modifiers));
        $return = $f->returnType !== '' ? " returns {$f->returnType}" : '';

        return ucfirst($f->kind) . " {$f->name}({$params}) [{$flags}]{$return}";
    }

    private function statementSummary(Node $stmt): string
    {
        $printer = new \PhpParser\PrettyPrinter\Standard();
        $first   = strtok($printer->prettyPrint([$stmt]), "\n");
        $text    = $first === false ? '' : trim($first);

        return str_pad($stmt->getType(), 16) . ' ' . $text;
    }
}
