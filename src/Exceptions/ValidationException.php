<?php

declare(strict_types=1);

namespace PhpSolidity\Exceptions;

use PhpSolidity\Diagnostics\Diagnostic;

/** Thrown by the validator when a contract has one or more errors. */
class ValidationException extends PhpSolidityException
{
    /** @var Diagnostic[] */
    private array $diagnostics = [];

    /** @param Diagnostic[] $diagnostics */
    public static function fromDiagnostics(array $diagnostics): self
    {
        $errors  = array_values(array_filter($diagnostics, static fn (Diagnostic $d) => $d->isError()));
        $message = "Contract validation failed:\n\n";

        foreach ($errors as $i => $error) {
            $message .= '  [' . ($i + 1) . '] ' . $error->format() . "\n";
        }

        $exception              = new self($message);
        $exception->diagnostics = $diagnostics;

        return $exception;
    }

    /** @return Diagnostic[] */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
