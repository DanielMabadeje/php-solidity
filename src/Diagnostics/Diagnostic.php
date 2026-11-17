<?php

declare(strict_types=1);

namespace PhpSolidity\Diagnostics;

/**
 * A single validator finding: an error (blocks compilation) or a warning.
 *
 * Codes are stable and documented in docs/validator.md.
 */
final class Diagnostic
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string  $severity,
        public readonly string  $code,
        public readonly string  $message,
        public readonly ?int    $line = null,
        public readonly ?string $hint = null,
    ) {}

    public static function error(string $code, string $message, ?int $line = null, ?string $hint = null): self
    {
        return new self(self::ERROR, $code, $message, $line, $hint);
    }

    public static function warning(string $code, string $message, ?int $line = null, ?string $hint = null): self
    {
        return new self(self::WARNING, $code, $message, $line, $hint);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** One-line human readable form, e.g. "MyToken.php:42  [E101] Blocked type 'float'". */
    public function format(?string $file = null): string
    {
        $where = '';
        if ($file !== null) {
            $where = $file . ($this->line !== null ? ':' . $this->line : '') . '  ';
        } elseif ($this->line !== null) {
            $where = 'line ' . $this->line . '  ';
        }

        $text = "{$where}[{$this->code}] {$this->message}";

        if ($this->hint !== null) {
            $text .= "\n      hint: {$this->hint}";
        }

        return $text;
    }
}
