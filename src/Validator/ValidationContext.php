<?php

declare(strict_types=1);

namespace PhpSolidity\Validator;

use PhpParser\Node;
use PhpSolidity\Diagnostics\Diagnostic;

/** Collects the findings of all rules for one source file. */
final class ValidationContext
{
    /** @var Diagnostic[] */
    private array $diagnostics = [];

    public function error(string $code, string $message, ?Node $node = null, ?string $hint = null): void
    {
        $this->diagnostics[] = Diagnostic::error($code, $message, $this->line($node), $hint);
    }

    public function warning(string $code, string $message, ?Node $node = null, ?string $hint = null): void
    {
        $this->diagnostics[] = Diagnostic::warning($code, $message, $this->line($node), $hint);
    }

    /** @return Diagnostic[] sorted by line, errors first on the same line */
    public function diagnostics(): array
    {
        $sorted = $this->diagnostics;

        usort($sorted, static function (Diagnostic $a, Diagnostic $b): int {
            return [$a->line ?? 0, $a->isError() ? 0 : 1, $a->code] <=> [$b->line ?? 0, $b->isError() ? 0 : 1, $b->code];
        });

        return $sorted;
    }

    private function line(?Node $node): ?int
    {
        $line = $node?->getStartLine();

        return $line !== null && $line > 0 ? $line : null;
    }
}
