# Contributing to PHP-Solidity

Thank you for your interest in contributing! PHP-Solidity is an open project and welcomes contributions of all kinds — bug fixes, new features, tests, documentation improvements, and example contracts.

---

## Table of Contents

- [Project Structure](#project-structure)
- [Setting Up Locally](#setting-up-locally)
- [How the Transpiler Works](#how-the-transpiler-works)
- [Running Tests](#running-tests)
- [Adding a New EVM Type](#adding-a-new-evm-type)
- [Adding a New Attribute](#adding-a-new-attribute)
- [Adding a Validator Rule](#adding-a-validator-rule)
- [Writing Example Contracts](#writing-example-contracts)
- [Submitting a Pull Request](#submitting-a-pull-request)
- [Code Standards](#code-standards)

---

## Project Structure

```
php-solidity/
├── bin/
│   └── phpsolidity              CLI entry point
├── examples/
│   └── MyToken.php              Example ERC-20 contract in PHP
├── src/
│   ├── AST/
│   │   └── Nodes/               One file per AST node class
│   │       ├── ContractNode.php
│   │       ├── FunctionNode.php
│   │       ├── StateVariableNode.php
│   │       ├── EventNode.php
│   │       ├── EventParamNode.php
│   │       ├── ModifierNode.php
│   │       └── ParameterNode.php
│   ├── Attributes/              One file per PHP Attribute class
│   │   ├── Contract.php
│   │   ├── Storage.php
│   │   ├── External.php
│   │   ├── View.php
│   │   ├── Modifier.php
│   │   ├── Guarded.php
│   │   └── ...
│   ├── Emitter/
│   │   └── SolidityEmitter.php  AST → Solidity string
│   ├── Exceptions/              One file per exception class
│   ├── Parser/
│   │   └── ContractParser.php   PHP source → AST (uses nikic/php-parser)
│   ├── TypeSystem/
│   │   └── TypeMapper.php       PHP types → Solidity types
│   ├── Validator/
│   │   └── ContractValidator.php Pre-compile checks
│   ├── Transpiler.php           Main entry point
│   └── TranspileResult.php      Value object for transpile output
├── stubs/
│   └── evm.php                  IDE stubs for EVM types (uint256, address, etc.)
├── tests/
│   ├── Unit/
│   └── Feature/
└── composer.json
```

**One class per file, always.** PSR-4 autoloading requires it.

---

## Setting Up Locally

```bash
git clone https://github.com/php-solidity/php-solidity
cd php-solidity
composer install
composer test
```

**Requirements:**
- PHP 8.1+
- Composer

---

## How the Transpiler Works

Understanding the pipeline is key before contributing:

### 1. `ContractValidator` — pre-flight checks

Before parsing, the raw PHP source string is scanned for obviously invalid patterns: blocked types (`float`, `array`), blocked functions (`rand()`, `eval()`), missing `#[Contract]` attribute, float literals in code, etc.

If validation fails, the process stops with a clear error message.

### 2. `ContractParser` — PHP → AST

`ContractParser::parseSource()` uses `nikic/php-parser` to parse the PHP source into a PHP AST. It then walks that AST looking for a class marked with `#[Contract]`.

From that class it extracts:
- Class-level attributes (`#[Contract]`, `#[SolidityEvent]`, `#[Inherits]`)
- Properties → `StateVariableNode[]`
- Methods → `FunctionNode[]` or `ModifierNode[]`
- Function bodies are translated from PHP syntax to Solidity syntax via `translateStatement()`

The result is a `ContractNode` — a clean, framework-agnostic representation of the contract.

### 3. `SolidityEmitter` — AST → Solidity

`SolidityEmitter::emit()` receives the `ContractNode` and walks it, building a Solidity source string. It handles:
- License and pragma header
- State variable declarations
- Event declarations
- Modifier declarations (with `_;` placeholder)
- Constructor and functions (signature + body)

### 4. `Transpiler` — orchestrator

`Transpiler` is the public entry point. It wires the parser and emitter together and provides `transpileFile()`, `transpile()`, and `transpileAndSave()`.

### 5. `TranspileResult` — value object

A simple readonly value object holding the contract name, Solidity source, the `ContractNode`, and optionally the output file path.

---

## Running Tests

```bash
# Run all tests
composer test

# Run a specific test file
./vendor/bin/phpunit tests/Unit/TypeMapperTest.php

# Run with coverage (requires Xdebug)
./vendor/bin/phpunit --coverage-text
```

When adding a feature, always add tests in `tests/Unit/` or `tests/Feature/`. Feature tests should exercise the full pipeline: PHP source in → Solidity string out.

---

## Adding a New EVM Type

**Example:** Adding `bytes16`.

**1. Add to `stubs/evm.php`:**
```php
/** Solidity: bytes16 */
class bytes16 {
    public function __construct(public readonly string $value = '0x') {}
    public function __toString(): string { return $this->value; }
}
```

Add its alias at the bottom:
```php
class_alias(bytes16::class, 'bytes16');
```

**2. Add to `src/TypeSystem/TypeMapper.php`:**
```php
private const MAP = [
    // ...existing...
    'bytes16' => 'bytes16',
];
```

**3. Add a test in `tests/Unit/TypeMapperTest.php`:**
```php
public function test_maps_bytes16(): void
{
    $mapper = new TypeMapper();
    $this->assertSame('bytes16', $mapper->toSolidity('bytes16'));
}
```

---

## Adding a New Attribute

**Example:** Adding `#[NonReentrant]` (a guard attribute).

**1. Create `src/Attributes/NonReentrant.php`:**
```php
<?php
declare(strict_types=1);
namespace PhpSolidity\Attributes;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class NonReentrant {}
```

**2. Handle it in `ContractParser::parseMethod()`:**

In `src/Parser/ContractParser.php`, inside `parseMethod()`, add detection:
```php
if ($this->hasAttribute($method, 'NonReentrant')) {
    $appliedModifiers[] = 'nonReentrant';
}
```

**3. The emitter will automatically include it** as a modifier name on the function signature.

**4. Document it** in README.md's Attributes Reference table.

---

## Adding a Validator Rule

All validation rules live in `src/Validator/ContractValidator.php`. Each rule is a private method called from `validate()`.

**Example:** Detecting use of `sleep()` (not valid on-chain):

**1. Add the method:**
```php
private function checkSleep(string $src): void
{
    if (preg_match('/\bsleep\s*\(/', $src)) {
        $this->errors[] = "sleep() is not valid in smart contracts. The EVM has no concept of time delays.";
    }
}
```

**2. Call it from `validate()`:**
```php
public function validate(string $phpSource, bool $throwOnError = true): self
{
    // ...existing calls...
    $this->checkSleep($phpSource);
    // ...
}
```

**3. Add a test** in `tests/Unit/ContractValidatorTest.php`.

---

## Writing Example Contracts

Example contracts live in `examples/`. They should:

- Be real-world useful (ERC-20, ERC-721, multisig, staking, etc.)
- Use only supported PHP-Solidity syntax
- Have a docblock explaining what the contract does and how to compile it
- Use `EVM::require()`, not PHP's `require`
- Not have a return type on the constructor
- Use `new uint8(value)` for typed integer literals, not bare ints

---

## Submitting a Pull Request

1. Fork the repository and create a branch: `git checkout -b feat/my-feature`
2. Make your changes — one concern per PR
3. Add or update tests
4. Run `composer test` and confirm everything passes
5. Update `README.md` if you added a public-facing feature
6. Open a PR with a clear title and description of what changed and why

---

## Code Standards

- **PSR-4 autoloading** — one class per file, filename matches class name exactly
- **PSR-12 code style** — 4-space indentation, opening braces on same line for control structures
- **Strict types** — every file must declare `strict_types=1`
- **Readonly where possible** — constructor-promoted properties and value objects should use `readonly`
- **No facades** — prefer direct instantiation over static entry points
- **Descriptive names** — `parseMethod()` not `pm()`, `SolidityEmitter` not `Emitter`
- **No multi-class files** — splitting is non-negotiable for autoloader compatibility

---

## Questions?

Open a GitHub Discussion or file an issue. We're happy to help contributors at any level.