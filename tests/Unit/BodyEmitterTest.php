<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BodyEmitterTest extends TestCase
{
    /** @return array<string, array{string, string[]}> */
    public static function statements(): array
    {
        return [
            'addition'                   => ['return $a + $b;', ['return a + b;']],
            'associative chain stays flat' => ['return $a + $b + $a;', ['return a + b + a;']],
            'subtraction chain is grouped' => ['return $a - $b - $a;', ['return (a - b) - a;']],
            'precedence is explicit'     => ['return $a + $b * 2;', ['return a + (b * 2);']],
            'power is right associative' => ['return $a ** 2 ** 3;', ['return a ** (2 ** 3);']],
            'bit and vs comparison'      => ['return $a & $b == $a;', ['return a & (b == a);']],
            'strict equality'            => ['return $a === $b;', ['return a == b;']],
            'not equal'                  => ['return $a !== $b;', ['return a != b;']],
            'logical and/or'             => ['return $a > 1 && $b > 1 || $a == 0;', ['return ((a > 1) && (b > 1)) || (a == 0);']],
            'ternary'                    => ['return $a > $b ? $a : $b;', ['return (a > b) ? a : b;']],
            'negation'                   => ['return !($a > 1);', ['return !(a > 1);']],
            'hex literal'                => ['return 0xFF;', ['return 0xff;']],
            'increment'                  => ['$a++; --$b; return $a;', ['a++;', '--b;', 'return a;']],
            'compound assignment'        => ['$a += 5; $b <<= 2; return $a;', ['a += 5;', 'b <<= 2;', 'return a;']],
            'power assignment is expanded' => ['$a **= 2; return $a;', ['a = a ** 2;', 'return a;']],
            'local with inferred type'   => ['$x = $a * 2; return $x;', ['uint256 x = a * 2;', 'return x;']],
            'local reuse is assignment'  => ['$x = 1; $x = 2; return $x;', ['uint256 x = 1;', 'x = 2;', 'return x;']],
            'docblock sets the type'     => ["/** @var uint8 */\n \$x = 1;\n return \$x;", ['uint8 x = 1;', 'return x;']],
            'reserved word is renamed'   => ['$default = 1; return $default;', ['uint256 default_ = 1;', 'return default_;']],
            'require'                    => ['EVM::require($a > 0, "positive"); return $a;', ['require(a > 0, "positive");', 'return a;']],
            'require without message'    => ['EVM::require($a > 0); return $a;', ['require(a > 0);', 'return a;']],
            'revert'                     => ['EVM::revert("nope"); return 0;', ['revert("nope");', 'return 0;']],
            'string escapes'             => ['EVM::revert("say \"hi\"\n"); return 0;', ['revert("say \"hi\"\n");', 'return 0;']],
            'unicode string'             => ['EVM::revert("héllo"); return 0;', ['revert(unicode"héllo");', 'return 0;']],
            'globals'                    => ['return block::timestamp() + block::number();', ['return block.timestamp + block.number;']],
            'zero address'               => ['return $who == address::zero() ? 1 : 0;', ['return (who == address(0)) ? 1 : 0;']],
            'if / else if / else'        => [
                'if ($a == 0) { return 1; } elseif ($a == 1) { return 2; } else { return 3; }',
                ['if (a == 0) {', '    return 1;', '} else if (a == 1) {', '    return 2;', '} else {', '    return 3;', '}'],
            ],
            'while'                      => ['while ($a < 10) { $a++; } return $a;', ['while (a < 10) {', '    a++;', '}', 'return a;']],
            'do while'                   => ['do { $a++; } while ($a < 10); return $a;', ['do {', '    a++;', '} while (a < 10);', 'return a;']],
            'for declares its counter'   => ['for ($i = 0; $i < $a; $i++) { $b += $i; } return $b;', ['for (uint256 i = 0; i < a; i++) {', '    b += i;', '}', 'return b;']],
            'break and continue'         => ['while (true) { if ($a > 1) { break; } continue; } return 0;', ['while (true) {', '    if (a > 1) {', '        break;', '    }', '    continue;', '}', 'return 0;']],
            'string concatenation'       => ['EVM::revert("a" . "b"); return 0;', ['revert(string.concat("a", "b"));', 'return 0;']],
            'conversion'                 => ['return uint256(uint8($a));', ['return uint256(uint8(a));']],
            'new uint8 is a literal'     => ['$x = new uint8(7); return $x;', ['uint8 x = 7;', 'return x;']],
            'keccak256'                  => ['$h = EVM::keccak256($a, $b); return 0;', ['bytes32 h = keccak256(abi.encodePacked(a, b));', 'return 0;']],
        ];
    }

    /** @param string[] $expected */
    #[DataProvider('statements')]
    public function testTranslatesStatements(string $php, array $expected): void
    {
        $this->assertSame($expected, $this->bodyLines($php));
    }

    public function testStateVariablesAndParametersCannotCollide(): void
    {
        // `$total` and `$this->total` are different things in PHP but the same identifier in Solidity.
        $lines = $this->bodyLines(
            '$total = $a + 1; $this->total = $total; return $this->total;',
            members: '    private uint256 $total;',
        );

        $this->assertSame(['uint256 total_ = a + 1;', 'total = total_;', 'return total;'], $lines);
    }

    public function testParameterShadowingStateVariableIsRenamed(): void
    {
        $php = $this->contract(
            "    private address \$owner;\n\n    #[External]\n    public function setOwner(address \$owner): void\n    {\n        \$this->owner = \$owner;\n    }",
        );

        $this->assertStringContainsString("function setOwner(address owner_) external {\n        owner = owner_;", $this->solidity($php));
    }

    public function testReferenceTypesGetMemoryLocation(): void
    {
        $php = $this->contract(
            "    #[External]\n    public function f(string \$text): string\n    {\n        \$copy = \$text;\n        return \$copy;\n    }",
        );

        $out = $this->solidity($php);

        $this->assertStringContainsString('function f(string memory text) external returns (string memory)', $out);
        $this->assertStringContainsString('string memory copy = text;', $out);
    }

    public function testArrayAppendLengthAndStructAccess(): void
    {
        $php = <<<'PHP'
<?php
#[Struct]
class Point { public uint256 $x; public uint256 $y; }

#[Contract]
class T
{
    #[SolType('Point[]')]
    private array $points;

    #[External]
    public function add(uint256 $x): uint256
    {
        $this->points[] = new Point(x: $x, y: 2);
        $p = $this->points[0];
        return count($this->points) + $p->x;
    }
}
PHP;

        $out = $this->solidity($php);

        $this->assertStringContainsString('points.push(Point({x: x, y: 2}));', $out);
        $this->assertStringContainsString('Point memory p = points[0];', $out);
        $this->assertStringContainsString('return points.length + p.x;', $out);
    }

    public function testImportsAreEmittedAfterThePragma(): void
    {
        $php = "<?php\n#[Contract]\n#[Import('@openzeppelin/contracts/access/Ownable.sol')]\n#[Inherits('Ownable')]\nclass T {}\n";

        $this->assertStringContainsString(
            "pragma solidity ^0.8.20;\n\nimport \"@openzeppelin/contracts/access/Ownable.sol\";\n\ncontract T is Ownable {",
            $this->solidity($php),
        );
    }

    public function testNonPayableSendsUseCheckedCall(): void
    {
        $lines = $this->bodyLines('EVM::sendValue($who, $a); return 0;');

        $this->assertSame([
            '{',
            '    (bool _sent, ) = payable(who).call{value: a}("");',
            '    require(_sent, "PhpSolidity: ETH transfer failed");',
            '}',
            'return 0;',
        ], $lines);
    }

    public function testAddressTransferIsMadePayable(): void
    {
        $this->assertSame(['payable(who).transfer(a);', 'return 0;'], $this->bodyLines('$who->transfer($a); return 0;'));
    }

    public function testBalanceOfAnAddress(): void
    {
        $this->assertSame(['return who.balance;'], $this->bodyLines('return $who->balance();'));
    }

    /** @return array<string, array{string, string}> */
    public static function unsupported(): array
    {
        return [
            'foreach'            => ['foreach ($a as $x) { } return 0;', 'Unsupported statement'],
            'null'               => ['return null;', 'Null does not exist'],
            'float'              => ['return 1.5;', 'Floating point'],
            'array literal'      => ['$x = [1, 2]; return 0;', 'Array literals'],
            'cast'               => ['return (int) $a;', 'Casts'],
            'closure'            => ['$f = function () {}; return 0;', 'Closures'],
            'null coalescing'    => ['return $a ?? 1;', 'The ?? operator'],
            'short ternary'      => ['return $a ?: 1;', 'short ternary'],
            'unknown variable'   => ['return $missing;', 'used before it is declared'],
            'unknown function'   => ['return strlen("x");', 'cannot be called'],
            'unknown state var'  => ['return $this->nothing;', 'Unknown state variable'],
            'unknown msg member' => ['return msg::nonsense();', 'Unknown msg::nonsense()'],
            'undeduced local'    => ['$x = $this->helper(); return 0;', 'Cannot infer the type'],
            'multi-level break'  => ['while (true) { break 2; } return 0;', "'break N'"],
            'switch'             => ['switch ($a) { case 1: return 1; } return 0;', 'Unsupported statement'],
            'variable scope'     => ['if ($a > 1) { $inner = 1; } return $inner;', 'used before it is declared'],
        ];
    }

    #[DataProvider('unsupported')]
    public function testReportsUnsupportedConstructs(string $body, string $message): void
    {
        try {
            $this->solidity($this->withBody($body));
            $this->fail('Expected a CompileException');
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertNotNull($e->getSourceLine(), 'errors must carry a line number');
        }
    }
}
