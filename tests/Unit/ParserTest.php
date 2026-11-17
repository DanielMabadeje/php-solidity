<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\AST\Nodes\EnumNode;
use PhpSolidity\AST\Nodes\StructNode;
use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\Parser\ContractParser;
use PhpSolidity\Tests\TestCase;

final class ParserTest extends TestCase
{
    private function parse(string $php): \PhpSolidity\AST\Nodes\SourceUnitNode
    {
        return (new ContractParser())->parseSourceUnit($php);
    }

    public function testBuildsTheContractTree(): void
    {
        $unit = $this->parse(<<<'PHP'
<?php
#[Contract(name: 'Renamed', license: 'GPL-3.0', version: '^0.8.24')]
#[SolidityEvent('Changed', ['address indexed who', 'uint256 value'])]
#[SolidityError('Nope', ['uint256 code'])]
class T extends Base
{
    #[Mapping('address', 'uint256')]
    private mapping $balances;

    #[Immutable]
    public address $owner;

    #[External, View, Guarded('onlyOwner', 'whenOpen')]
    public function get(address $who): uint256 { return $this->balances[$who]; }
}
PHP);

        $contract = $unit->primary();

        $this->assertNotNull($contract);
        $this->assertSame('Renamed', $contract->name);
        $this->assertSame('GPL-3.0', $unit->license);
        $this->assertSame('^0.8.24', $unit->solidityVersion);
        $this->assertSame(['Base'], $contract->inherits);
        $this->assertSame('Changed', $contract->events[0]->name);
        $this->assertTrue($contract->events[0]->params[0]->indexed);
        $this->assertSame('Nope', $contract->errors[0]->name);

        $this->assertSame('mapping(address => uint256)', $contract->stateVariables[0]->solidityType);
        $this->assertSame('private', $contract->stateVariables[0]->visibility);
        $this->assertSame('public', $contract->stateVariables[1]->visibility);
        $this->assertSame('immutable', $contract->stateVariables[1]->mutability);

        $get = $contract->functions[0];
        $this->assertSame('external', $get->visibility);
        $this->assertSame('view', $get->mutability);
        $this->assertSame('uint256', $get->returnType);
        $this->assertSame(['onlyOwner', 'whenOpen'], $get->modifiers);
        $this->assertSame('address', $get->params[0]->solidityType);
    }

    public function testCollectsEnumsStructsInterfacesAndLibraries(): void
    {
        $unit = $this->parse(<<<'PHP'
<?php
enum Mode { case On; case Off; }
#[Struct] class Pair { public uint256 $a; public bool $b; }
interface IThing { public function ping(): bool; }
#[Library] class Lib { public static function id(uint256 $x): uint256 { return $x; } }
#[Contract] class Main {}
PHP);

        $kinds = array_map(static fn ($d) => $d instanceof EnumNode ? 'enum' : ($d instanceof StructNode ? 'struct' : $d->kind), $unit->declarations);

        $this->assertSame(['enum', 'struct', 'interface', 'library', 'contract'], $kinds);
        $this->assertSame('Main', $unit->primary()?->name);
        $this->assertSame(['On', 'Off'], $unit->declarations[0]->cases);
        $this->assertSame('internal', $unit->declarations[3]->functions[0]->visibility, 'library functions default to internal');
        $this->assertSame('external', $unit->declarations[2]->functions[0]->visibility, 'interface functions are external');
    }

    public function testPhpVisibilityIsUsedWhenNoAttributeIsGiven(): void
    {
        $unit = $this->parse("<?php\n#[Contract] class T {\n public function a(): void {}\n protected function b(): void {}\n private function c(): void {}\n}");

        $visibility = array_map(static fn ($f) => $f->visibility, $unit->primary()?->functions ?? []);

        $this->assertSame(['public', 'internal', 'private'], $visibility);
    }

    public function testPrimaryContractIsTheLastOneAndAbstractIsRecognised(): void
    {
        $unit = $this->parse("<?php\n#[Contract] abstract class Base {}\n#[Contract] class Child extends Base {}");

        $this->assertSame('Child', $unit->primary()?->name);
        $this->assertSame('abstract contract', $unit->contracts()[0]->kind);
    }

    public function testSolTypeOverridesAndDocblockFallback(): void
    {
        $unit = $this->parse(<<<'PHP'
<?php
#[Contract]
class T
{
    #[SolType('uint256[]')]
    private array $ids;

    /** @sol-type mapping(address => bool) */
    private mapping $flags;
}
PHP);

        $vars = $unit->primary()?->stateVariables ?? [];

        $this->assertSame('uint256[]', $vars[0]->solidityType);
        $this->assertSame('mapping(address => bool)', $vars[1]->solidityType);
    }

    public function testNamespacedFilesAreSupported(): void
    {
        $unit = $this->parse("<?php\nnamespace App\\Contracts;\n#[Contract] class Deep {}");

        $this->assertSame('Deep', $unit->primary()?->name);
    }

    public function testFilesWithoutAContractAreRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('No #[Contract] class found');
        $this->parse('<?php class Nothing {}');
    }

    public function testPhpSyntaxErrorsCarryALine(): void
    {
        try {
            $this->parse("<?php\n\nclass {");
            $this->fail('Expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame(3, $e->getSourceLine());
        }
    }

    public function testUntypedMappingIsAHelpfulError(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("#[Mapping('address', 'uint256')]");
        $this->parse("<?php\n#[Contract] class T { private mapping \$m; }");
    }

    public function testMissingTypesAreErrorsNotSilentUint256(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('has no type');
        $this->parse("<?php\n#[Contract] class T { #[External] public function f(\$x): void {} }");
    }

    public function testNullableTypesAreRejected(): void
    {
        $this->expectException(CompileException::class);
        $this->parse("<?php\n#[Contract] class T { private ?uint256 \$x; }");
    }

    public function testBackwardsCompatibleSingleContractApi(): void
    {
        $contract = (new ContractParser())->parseSource("<?php\n#[Contract] class Solo {}");

        $this->assertSame('Solo', $contract->name);
    }
}
