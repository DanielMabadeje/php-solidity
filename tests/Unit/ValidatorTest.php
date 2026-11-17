<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Exceptions\ValidationException;
use PhpSolidity\Tests\TestCase;
use PhpSolidity\Validator\ContractValidator;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidatorTest extends TestCase
{
    /** Each case: [description => [members of the contract class, expected code]] */
    /** @return array<string, array{string, string}> */
    public static function memberCases(): array
    {
        $fn = static fn (string $body, string $extra = '') => "    #[External{$extra}]\n    public function f(uint256 \$a): uint256\n    {\n        {$body}\n    }";

        return [
            'E101 float property'        => ['    private float $x;', 'E101'],
            'E101 mixed parameter'       => ["    #[External]\n    public function f(mixed \$a): void {}", 'E101'],
            'E101 array without type'    => ['    private array $items;', 'E101'],
            'E102 nullable'              => ['    private ?uint256 $x;', 'E102'],
            'E103 union'                 => ['    private uint256|bool $x;', 'E103'],
            'E104 mapping untyped'       => ['    private mapping $balances;', 'E104'],
            'E105 untyped property'      => ['    private $x;', 'E105'],
            'E105 untyped parameter'     => ["    #[External]\n    public function f(\$a): void {}", 'E105'],
            'E106 promotion'             => ["    #[Constructor]\n    public function __construct(private uint256 \$a) {}", 'E106'],
            'E107 default value'         => ["    #[External]\n    public function f(uint256 \$a = 5): void {}", 'E107'],
            'E108 value without type'    => ["    #[External]\n    public function f(): void\n    {\n    }\n\n    #[External]\n    public function g()\n    {\n        return 1;\n    }", 'E108'],
            'E109 msg.value'             => [$fn('return msg::value();'), 'E109'],
            'E201 closure'               => [$fn('$g = function () {}; return 0;'), 'E201'],
            'E201 arrow function'        => [$fn('$g = fn () => 1; return 0;'), 'E201'],
            'E202 foreach'               => [$fn('foreach ([1] as $x) {} return 0;'), 'E202'],
            'E203 try'                   => [$fn('try { return 1; } catch (\Exception $e) { return 0; }'), 'E203'],
            'E203 throw'                 => [$fn('throw new \Exception("x");'), 'E203'],
            'E204 switch'                => [$fn('switch ($a) { case 1: return 1; } return 0;'), 'E204'],
            'E205 echo'                  => [$fn('echo "hi"; return 0;'), 'E205'],
            'E205 print'                 => [$fn('print("hi"); return 0;'), 'E205'],
            'E205 eval'                  => [$fn('eval("1"); return 0;'), 'E205'],
            'E206 exec'                  => [$fn('exec("ls"); return 0;'), 'E206'],
            'E206 file io'               => [$fn('file_put_contents("x", "y"); return 0;'), 'E206'],
            'E206 curl'                  => [$fn('curl_init(); return 0;'), 'E206'],
            'E206 PDO'                   => [$fn('$db = new PDO("x"); return 0;'), 'E206'],
            'E207 superglobal'           => [$fn('return $_GET["a"];'), 'E207'],
            'E208 float literal'         => [$fn('return 1.5;'), 'E208'],
            'E208 huge integer'          => [$fn('return 0xFFFFFFFFFFFFFFFFFFFF;'), 'E208'],
            'E209 isset'                 => [$fn('return isset($a) ? 1 : 0;'), 'E209'],
            'E210 interpolation'         => [$fn('$s = "value {$a}"; return 0;'), 'E210'],
            'E211 cast'                  => [$fn('return (int) $a;'), 'E211'],
            'E212 static variable'       => [$fn('static $x = 1; return $x;'), 'E212'],
            'E213 null coalescing'       => [$fn('return $a ?? 1;'), 'E213'],
            'E213 array literal'         => [$fn('$x = [1]; return 0;'), 'E213'],
            'E301 rand'                  => [$fn('return rand(0, 10);'), 'E301'],
            'E301 mt_rand'               => [$fn('return mt_rand();'), 'E301'],
            'W301 time'                  => [$fn('return time();'), 'W301'],
            'E501 constant without value' => ["    #[Constant]\n    private uint256 \$x;", 'E501'],
            'E502 immutable reassigned'  => ["    #[Immutable]\n    private address \$o;\n\n    #[External]\n    public function f(): void\n    {\n        \$this->o = msg::sender();\n    }", 'E502'],
            'E503 constant assigned'     => ["    #[Constant]\n    private uint256 \$x = 1;\n\n    #[External]\n    public function f(): void\n    {\n        \$this->x = 2;\n    }", 'E503'],
            'E504 constant and immutable' => ["    #[Constant, Immutable]\n    private uint256 \$x = 1;", 'E504'],
            'W401 no visibility'         => ["    public function f(): uint256\n    {\n        return 1;\n    }", 'W401'],
            'W601 tx.origin'             => [$fn('EVM::require(tx::origin() == msg::sender()); return 0;'), 'W601'],
            'W602 no access check'       => ["    private uint256 \$x;\n\n    #[External]\n    public function set(uint256 \$v): void\n    {\n        \$this->x = \$v;\n    }", 'W602'],
            'W603 state after call'      => ["    private uint256 \$x;\n\n    #[External]\n    public function f(address \$to): void\n    {\n        EVM::sendValue(\$to, 1);\n        \$this->x = 0;\n        EVM::require(msg::sender() == \$to);\n    }", 'W603'],
            'W604 address transfer'      => ["    #[External]\n    public function f(address \$to): void\n    {\n        \$to->transfer(1);\n    }", 'W604'],
            'W701 shadowing'             => ["    private address \$owner;\n\n    #[External]\n    public function f(address \$owner): void {}", 'W701'],
        ];
    }

    #[DataProvider('memberCases')]
    public function testReportsProblems(string $members, string $expectedCode): void
    {
        $codes = $this->codes($this->contract($members));

        $this->assertContains($expectedCode, $codes, 'got: ' . implode(', ', $codes));
    }

    public function testReportsErrorsWithLineNumbers(): void
    {
        $php = $this->contract("    #[External]\n    public function f(): void\n    {\n        echo 'x';\n    }");

        $diagnostics = $this->diagnostics($php);
        $echo        = array_values(array_filter($diagnostics, static fn ($d) => $d->code === 'E205'));

        $this->assertCount(1, $echo);
        $this->assertSame(12, $echo[0]->line, 'the echo statement is on line 12 of the generated source');
    }

    public function testSyntaxErrorsAreReported(): void
    {
        $diagnostics = $this->diagnostics('<?php class { ');

        $this->assertSame('E000', $diagnostics[0]->code);
        $this->assertNotNull($diagnostics[0]->line);
    }

    public function testMissingContractIsAnError(): void
    {
        $this->assertSame(['E001'], $this->codes('<?php class Plain {}'));
    }

    public function testInterfacesAreAcceptedWithoutAContractClass(): void
    {
        $this->assertSame([], $this->codes("<?php\ninterface IThing { public function f(): uint256; }\n"));
    }

    public function testTextInsideStringsAndCommentsIsNotMistakenForCode(): void
    {
        // The old regex based validator flagged all of these.
        $php = $this->contract(
            "    // echo this, eval( that, float x, PDO, \$_GET and 0.8.20 are only comments\n" .
            "    #[External]\n    public function f(): string\n    {\n        return 'echo eval( float 1.5 PDO';\n    }",
            "#[SolidityEvent('Value', ['uint256 amount'])]",
        );

        $this->assertSame([], array_values(array_filter($this->codes($php), static fn ($c) => str_starts_with($c, 'E'))));
    }

    public function testAttributeArgumentsAreNotArrayLiterals(): void
    {
        $php = $this->contract('', "#[SolidityEvent('Transfer', ['address indexed from', 'uint256 value'])]");

        $this->assertSame([], $this->codes($php));
    }

    public function testTypeOverridesSilenceBlockedTypeErrors(): void
    {
        $php = $this->contract("    #[SolType('uint256[]')]\n    private array \$ids;\n\n    #[Mapping('address', 'uint256')]\n    private mapping \$balances;");

        $this->assertSame([], $this->codes($php));
    }

    public function testShippedExamplesHaveNoErrorsOrSurprises(): void
    {
        foreach (glob(__DIR__ . '/../../examples/*.php') ?: [] as $file) {
            $validator = (new ContractValidator())->validate((string) file_get_contents($file), throwOnError: false);
            $this->assertSame([], $validator->getErrors(), basename($file));
        }
    }

    public function testThrowsWithAllErrorsListed(): void
    {
        $php = $this->contract("    private float \$a;\n    private ?bool \$b;");

        try {
            (new ContractValidator())->validate($php);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('[E101]', $e->getMessage());
            $this->assertStringContainsString('[E102]', $e->getMessage());
            $this->assertCount(2, array_filter($e->getDiagnostics(), static fn ($d) => $d->isError()));
        }
    }

    public function testStringAccessorsKeepBackwardsCompatibility(): void
    {
        $validator = (new ContractValidator())->validate($this->contract('    private float $x;'), throwOnError: false);

        $this->assertFalse($validator->isValid());
        $this->assertStringContainsString("Blocked type 'float'", $validator->getErrors()[0]);
        $this->assertStringContainsString('(line', $validator->getErrors()[0]);
    }
}
