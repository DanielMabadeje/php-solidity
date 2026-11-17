# Language reference

Everything you can write inside a PHP contract file, and the Solidity it becomes. Anything not listed here is either rejected by the [validator](validator.md) or reported by the translator with a line number.

- [File structure](#file-structure)
- [Types](#types)
- [State variables](#state-variables)
- [Functions](#functions)
- [Modifiers](#modifiers)
- [Events and errors](#events-and-errors)
- [Structs and enums](#structs-and-enums)
- [Interfaces and libraries](#interfaces-and-libraries)
- [Inheritance and imports](#inheritance-and-imports)
- [Globals and EVM helpers](#globals-and-evm-helpers)
- [Statements](#statements)
- [Expressions](#expressions)
- [Local variables](#local-variables)
- [Naming rules](#naming-rules)
- [Attribute quick reference](#attribute-quick-reference)

## File structure

One PHP file produces one `.sol` file. The file may hold several declarations, emitted in source order:

| PHP | Solidity |
| --- | --- |
| `#[Contract] class Token {}` | `contract Token {}` |
| `#[Contract] abstract class Base {}` | `abstract contract Base {}` |
| `interface IToken {}` | `interface IToken {}` |
| `#[Library] class MathLib {}` | `library MathLib {}` |
| `#[Struct] class Point {}` | `struct Point {}` |
| `enum Status { case Open; }` | `enum Status { Open }` |

The `.sol` file is named after the **primary** contract: the last `#[Contract]` class in the file, otherwise the last library, otherwise the last interface. The SPDX license and pragma come from that contract.

Solidity requires a base contract to be declared **before** the contracts that inherit from it, so write them in that order.

```php
#[Contract(name: 'Token', license: 'MIT', version: '^0.8.24')]
```

`#[Contract]` accepts `name` (default: the class name), `license` (default `MIT`) and `version` (default `^0.8.20`). `#[Library]` accepts the same.

## Types

| Write | Solidity | Notes |
| --- | --- | --- |
| `uint8` ... `uint256` | same | Every multiple of 8 |
| `int8` ... `int256` | same | Every multiple of 8 |
| `int` | `int256` | PHP's native `int` is mapped to a 256-bit signed integer, not a 64-bit one |
| `bool` | `bool` | |
| `address` | `address` | |
| `string` | `string` | Gets `memory` automatically in parameters, returns and locals |
| `bytes` | `bytes` | Same |
| `bytes1` ... `bytes32` | same | |
| `Name` (capitalised) | `Name` | A struct, enum, contract or interface name |
| `void` | no return | Use as a return type for functions that return nothing |

Rejected types: `float`, `double`, `array`, `object`, `mixed`, `null`, `callable`, `iterable`, `resource`, nullable types (`?T`) and union types (`A|B`). Each has a message explaining the alternative.

### Types PHP cannot express

PHP type declarations cannot say `uint256[]` or `mapping(address => uint256)`. Two attributes carry the Solidity type instead.

```php
#[Mapping('address', 'uint256')]
private mapping $balances;                  // mapping(address => uint256)

#[Mapping('address', 'mapping(address => uint256)')]
private mapping $allowances;               // nested mapping

#[SolType('uint256[]')]
private array $ids;                         // dynamic array

#[SolType('Contribution[]')]
private array $contributions;               // array of structs

#[SolType('address[3]')]
private array $signers;                     // fixed-size array
```

`#[SolType('...')]` can go on a property, a parameter or a method (where it overrides the return type) and its text is used as written. A docblock `/** @sol-type mapping(address => bool) */` on a property is accepted as an alternative.

An untyped `private mapping $x;` is an error (E104), as is an `array` without `#[SolType]`.

### Data locations

Solidity needs to know where reference types live. The transpiler adds `memory` for you on `string`, `bytes`, arrays and structs in parameters, return types and local variables. To get a storage pointer instead, give the local an explicit type in a [docblock](#local-variables).

## State variables

PHP properties become state variables.

```php
#[Storage(public: true)]
private uint256 $totalSupply;        // uint256 public totalSupply;

private address $owner;               // address private owner;
protected uint256 $fee;               // uint256 internal fee;
public bool $paused;                  // bool public paused;
```

| Declared as | Visibility |
| --- | --- |
| `#[Storage(public: true)]` or PHP `public` | `public` (adds a getter) |
| PHP `protected` | `internal` |
| PHP `private` | `private` |

Visibility is always written out in the Solidity. Initial values are emitted as written:

```php
#[Constant, Storage(public: true)]
private uint8 $decimals = 18;         // uint8 public constant decimals = 18;

#[Immutable]
private address $deployer;            // address private immutable deployer;
```

- `#[Constant]` requires a value (E501) and can never be assigned (E503).
- `#[Immutable]` may only be assigned in the constructor (E502).
- Property defaults must be plain PHP constant expressions, so write `18`, not `new uint8(18)`.

## Functions

```php
#[External, View]
public function balanceOf(address $account): uint256
{
    return $this->balances[$account];
}
```

**Visibility.** Choose with an attribute: `#[External]`, `#[Internal]`, `#[SolidityPublic]`, `#[SolidityPrivate]`. With none, the PHP keyword decides: `public` becomes `public` (and the validator warns, W401), `protected` becomes `internal`, `private` becomes `private`. In a library the default is `internal`. In an interface it is always `external`.

**Mutability.** `#[View]`, `#[Pure]`, `#[Payable]`. Reading `msg::value()` in a function that is not `#[Payable]` is an error (E109).

**Return type.** The PHP return type, or `#[SolType]` on the method. `void` and no return type both mean "returns nothing". A function that returns a value but declares no type is an error (E108).

**Special functions.**

| PHP | Solidity |
| --- | --- |
| `__construct()` or `#[Constructor]` | `constructor(...)` (add `#[Payable]` to make it payable) |
| `#[Receive]` method | `receive() external payable` |
| `#[Fallback]` method | `fallback() external` (add `#[Payable]` for `payable`) |

**Inheritance keywords.** `#[Virtual]` adds `virtual`, `#[Override]` adds `override`.

**Modifiers.** `#[Guarded('onlyOwner')]` applies one or more modifiers: `#[Guarded('onlyOwner', 'whenNotPaused')]`. The text is emitted verbatim, so `#[Guarded('onlyRole(ADMIN)')]` works.

Parameters need an explicit type and cannot have default values (E105, E107). Constructor property promotion is not supported (E106).

## Modifiers

A method marked `#[Modifier]` becomes a Solidity modifier. Its statements run before the guarded function, and the function body runs afterwards:

```php
#[Modifier]
protected function onlyOwner(): void
{
    EVM::require(msg::sender() == $this->owner, 'Ownable: caller is not the owner');
}
```

```solidity
modifier onlyOwner() {
    require(msg.sender == owner, "Ownable: caller is not the owner");
    _;
}
```

To run code *after* the function, put `EVM::placeholder();` where the function body should go (Solidity's `_;`):

```php
#[Modifier]
private function nonReentrant(): void
{
    EVM::require(! $this->locked, 'reentrant call');
    $this->locked = true;
    EVM::placeholder();
    $this->locked = false;
}
```

## Events and errors

Declare them on the class, then use them in functions.

```php
#[SolidityEvent('Transfer', ['address indexed from', 'address indexed to', 'uint256 value'])]
#[SolidityError('InsufficientBalance', ['uint256 available', 'uint256 requested'])]
class Token
{
    // ...
    Event::emit('Transfer', msg::sender(), $to, $amount);
    EVM::revertWith('InsufficientBalance', $balance, $amount);
}
```

```solidity
event Transfer(address indexed from, address indexed to, uint256 value);
error InsufficientBalance(uint256 available, uint256 requested);
// ...
emit Transfer(msg.sender, to, amount);
revert InsufficientBalance(balance, amount);
```

The first argument of `Event::emit` and `EVM::revertWith` must be a string literal. Both attributes can be repeated. Custom errors need Solidity 0.8.4 or newer.

## Structs and enums

```php
enum Status { case Open; case Funded; }

#[Struct]
class Contribution
{
    public address $backer;
    public uint256 $amount;
}
```

Use them like this:

```php
$this->status = Status::Open;                                        // status = Status.Open;
$this->contributions[] = new Contribution(msg::sender(), msg::value());
                                    // contributions.push(Contribution(msg.sender, msg.value));
$c = new Contribution(backer: $who, amount: 5);                      // Contribution({backer: who, amount: 5})
```

Backed enums (`enum X: int`) are not supported.

## Interfaces and libraries

A PHP `interface` becomes a Solidity `interface`. Mark read-only methods with `#[View]` or `#[Pure]`.

```php
interface IERC20
{
    #[View]
    public function balanceOf(address $account): uint256;

    public function transfer(address $to, uint256 $amount): bool;
}

#[Contract]
class Vault
{
    private IERC20 $token;

    #[Constructor]
    public function __construct(address $tokenAddress)
    {
        $this->token = IERC20($tokenAddress);                // conversion: token = IERC20(tokenAddress);
    }

    #[External]
    public function pull(uint256 $amount): void
    {
        EVM::require($this->token->transfer(msg::sender(), $amount), 'transfer failed');
    }
}
```

A `#[Library]` class holds `static` methods. Call them with `::`:

```php
#[Library]
class MathLib
{
    #[Pure]
    public static function min(uint256 $a, uint256 $b): uint256 { return $a < $b ? $a : $b; }
}

$smaller = MathLib::min($a, $b);          // uint256 smaller = MathLib.min(a, b);
```

A static call on any class that is not `msg`, `block`, `tx`, `address`, `EVM`, `Event` or an enum is emitted as a library call, which is how imported libraries are reached.

## Inheritance and imports

`extends` and `implements` become `is`. `#[Inherits('A', 'B')]` adds more, and `#[Import('path')]` writes an `import` line. Together they let you build on OpenZeppelin:

```php
#[Contract]
#[Import('@openzeppelin/contracts/access/Ownable.sol')]
#[Inherits('Ownable')]
class Vault {}
```

State variables and function return types from bases declared **in the same file** are visible to the type inference. Members of imported bases are not known to the tool, so a local variable initialised from one needs a [`@var` docblock](#local-variables).

## Globals and EVM helpers

| PHP | Solidity |
| --- | --- |
| `msg::sender()` | `msg.sender` |
| `msg::value()` | `msg.value` |
| `msg::data()` | `msg.data` |
| `msg::sig()` | `msg.sig` |
| `block::timestamp()` | `block.timestamp` |
| `block::number()` | `block.number` |
| `block::chainid()` | `block.chainid` |
| `block::basefee()`, `gaslimit()`, `coinbase()`, `prevrandao()` | `block.basefee` etc. |
| `tx::origin()` | `tx.origin` (the validator warns: never use it for authorization) |
| `tx::gasprice()` | `tx.gasprice` |
| `address::zero()` | `address(0)` |
| `address::self()` | `address(this)` |
| `$addr->balance()` | `addr.balance` |
| `$addr->transfer($n)`, `->send($n)` | `payable(addr).transfer(n)` (validator warns, W604) |

| Helper | Solidity |
| --- | --- |
| `EVM::require($cond, 'message')` | `require(cond, "message")` |
| `EVM::revert('message')` | `revert("message")` |
| `EVM::assert($cond)` | `assert(cond)` |
| `EVM::revertWith('Error', ...$args)` | `revert Error(args)` (statement) |
| `Event::emit('Event', ...$args)` | `emit Event(args)` (statement) |
| `EVM::sendValue($to, $amount)` | A checked `call{value: ...}` that reverts on failure (statement) |
| `EVM::keccak256(...$args)` | `keccak256(abi.encodePacked(args))` |
| `EVM::encodePacked(...$args)` | `abi.encodePacked(args)` |
| `EVM::encode(...$args)` | `abi.encode(args)` |
| `EVM::placeholder()` | `_;` inside a modifier (statement) |

Sending ETH is the safe pattern to use:

```php
EVM::sendValue(msg::sender(), $amount);
```

```solidity
{
    (bool _sent, ) = payable(msg.sender).call{value: amount}("");
    require(_sent, "PhpSolidity: ETH transfer failed");
}
```

Unlike `transfer`, this forwards all remaining gas, so update your state **before** calling it. The validator warns (W603) when you do not.

## Statements

| PHP | Solidity | Notes |
| --- | --- | --- |
| `$x = expr;` | `uint256 x = expr;` | First assignment declares a [local](#local-variables) |
| `$this->x = expr;` | `x = expr;` | |
| `$a[] = $v;` | `a.push(v);` | |
| `unset($m[$k]);` | `delete m[k];` | |
| `if / elseif / else` | `if / else if / else` | |
| `while`, `do { } while` | same | |
| `for ($i = 0; $i < $n; $i++)` | `for (uint256 i = 0; i < n; i++)` | One initialiser, one condition, one step |
| `break;`, `continue;` | same | `break 2;` is not supported |
| `return expr;` | `return expr;` | |

Not supported, each with a clear error: `foreach` (use `for` with `count()`), `switch`/`match` (use `if`), `try`/`catch`/`throw` (use `EVM::revert`), `echo` and friends (emit an event), `global`, `static` variables, closures.

## Expressions

| PHP | Solidity |
| --- | --- |
| `+ - * / %` | same |
| `**` | `**` |
| `& \| ^ ~ << >>` | same |
| `== ===` | `==` |
| `!= !==` | `!=` |
| `< <= > >=` | same |
| `&& \|\| and or !` | `&& \|\| && \|\| !` |
| `$a ? $b : $c` | `a ? b : c` |
| `$a . $b` | `string.concat(a, b)` (Solidity 0.8.12 or newer) |
| `$x .= $y` | `x = string.concat(x, y)` |
| `$x **= $y` | `x = x ** y` (Solidity has no `**=`) |
| `++ --` | same |
| `uint256($x)`, `address($x)`, `bytes32($x)`, `IToken($x)` | conversions |
| `new uint8(18)` | `18` |
| `count($arr)` | `arr.length` |

Nested binary operations are always parenthesised in the output. Not supported: float literals, `null`, array literals, casts like `(int)`, `??`, `?:`, `<=>`, `instanceof`, string interpolation, `isset`, `empty`.

Integer literals larger than PHP's `PHP_INT_MAX` cannot be written. Build them: `10 ** 18`, `1 << 200`.

## Local variables

The first assignment to a plain `$variable` declares it, and its type is inferred from the right-hand side: literals, parameters, state variables, mapping and array elements, struct members, arithmetic, comparisons, `msg::`/`block::` values, calls to your own functions, library calls with a declared return type, and conversions.

When the type cannot be inferred, the translator stops with an error. Add a docblock above the assignment:

```php
/** @var uint8 */
$small = 7;

/** @var Position storage */
$position = $this->positions[$id];       // a storage pointer, not a copy
```

Rules:

- A variable declared inside an `if`, `for` or `while` block exists only in that block, as in Solidity. Using it afterwards is an error that tells you to declare it earlier.
- An integer literal infers `uint256`, a negative one `int256`. Use `@var` for anything else.

## Naming rules

A parameter or local whose name collides with a state variable or a reserved Solidity word is emitted with a trailing underscore: parameter `$owner` becomes `owner_`, local `$default` becomes `default_`. References are renamed consistently. The validator reports W701 so the change is not a surprise. Rename the PHP parameter if you want a different name in the ABI.

## Attribute quick reference

All attributes live in `PhpSolidity\Attributes`.

| Attribute | Goes on | Effect |
| --- | --- | --- |
| `Contract(name, license, version)` | class | Declares a contract |
| `Library(name, license, version)` | class | Declares a library |
| `Struct` | class | Declares a struct |
| `Inherits(...names)` | class | Adds base contracts |
| `Import(path)` | class | Adds an `import` line (repeatable) |
| `SolidityEvent(name, params)` | class | Declares an event (repeatable) |
| `SolidityError(name, params)` | class | Declares a custom error (repeatable) |
| `Storage(public)` | property | `public: true` makes the variable public |
| `Constant`, `Immutable` | property | Mutability |
| `Mapping(key, value)` | property | Mapping type |
| `SolType(type)` | property, parameter, method | Explicit Solidity type |
| `External`, `Internal`, `SolidityPublic`, `SolidityPrivate` | method | Visibility |
| `View`, `Pure`, `Payable` | method | Mutability |
| `Constructor`, `Receive`, `Fallback` | method | Special functions |
| `Guarded(...modifiers)` | method | Applies modifiers |
| `Modifier` | method | Declares a modifier |
| `Virtual`, `Override` | method | Inheritance keywords |
