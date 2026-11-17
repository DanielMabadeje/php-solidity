<?php

declare(strict_types=1);

namespace PhpSolidity\Validator;

use PhpParser\ParserFactory;
use PhpSolidity\Diagnostics\Diagnostic;
use PhpSolidity\Exceptions\ValidationException;
use PhpSolidity\Validator\Rules;

/**
 * Checks PHP contract source against EVM constraints BEFORE the transpiler runs.
 *
 * It works on the PHP syntax tree (not on text), so findings carry accurate line numbers and
 * code inside comments or strings can never cause false positives.
 */
class ContractValidator
{
    /** @var Diagnostic[] */
    private array $diagnostics = [];

    /** @param Rules\RuleInterface[]|null $rules */
    public function __construct(private readonly ?array $rules = null) {}

    /**
     * Validate PHP source. Returns $this for chaining.
     *
     * @throws ValidationException when $throwOnError is true and there are errors
     */
    public function validate(string $phpSource, bool $throwOnError = true): self
    {
        $context = new ValidationContext();

        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($phpSource) ?? [];

            foreach ($this->rules ?? self::defaultRules() as $rule) {
                $rule->check($ast, $context);
            }
        } catch (\PhpParser\Error $e) {
            $context->error('E000', 'PHP syntax error: ' . $e->getRawMessage(), null);
            $this->diagnostics = [Diagnostic::error('E000', 'PHP syntax error: ' . $e->getRawMessage(), $e->getStartLine() > 0 ? $e->getStartLine() : null)];

            if ($throwOnError) {
                throw ValidationException::fromDiagnostics($this->diagnostics);
            }

            return $this;
        }

        $this->diagnostics = $context->diagnostics();

        if ($throwOnError && ! $this->isValid()) {
            throw ValidationException::fromDiagnostics($this->diagnostics);
        }

        return $this;
    }

    /** @return Rules\RuleInterface[] */
    public static function defaultRules(): array
    {
        return [
            new Rules\ContractPresenceRule(),
            new Rules\TypeRule(),
            new Rules\ConstructRule(),
            new Rules\RandomnessRule(),
            new Rules\StateRule(),
            new Rules\FunctionRule(),
            new Rules\SecurityRule(),
            new Rules\NamingRule(),
        ];
    }

    public function isValid(): bool
    {
        return $this->getErrorDiagnostics() === [];
    }

    /** @return Diagnostic[] */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return Diagnostic[] */
    public function getErrorDiagnostics(): array
    {
        return array_values(array_filter($this->diagnostics, static fn (Diagnostic $d) => $d->isError()));
    }

    /** @return Diagnostic[] */
    public function getWarningDiagnostics(): array
    {
        return array_values(array_filter($this->diagnostics, static fn (Diagnostic $d) => ! $d->isError()));
    }

    /** @return string[] error messages, each ending with its line number when known */
    public function getErrors(): array
    {
        return array_map([$this, 'plain'], $this->getErrorDiagnostics());
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return array_map([$this, 'plain'], $this->getWarningDiagnostics());
    }

    private function plain(Diagnostic $d): string
    {
        return $d->message . ($d->line !== null ? " (line {$d->line})" : '');
    }
}
