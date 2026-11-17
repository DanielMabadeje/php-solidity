# Validator rules

The validator runs before translation (`phpsolidity compile` and `phpsolidity validate` both use it, and so does `Transpiler` unless you disable it). Findings come in two kinds:

- **Errors** (`E...`) stop compilation. The PHP cannot be turned into valid Solidity, or would mean something dangerous.
- **Warnings** (`W...`) are printed but never stop compilation. They flag risky patterns.

Every finding has a stable code, a line number when one is known, and usually a hint:

```
contracts/Token.php:42  [E101] Blocked type 'float' in property $rate: Solidity has no floating-point type. ...
```

The validator reads the PHP syntax tree, so text inside strings and comments never triggers a rule.

## Errors

| Code | Meaning | Fix |
| --- | --- | --- |
| E000 | The file is not valid PHP | Fix the syntax error at the reported line |
| E001 | No `#[Contract]` class, library or interface in the file | Add `#[Contract]` above your class |
| E101 | A blocked type (`float`, `array`, `mixed`, `object`, `callable`, ...) | Use an EVM type. For arrays and mappings add `#[SolType]` or `#[Mapping]` |
| E102 | Nullable type (`?T`) | Use the plain type and its zero value |
| E103 | Union or intersection type | Use one type |
| E104 | `mapping` without key and value types | Add `#[Mapping('address', 'uint256')]` |
| E105 | A property or parameter with no type | Add an explicit type |
| E106 | Constructor property promotion | Declare the property, assign it in the constructor |
| E107 | Default parameter value | Remove it; Solidity has none |
| E108 | A function returns a value but declares no return type | Add a return type |
| E109 | `msg::value()` read in a function that is neither `#[Payable]` nor internal | Add `#[Payable]` |
| E201 | Closure or arrow function | Use a named method |
| E202 | `foreach` | Use `for` with `count()` |
| E203 | `try`, `catch` or `throw` | Use `EVM::revert()` or `EVM::revertWith()` |
| E204 | `switch` or `match` | Use `if` / `else if` |
| E205 | `echo`, `print`, `exit`, `include`, `eval`, inline HTML | Emit an event instead |
| E206 | A forbidden function or class: file, network, database, JSON, hashing helpers, `PDO`, `DateTime`, `Exception` | Contracts cannot do I/O. Use `EVM::keccak256()` for hashes |
| E207 | A PHP superglobal (`$_GET`, `$GLOBALS`, ...) | Use `msg::sender()` and friends |
| E208 | A float literal, or an integer too large for PHP | Scale integers by `10 ** 18`; build big numbers with `**` or shifts |
| E209 | `isset()` or `empty()` | Compare with the zero value |
| E210 | String interpolation | Concatenate with `.` |
| E211 | A PHP cast such as `(int)` | Use `uint256($x)`, `address($x)` |
| E212 | `global` or `static` variable | Use a state variable |
| E213 | Another unsupported expression: `??`, `<=>`, `instanceof`, `@`, `clone`, array literals | See the hint |
| E301 | Randomness (`rand`, `mt_rand`, `random_int`, `shuffle`, ...) | On-chain randomness must come from an oracle such as Chainlink VRF |
| E501 | `#[Constant]` without a value | Give it one: `#[Constant] private uint8 $decimals = 18;` |
| E502 | `#[Immutable]` assigned outside the constructor | Assign it only in the constructor, or drop `#[Immutable]` |
| E503 | A `#[Constant]` is assigned | Constants are fixed |
| E504 | `#[Constant]` and `#[Immutable]` together | Pick one |

## Warnings

| Code | Meaning | What to do |
| --- | --- | --- |
| W301 | PHP time function (`time()`, `date()`, ...) | Use `block::timestamp()` |
| W401 | A `public` method with no visibility attribute: it is emitted as `public` | Add `#[External]` for functions only called from outside |
| W601 | `tx::origin()` is used | Never use it for authorization; compare `msg::sender()` |
| W602 | A callable function changes state, has no `#[Guarded]`, and never reads `msg::sender()` | Add an access check, or ignore it if anyone is meant to call it |
| W603 | State changes **after** an external call (`transfer`, `call`, `EVM::sendValue`, `transferFrom`, ...) | Update state first, then call out (checks-effects-interactions), or add a reentrancy guard |
| W604 | `address->transfer()` or `->send()`, which forward only 2300 gas and can fail for contract wallets | Use `EVM::sendValue($to, $amount)` |
| W701 | A parameter clashes with a state variable or a reserved word and is emitted as `name_` | Rename it if you want a different ABI name |

The security warnings (W6xx) are **heuristics**. They find common patterns, not every vulnerability, and they can fire on code that is fine. A clean run does not mean a contract is safe. See [Security](security.md).

## Errors from the translator

Some problems only appear while translating a function body. They have no code, but they carry the file, the line and a hint, and they stop compilation:

```
contracts/Vault.php:23  Cannot infer the type of local variable $payout.
  hint: Add a docblock above the assignment, for example: /** @var uint256 */ $payout = ...;
```

Common ones:

| Message | Meaning |
| --- | --- |
| Cannot infer the type of local variable | Add a `/** @var type */` docblock |
| Variable $x is used before it is declared | Declared in an inner block, or never assigned |
| Unknown state variable $this->x | No such property on the contract |
| Function x() cannot be called inside a contract | Call your own methods with `$this->`; use `EVM::`, `msg::`, `block::` for EVM features |
| Unknown msg::x() | Not an EVM global; the message lists the valid ones |
| Unsupported statement / expression | Not translatable; the hint names the alternative |

## Using the validator from PHP

```php
use PhpSolidity\Validator\ContractValidator;

$validator = (new ContractValidator())->validate($source, throwOnError: false);

$validator->isValid();                    // bool
$validator->getDiagnostics();             // Diagnostic[] (code, severity, message, line, hint)
$validator->getErrors();                  // string[] "message (line 12)"
```

With `throwOnError: true` (the default) a `ValidationException` is thrown instead, and `$e->getDiagnostics()` returns everything found.

To add your own rule, see [Architecture](architecture.md#adding-a-validator-rule).
