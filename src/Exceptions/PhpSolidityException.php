<?php

declare(strict_types=1);

namespace PhpSolidity\Exceptions;

/**
 * Base class for every exception thrown by PHP-Solidity.
 *
 * Carries an optional source line so CLI output can point at the offending PHP code.
 */
class PhpSolidityException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly ?int $sourceLine = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }
}
