<?php

declare(strict_types=1);

namespace PhpSolidity\Tests;

use PhpSolidity\Diagnostics\Diagnostic;
use PhpSolidity\Transpiler;
use PhpSolidity\Validator\ContractValidator;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Wrap members in a minimal #[Contract] class so tests only show what they are about.
     */
    protected function contract(string $members, string $attributes = ''): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n#[Contract]\n{$attributes}\nclass T\n{\n{$members}\n}\n";
    }

    /** A contract with one function `f(uint256 \$a, uint256 \$b, address \$who)` whose body is $body. */
    protected function withBody(string $body, string $returns = 'uint256', string $members = ''): string
    {
        $returnClause = $returns === '' ? '' : ": {$returns}";

        return $this->contract(
            "{$members}\n    #[External]\n    public function f(uint256 \$a, uint256 \$b, address \$who){$returnClause}\n    {\n{$body}\n    }",
        );
    }

    /** Transpile without running the validator, so emitter behaviour can be tested alone. */
    protected function solidity(string $php): string
    {
        return (new Transpiler(validate: false))->transpile($php)->soliditySource;
    }

    /**
     * The Solidity statements inside `function f(...)`, de-indented, as a list of lines.
     *
     * @return string[]
     */
    protected function bodyLines(string $body, string $returns = 'uint256', string $members = ''): array
    {
        $out = $this->solidity($this->withBody($body, $returns, $members));

        $this->assertMatchesRegularExpression('/function f\(.*?\{\n(.*?)\n    \}/s', $out);
        preg_match('/function f\(.*?\{\n(.*?)\n    \}/s', $out, $m);

        return array_map(static fn (string $l) => preg_replace('/^ {8}/', '', $l) ?? $l, explode("\n", $m[1]));
    }

    /** @return Diagnostic[] */
    protected function diagnostics(string $php): array
    {
        return (new ContractValidator())->validate($php, throwOnError: false)->getDiagnostics();
    }

    /** @return string[] codes in report order */
    protected function codes(string $php): array
    {
        return array_map(static fn (Diagnostic $d) => $d->code, $this->diagnostics($php));
    }
}
