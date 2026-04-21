<?php

declare(strict_types=1);

namespace PhpSolidity\Validator;

use PhpSolidity\Exceptions\ValidationException;

/**
 * Validates PHP contract source code against EVM constraints
 * BEFORE the transpiler runs, giving the developer clear, early errors.
 */
class ContractValidator
{
    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * Validate PHP source. Returns $this for chaining.
     * Call ->getErrors() after to check results.
     *
     * @throws ValidationException if $throwOnError is true
     */
    public function validate(string $phpSource, bool $throwOnError = true): self
    {
        $this->errors   = [];
        $this->warnings = [];

        $this->checkContractAttribute($phpSource);
        $this->checkBlockedTypes($phpSource);
        $this->checkBlockedConstructs($phpSource);
        $this->checkFloatingPoint($phpSource);
        $this->checkVisibility($phpSource);
        $this->checkFunctionReturns($phpSource);
        $this->checkEVMGlobals($phpSource);

        if ($throwOnError && ! empty($this->errors)) {
            $message = "Contract validation failed:\n\n";
            foreach ($this->errors as $i => $error) {
                $message .= "  [" . ($i + 1) . "] {$error}\n";
            }
            throw new ValidationException($message);
        }

        return $this;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    // ─────────────────────────────────────────────────────────
    // Validation Rules
    // ─────────────────────────────────────────────────────────

    private function checkContractAttribute(string $src): void
    {
        if (! str_contains($src, '#[Contract')) {
            $this->errors[] = "Missing #[Contract] attribute. Your contract class must be decorated with #[Contract].";
        }
    }

    private function checkBlockedTypes(string $src): void
    {
        $blocked = [
            'float'    => "float is not a valid EVM type. Use uint256 with fixed-point arithmetic (e.g. multiply by 1e18).",
            'double'   => "double is not a valid EVM type. Use uint256 with fixed-point arithmetic.",
            'mixed'    => "mixed is not allowed. All parameters and properties must be explicitly typed.",
            'object'   => "object type is not allowed. Use structs (value objects / inner classes) instead.",
            'resource' => "resource is not a valid type in smart contracts.",
            'callable' => "callable is not supported in smart contracts.",
            'Closure'  => "Closures are not supported in smart contracts.",
            'array'    => "PHP array is not allowed. Use mapping or a typed array (uint256[]) instead.",
        ];

        foreach ($blocked as $type => $explanation) {
            // Match type hints, not arbitrary occurrences inside strings
            if (preg_match('/(?::\s*' . preg_quote($type) . '|' . preg_quote($type) . '\s+\$)/i', $src)) {
                $this->errors[] = "Blocked type '{$type}': {$explanation}";
            }
        }
    }

    private function checkBlockedConstructs(string $src): void
    {
        $blocked = [
            'eval('       => "eval() is not allowed in smart contracts.",
            'exec('       => "exec() is not allowed. Smart contracts cannot call external processes.",
            'shell_exec(' => "shell_exec() is not allowed in smart contracts.",
            'file_get_contents(' => "File I/O is not allowed in smart contracts.",
            'file_put_contents(' => "File I/O is not allowed in smart contracts.",
            'curl_init('  => "Network calls are not allowed in smart contracts.",
            'mysqli_'     => "Database calls are not allowed in smart contracts.",
            'PDO'         => "Database calls (PDO) are not allowed in smart contracts.",
            '$_GET'       => "PHP superglobals are not allowed in smart contracts.",
            '$_POST'      => "PHP superglobals are not allowed in smart contracts.",
            '$_SESSION'   => "PHP superglobals are not allowed in smart contracts.",
            'header('     => "HTTP headers are not allowed in smart contracts.",
            'echo '       => "echo is not allowed in smart contracts.",
            'print('      => "print is not allowed in smart contracts.",
            'var_dump('   => "Debugging functions are not allowed in smart contracts.",
        ];

        foreach ($blocked as $construct => $explanation) {
            if (str_contains($src, $construct)) {
                $this->errors[] = "Blocked construct '{$construct}': {$explanation}";
            }
        }
    }

    private function checkFloatingPoint(string $src): void
    {
        // Strip string literals and comments before checking,
        // so version strings like '^0.8.20' don't false-positive.
        $stripped = preg_replace('/\/\/[^\n]*/', '', $src);         // // line comments
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $stripped);  // /* block comments */
        $stripped = preg_replace("/'[^']*'/", "''", $stripped);     // single-quoted strings
        $stripped = preg_replace('/"[^"]*"/', '""', $stripped);     // double-quoted strings

        // Now check for bare float literals in actual PHP code
        if (preg_match('/\b\d+\.\d+\b/', $stripped, $match)) {
            $this->errors[] =
                "Float literal '{$match[0]}' detected. " .
                "Solidity has no floating-point. Use integer arithmetic and scale by 1e18 (wei units) instead.";
        }
    }

    private function checkVisibility(string $src): void
    {
        // Functions without #[External], #[Internal], etc. get a warning
        preg_match_all('/public\s+function\s+(\w+)/', $src, $matches);
        foreach ($matches[1] as $fnName) {
            if ($fnName === '__construct') continue;

            // Check if the preceding lines have a visibility attribute
            $pattern = '/#\[(External|Internal|SolidityPrivate|SolidityPublic|Modifier)[^\]]*\][\s\S]{0,100}public\s+function\s+' . preg_quote($fnName) . '/';
            if (! preg_match($pattern, $src)) {
                $this->warnings[] =
                    "Function '{$fnName}' has no visibility attribute (#[External], #[Internal], etc.). " .
                    "It will default to 'public' in Solidity.";
            }
        }
    }

    private function checkFunctionReturns(string $src): void
    {
        // Functions with no return type hint that aren't void
        preg_match_all('/#\[External[^\]]*\][\s\S]{0,50}public\s+function\s+(\w+)\s*\([^)]*\)\s*\{/', $src, $matches);
        foreach ($matches[1] as $fnName) {
            $this->warnings[] =
                "Function '{$fnName}' has no return type hint. " .
                "Add ': void', ': uint256', ': bool', etc. for cleaner Solidity output.";
        }
    }

    private function checkEVMGlobals(string $src): void
    {
        // Warn if someone uses PHP's $_SERVER or time() instead of EVM equivalents
        if (str_contains($src, '$_SERVER')) {
            $this->errors[] = "Use msg::sender() instead of \$_SERVER for transaction context.";
        }

        if (preg_match('/\btime\(\)/', $src)) {
            $this->warnings[] =
                "PHP time() detected. Use block::timestamp() for on-chain time in smart contracts.";
        }

        if (preg_match('/\brand\(/', $src)) {
            $this->errors[] =
                "rand() is not safe in smart contracts — block data can be manipulated by miners. " .
                "Use a VRF (Verifiable Random Function) oracle like Chainlink VRF instead.";
        }

        if (preg_match('/\bmt_rand\(/', $src)) {
            $this->errors[] =
                "mt_rand() is not safe in smart contracts. Use Chainlink VRF for randomness.";
        }
    }

    // ─────────────────────────────────────────────────────────
    // Pretty-print result
    // ─────────────────────────────────────────────────────────

    public function dump(): void
    {
        if ($this->isValid()) {
            echo "✅  Validation passed";
            if (! empty($this->warnings)) {
                echo " (with " . count($this->warnings) . " warning(s))";
            }
            echo "\n";
        } else {
            echo "❌  Validation failed — " . count($this->errors) . " error(s)\n";
            foreach ($this->errors as $i => $error) {
                echo "    [E" . ($i + 1) . "] {$error}\n";
            }
        }

        if (! empty($this->warnings)) {
            echo "\n⚠️   Warnings:\n";
            foreach ($this->warnings as $i => $warn) {
                echo "    [W" . ($i + 1) . "] {$warn}\n";
            }
        }

        echo "\n";
    }
}
