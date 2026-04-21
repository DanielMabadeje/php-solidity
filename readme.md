# PHP-Solidity 🔷

**Write Ethereum smart contracts in PHP.**

PHP-Solidity is a PHP-to-Solidity transpiler. You write smart contracts using familiar PHP 8 class syntax — with typed properties, attributes, and static method calls — and PHP-Solidity compiles them into valid Solidity source code ready to deploy on any EVM-compatible blockchain (Ethereum, Polygon, BSC, Arbitrum, etc.).

> PHP does not *run* on the blockchain. PHP-Solidity uses PHP purely as a **syntax layer**. Your contract file is read, parsed into an AST, and emitted as `.sol` — exactly like how TypeScript compiles to JavaScript.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Writing Your First Contract](#writing-your-first-contract)
- [EVM Types](#evm-types)
- [Attributes Reference](#attributes-reference)
- [EVM Globals](#evm-globals)
- [Examples](#examples)
- [CLI Reference](#cli-reference)
- [Programmatic API](#programmatic-api)
- [Validator](#validator)
- [Limitations](#limitations)
- [After Transpiling](#after-transpiling)

---

## How It Works

```
Your PHP Contract File
        │
        ▼
 ContractValidator      checks for blocked types, unsafe constructs
        │
        ▼
 ContractParser         reads PHP with nikic/php-parser → builds AST
        │
        ▼
 AST (ContractNode)     internal representation of the contract
        │
        ▼
 SolidityEmitter        walks the AST and outputs Solidity source
        │
        ▼
   MyContract.sol       valid Solidity, ready for solc / Hardhat / Foundry
```

---

## Installation

```bash
composer require php-solidity/php-solidity
```

**Requirements:** PHP 8.1+

---

## Quick Start

**1. Create your contract file:**

```php
<?php
// contracts/Counter.php

use PhpSolidity\Attributes\Contract;
use PhpSolidity\Attributes\Storage;
use PhpSolidity\Attributes\External;
use PhpSolidity\Attributes\View;

#[Contract]
class Counter
{
    #[Storage(public: true)]
    private uint256 $count;

    #[External]
    public function increment(): void
    {
        $this->count += 1;
    }

    #[External, View]
    public function getCount(): uint256
    {
        return $this->count;
    }
}
```

**2. Compile it:**

```bash
php bin/phpsolidity compile contracts/Counter.php
```

**3. Output — `Counter.sol`:**

```solidity
// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Counter {

    uint256 public count;

    function increment() external {
        count += 1;
    }

    function getCount() external view returns (uint256) {
        return count;
    }
}
```

---

## Writing Your First Contract

### Step 1 — Create a PHP file and mark the class

Every contract starts with a class decorated with `#[Contract]`:

```php
<?php

use PhpSolidity\Attributes\Contract;

#[Contract(license: 'MIT', version: '^0.8.20')]
class MyContract
{
}
```

### Step 2 — Declare state variables

State variables are properties marked with `#[Storage]`. Use EVM types, not PHP's native types:

```php
#[Storage(public: true)]   // generates a public getter in Solidity
private uint256 $totalSupply;

#[Storage]                 // private by default
private mapping $balances;

#[Immutable]               // set once in constructor, read-only after
private address $owner;

#[Constant]                // compile-time constant
private uint8 $decimals;
```

### Step 3 — Write the constructor

Mark `__construct` with `#[Constructor]`. Do not declare a return type on constructors:

```php
#[Constructor]
public function __construct(uint256 $initialSupply)
{
    $this->owner       = msg::sender();
    $this->decimals    = new uint8(18);
    $this->totalSupply = $initialSupply;
    $this->balances[msg::sender()] = $initialSupply;
}
```

### Step 4 — Write functions

Mark each function with its Solidity visibility and mutability attributes:

```php
// Read-only function
#[External, View]
public function balanceOf(address $account): uint256
{
    return $this->balances[$account];
}

// State-changing function
#[External]
public function transfer(address $to, uint256 $amount): bool
{
    EVM::require($this->balances[msg::sender()] >= $amount, "Insufficient balance");
    $this->balances[msg::sender()] -= $amount;
    $this->balances[$to]           += $amount;
    Event::emit('Transfer', msg::sender(), $to, $amount);
    return true;
}
```

### Step 5 — Declare events

Events are declared as class-level attributes:

```php
#[Contract]
#[SolidityEvent('Transfer', ['address indexed from', 'address indexed to', 'uint256 value'])]
#[SolidityEvent('Approval', ['address indexed owner', 'address indexed spender', 'uint256 value'])]
class MyToken { ... }
```

### Step 6 — Add modifiers

Declare a modifier method with `#[Modifier]`, then apply it with `#[Guarded(...)]`:

```php
#[Modifier]
private function onlyOwner(): void
{
    EVM::require(msg::sender() == $this->owner, "Not the owner");
}

#[External, Guarded('onlyOwner')]
public function mint(address $to, uint256 $amount): void
{
    $this->totalSupply   += $amount;
    $this->balances[$to] += $amount;
}
```

### Step 7 — Compile

```bash
# Output to current directory
php bin/phpsolidity compile contracts/MyToken.php

# Output to a specific folder
php bin/phpsolidity compile contracts/MyToken.php --output ./solidity
```

---

## EVM Types

Use these types in your PHP contracts. **Do not use PHP's native `float`, `array`, or `mixed`** — they will be rejected at compile time.

| PHP-Solidity | Solidity | Notes |
|---|---|---|
| `uint256` | `uint256` | Unsigned 256-bit integer |
| `uint128` | `uint128` | Unsigned 128-bit integer |
| `uint64` | `uint64` | |
| `uint32` | `uint32` | |
| `uint16` | `uint16` | |
| `uint8` | `uint8` | Use `new uint8(value)` for literals |
| `int256` | `int256` | Signed 256-bit integer |
| `int128` | `int128` | |
| `address` | `address` | 20-byte Ethereum address |
| `bool` | `bool` | Boolean |
| `bytes32` | `bytes32` | Fixed 32-byte array |
| `bytes` | `bytes` | Dynamic byte array |
| `string` | `string` | UTF-8 string |
| `mapping` | `mapping(...)` | Key-value storage |

**Blocked types** — these throw a compile-time error:
`float`, `double`, `array`, `mixed`, `object`, `resource`, `callable`, `Closure`

---

## Attributes Reference

### Class-level

| Attribute | Description |
|---|---|
| `#[Contract]` | Marks the class as a contract. Accepts `name`, `license`, `version`. |
| `#[SolidityEvent('Name', [params])]` | Declares an event. Repeatable. |
| `#[Inherits(ParentContract::class)]` | Contract inheritance. |

### Property-level

| Attribute | Description |
|---|---|
| `#[Storage]` | Private state variable. |
| `#[Storage(public: true)]` | Public state variable with auto-getter. |
| `#[Immutable]` | Set once in constructor, read-only after deployment. |
| `#[Constant]` | Compile-time constant. |

### Method-level

| Attribute | Description |
|---|---|
| `#[Constructor]` | Marks the constructor method. |
| `#[External]` | Callable from outside the contract. |
| `#[Internal]` | Callable only from within the contract or children. |
| `#[SolidityPublic]` | Public visibility. |
| `#[SolidityPrivate]` | Private visibility. |
| `#[View]` | Reads state but does not modify it. |
| `#[Pure]` | Does not read or modify state. |
| `#[Payable]` | Can receive Ether. |
| `#[Modifier]` | Declares a Solidity modifier. |
| `#[Guarded('modifierName')]` | Applies a modifier to a function. |

---

## EVM Globals

These replace PHP's globals and built-ins inside contract files:

```php
// Transaction context
msg::sender()           // address of the caller       → msg.sender
msg::value()            // ETH sent with the call      → msg.value
msg::data()             // raw calldata                → msg.data

// Block context
block::timestamp()      // current block timestamp     → block.timestamp
block::number()         // current block number        → block.number
block::chainid()        // chain ID                    → block.chainid

// Transaction origin
tx::origin()            // original transaction sender → tx.origin

// Address utilities
address::zero()         // the zero address            → address(0)

// Events
Event::emit('Transfer', $from, $to, $amount)   // → emit Transfer(from, to, amount)

// Guards (replaces PHP's require/assert)
EVM::require($condition, "Error message")       // → require(condition, "message")
EVM::revert("Error message")                    // → revert("message")
EVM::assert($condition)                         // → assert(condition)
```

> **Why `EVM::require()` instead of `require()`?**
> PHP treats `require` as a language construct, not a function. You cannot shadow it. `EVM::require()` is the PHP-Solidity equivalent and transpiles directly to Solidity's `require()`.

---

## Examples

### Simple Storage Contract

```php
<?php
use PhpSolidity\Attributes\{Contract, Storage, External, View};

#[Contract]
class SimpleStorage
{
    #[Storage]
    private uint256 $value;

    #[External]
    public function set(uint256 $newValue): void
    {
        $this->value = $newValue;
    }

    #[External, View]
    public function get(): uint256
    {
        return $this->value;
    }
}
```

### Ownable Pattern

```php
<?php
use PhpSolidity\Attributes\{Contract, Immutable, Constructor, External, Modifier, Guarded};

#[Contract]
class Ownable
{
    #[Immutable]
    private address $owner;

    #[Constructor]
    public function __construct()
    {
        $this->owner = msg::sender();
    }

    #[Modifier]
    private function onlyOwner(): void
    {
        EVM::require(msg::sender() == $this->owner, "Not the owner");
    }

    #[External, Guarded('onlyOwner')]
    public function sensitiveAction(): void
    {
        // Only the contract owner can call this
    }
}
```

### Payable Vault

```php
<?php
use PhpSolidity\Attributes\{Contract, Storage, External, Payable};

#[Contract]
class Vault
{
    #[Storage]
    private mapping $deposits;

    #[External, Payable]
    public function deposit(): void
    {
        $this->deposits[msg::sender()] += msg::value();
    }

    #[External]
    public function withdraw(uint256 $amount): void
    {
        EVM::require($this->deposits[msg::sender()] >= $amount, "Insufficient balance");
        $this->deposits[msg::sender()] -= $amount;
        msg::sender()->transfer($amount);
    }
}
```

### ERC-20 Token

See [`examples/MyToken.php`](examples/MyToken.php) for a full ERC-20 implementation with events, modifiers, mint, burn, and ownership transfer.

---

## CLI Reference

```bash
# Compile a contract
php bin/phpsolidity compile <file.php>

# Compile and save output to a directory
php bin/phpsolidity compile <file.php> --output ./solidity

# Validate only — no Solidity output generated
php bin/phpsolidity validate <file.php>

# Show help
php bin/phpsolidity help
```

After running `composer install`, you can also use:

```bash
./vendor/bin/phpsolidity compile contracts/MyToken.php
```

Or add to your PATH for global usage:

```bash
# In ~/.zshrc or ~/.bashrc
export PATH="$PATH:/path/to/php-solidity/vendor/bin"
```

---

## Programmatic API

```php
use PhpSolidity\Transpiler;

$transpiler = new Transpiler();

// Transpile from a file
$result = $transpiler->transpileFile('contracts/MyToken.php');

// Transpile from a source string
$result = $transpiler->transpile($phpSourceCode);

// Transpile and write .sol file to disk
$result = $transpiler->transpileAndSave('contracts/MyToken.php', './solidity');

// Access the output
echo $result->soliditySource;   // the full Solidity source string
echo $result->contractName;     // "MyToken"
echo $result->outputPath;       // "./solidity/MyToken.sol" (if saved)
```

You can also use the lower-level components directly:

```php
use PhpSolidity\Parser\ContractParser;
use PhpSolidity\Emitter\SolidityEmitter;
use PhpSolidity\Validator\ContractValidator;

// Validate before compiling
$validator = new ContractValidator();
$validator->validate($phpSource, throwOnError: false);

if (! $validator->isValid()) {
    print_r($validator->getErrors());
}

// Parse PHP → AST
$parser       = new ContractParser();
$contractNode = $parser->parseSource($phpSource);

// Emit AST → Solidity
$emitter      = new SolidityEmitter();
$solidity     = $emitter->emit($contractNode);
```

---

## Validator

The validator runs automatically before every compile and catches problems early:

```
✅  Validation passed

❌  Validation failed — 3 error(s)
    [E1] Blocked type 'float': Solidity has no floating-point type.
    [E2] Blocked construct 'rand()': Not safe — use Chainlink VRF.
    [E3] Missing #[Contract] attribute.

⚠️  Warnings:
    [W1] PHP time() detected. Use block::timestamp() instead.
```

**What the validator catches:**

- Missing `#[Contract]` attribute
- Blocked PHP types (`float`, `mixed`, `array`, etc.)
- Blocked PHP constructs (`eval`, `exec`, `file_get_contents`, superglobals, etc.)
- Float literals in code
- Unsafe randomness (`rand()`, `mt_rand()`)
- PHP time functions instead of EVM equivalents

---

## Limitations

PHP-Solidity transpiles a strict subset of PHP. The following are intentionally unsupported:

| Unsupported | Reason |
|---|---|
| `float` / `double` | EVM has no floating-point |
| PHP `array` | Use `mapping` or typed arrays (`uint256[]`) |
| Closures / anonymous functions | No equivalent in Solidity |
| `rand()` / `mt_rand()` | Unsafe on-chain — use Chainlink VRF |
| PHP I/O (`file_get_contents`, `curl`) | No I/O in smart contracts |
| `$_GET`, `$_POST`, superglobals | No HTTP context on-chain |
| `eval()`, `exec()` | Blocked for security |
| `require()` built-in | Shadowed by `EVM::require()` |

---

## After Transpiling

Once you have your `.sol` file, use standard Solidity tooling:

```bash
# Hardhat
npm install --save-dev hardhat
npx hardhat compile
npx hardhat run scripts/deploy.js --network mainnet

# Foundry
forge build
forge test
forge deploy
```

---

## License

MIT — © PHP-Solidity Contributors
