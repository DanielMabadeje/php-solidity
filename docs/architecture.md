# Architecture

A map of the code, and recipes for the changes people most often want to make. Read [How it works](how-it-works.md) first.

## Code map

```
src/
├── Transpiler.php            wires validate → parse → emit
├── TranspileResult.php       what Transpiler returns
├── Attributes/               #[Contract], #[External], ... (inert markers; see below)
├── AST/Nodes/                the Contract tree
├── Parser/
│   ├── ContractParser.php    PHP tree → Contract tree
│   └── AttributeReader.php   reads attribute arguments from the PHP tree
├── Emitter/
│   ├── SolidityEmitter.php   Contract tree → file layout and signatures
│   ├── BodyEmitter.php       function bodies: statements, expressions, type inference
│   ├── Scope.php             local variables of the function being emitted
│   └── SourceContext.php     facts about the whole file (struct names, return types, ...)
├── TypeSystem/
│   ├── TypeMapper.php        PHP type name → Solidity type, data locations, mapping helpers
│   └── Naming.php            reserved words and collision renaming
├── Validator/
│   ├── ContractValidator.php runs the rules
│   ├── ValidationContext.php collects diagnostics
│   └── Rules/                one class per family of checks
├── Diagnostics/Diagnostic.php   an error or warning with code, line and hint
├── Config/Config.php         phpsolidity.json
├── Cli/                      Application (commands) and AstDumper
└── Exceptions/
stubs/evm.php                 IDE-only declarations of uint256, msg, EVM, ... (not autoloaded)
```

**The attribute classes in `src/Attributes/` are never instantiated.** The transpiler reads attributes from the syntax tree by *name*, as text. The classes exist so that editors and static analysers can resolve `#[External]`. This is why a contract file does not need `use` statements to be transpiled, and why adding an attribute means changing the parser, not the attribute class.

## Conventions

- `declare(strict_types=1)` everywhere; PHPStan level 8 must stay clean.
- PHP 8.1 is the minimum. No features newer than 8.1.
- Errors that the developer can fix carry a **line number** (`CompileException`'s second argument) and a **hint**.
- Emitter output must be deterministic and must not depend on array ordering that PHP does not guarantee.
- Anything that changes generated Solidity needs a golden fixture (see [Testing and CI](testing-and-ci.md)).

## Adding a type

Types live in `TypeSystem/TypeMapper.php`.

1. Teach `toSolidity()` to recognise it. Elementary types go through `isElementary()`; blocked types go in the `BLOCKED` constant with a message that says what to do instead.
2. If it needs a data location (`memory`), update `needsDataLocation()`.
3. If it can appear in an expression, teach `BodyEmitter::typeOf()` what produces it.
4. Add rows to `TypeMapperTest`, and a mention in `language-reference.md`.

## Adding an attribute

1. Create the class in `src/Attributes/` with the right `#[Attribute(Attribute::TARGET_...)]`. File name must match the class (PSR-4).
2. If it takes arguments, add its positional parameter names to `SIGNATURES` in `Parser/AttributeReader.php` so both `#[X('a')]` and `#[X(name: 'a')]` work.
3. Read it in `ContractParser`. Put the fact into a Contract tree node (add a field if needed).
4. Use it in `SolidityEmitter`.
5. If it can be misused, add a validator rule.
6. Add a fixture, a parser test, a `language-reference.md` entry, and a row in its attribute table.

## Adding a statement or expression

Statements are handled in `BodyEmitter::statement()`, expressions in `BodyEmitter::expr()`. Both are a single `match` on the node class.

1. Add an arm, and a method if it is more than one line. Translate children by calling `expr()` or `statements()`, never by building strings from PHP text.
2. For anything that can fail, call `$this->fail($node, 'message', 'hint')`; it records the line.
3. If the expression has a type, extend `typeOf()` so locals can be inferred from it.
4. Wrap nested operations with `operand()` unless the result is already atomic.
5. Add a row to the `statements()` data provider in `BodyEmitterTest` (PHP in, Solidity lines out), and to the `unsupported()` provider for each error you introduce.

**Statement-level macros** (`Event::emit`, `EVM::revertWith`, `EVM::sendValue`, `EVM::placeholder`) expand to several lines or a keyword that is not an expression. They live in `BodyEmitter::staticCallStatement()`.

**Adding an EVM global or helper.** Globals are the `GLOBALS` table (name, member, Solidity text) plus `GLOBAL_TYPES` for inference. Helpers are in `evmCall()`. Add the matching declaration to `stubs/evm.php` for editors.

## Adding a validator rule

1. Create `src/Validator/Rules/YourRule.php` extending `AbstractRule`.
2. Implement `check(array $ast, ValidationContext $context)`. `AbstractRule::walk()` visits every node except attribute groups; `contractClasses()` and `methodsOf()` find the parts you usually want.
3. Report with `$context->error('E###', 'message', $node, 'hint')` or `->warning('W###', ...)`. Pass the node so the line number is recorded.
4. Pick the next free code in the right family (see [Validator rules](validator.md)), and register the class in `ContractValidator::defaultRules()`.
5. Add a row to the `memberCases()` provider in `ValidatorTest`, and document the code.

A minimal rule that warns about `while (true)`:

```php
final class InfiniteLoopRule extends AbstractRule
{
    public function check(array $ast, ValidationContext $context): void
    {
        $this->walk($ast, static function (Node $node) use ($context): void {
            if ($node instanceof Stmt\While_
                && $node->cond instanceof Expr\ConstFetch
                && strtolower($node->cond->name->toString()) === 'true') {
                $context->warning('W801', 'while (true) can run out of gas.', $node, 'Bound the loop.');
            }
        });
    }
}
```

## Public API stability

Before 1.0 the API can change between minor versions, and every change is listed in `CHANGELOG.md`. The parts intended for use from other code are `Transpiler`, `TranspileResult`, `Validator\ContractValidator`, `Diagnostics\Diagnostic`, the exception classes and the CLI. The Contract tree and the emitters are internal.
