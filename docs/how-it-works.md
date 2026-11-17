# How it works

PHP-Solidity is a **transpiler**: a program that reads source code in one language and writes equivalent source code in another. It never runs your PHP.

```
                 ┌─────────────┐   ┌──────────────┐   ┌───────────────┐
 Contract.php ──▶│  Validator  │──▶│    Parser    │──▶│    Emitter    │──▶ Contract.sol
                 └─────────────┘   └──────────────┘   └───────────────┘
                  checks PHP        PHP tree ──▶        Contract tree ──▶
                  against EVM       Contract tree       Solidity text
                  rules
                                                                              ┌──────┐
                                                        (optional `build`) ──▶│ solc │──▶ ABI + bytecode
                                                                              └──────┘
```

| Stage | Class | Input | Output |
| --- | --- | --- | --- |
| 1. Validate | `Validator\ContractValidator` and the rules in `Validator\Rules\` | PHP source | A list of errors and warnings with line numbers |
| 2. Parse | `Parser\ContractParser` | PHP source | A **Contract tree** (`SourceUnitNode`, `ContractNode`, `FunctionNode`, ...) |
| 3. Emit | `Emitter\SolidityEmitter` and `Emitter\BodyEmitter` | The Contract tree | Solidity source text |
| 4. Compile (optional) | `solc`, run by `phpsolidity build` | `.sol` file | ABI and bytecode |

`Transpiler` wires stages 1 to 3 together. If you use the library from PHP instead of the command line:

```php
$result = (new PhpSolidity\Transpiler())->transpileFile('contracts/Counter.php');

echo $result->soliditySource;   // the .sol text
$result->contractName;          // "Counter"
$result->warnings;              // Diagnostic[] from the validator
```

## Two trees, not one

A syntax tree (AST) is the structure a compiler builds from text so it can reason about it. [Understanding ASTs](understanding-asts.md) explains the idea from scratch. This project uses two:

1. **The PHP tree**, built by [nikic/php-parser](https://github.com/nikic/PHP-Parser). It describes a PHP *file*: classes, methods, `if` statements, operators. We do not write or maintain this parser.
2. **The Contract tree**, built by `ContractParser`. It describes a *contract*: state variables, events, modifiers, functions with a visibility and a mutability. It is deliberately small and knows nothing about PHP syntax.

Function bodies are the one exception. They stay as PHP statements inside the Contract tree and are translated, node by node, by `BodyEmitter` during emission. Translating a statement needs context (which variables are in scope, what type an expression has) that is only complete once the whole contract is known.

## Design decisions

**It walks a tree instead of editing text.** The first version of this project printed each PHP statement back to text and ran search-and-replace over it (`$this->x` to `x`, `$name` to `name`, and so on). That approach has failure modes that cannot be fixed, only patched: it rewrites `$` characters inside strings, it cannot tell a parameter named `$owner` from the state variable `$this->owner`, it cannot declare variables, and it does not know that PHP and Solidity group `&` and `==` differently. Working on the tree makes those problems solvable, and gives every error a line number.

**It fails loudly.** If a construct has no safe Solidity translation, the tool stops with an error and a hint. It does not guess. An unsupported `foreach` is a compile error, not a silently skipped loop.

**Output is explicit.** Visibility is always written out. Nested operations are always parenthesised, so the Solidity grouping can never differ from the PHP tree. Reference types always carry a data location.

**Output is deterministic.** The same input always produces the same bytes, which is what makes [golden-file tests](testing-and-ci.md) possible.

**It is a thin layer, not a new language.** Anything that already has a name in Solidity keeps that name. Types are `uint256`, not `Integer`. Where PHP syntax cannot express something (a mapping type, an array type), a small attribute carries the Solidity text through.

## What the validator does and does not do

The validator works on the PHP tree, so it reports accurate line numbers and cannot be fooled by words inside strings or comments. It checks that the PHP can exist on the EVM (no floats, no file access, no `foreach`) and gives security advice (reentrancy ordering, `tx.origin`). It does not type check your arithmetic and it does not know every Solidity rule. The final authority on whether the output is valid Solidity is `solc`, which is why CI [compiles every generated file](testing-and-ci.md#3-the-solc-check).
