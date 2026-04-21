# PHP-Solidity 🔷

**Write Ethereum smart contracts in PHP.**

PHP-Solidity is a PHP-to-Solidity transpiler. You write smart contracts using familiar PHP class syntax with PHP 8 Attributes, and the package compiles them into valid Solidity source code, ready to deploy on any EVM-compatible blockchain.

```php
#[Contract]
#[SolidityEvent('Transfer', ['address indexed from', 'address indexed to', 'uint256 value'])]
class MyToken
{
    #[Storage(public: true)]
    private uint256 $totalSupply;

    #[Storage]
    private mapping $balances;

    #[Constructor]
    public function __construct(uint256 $initialSupply): void
    {
        $this->totalSupply = $initialSupply;
        $this->balances[msg::sender()] = $initialSupply;
        Event::emit('Transfer', address::zero(), msg::sender(), $initialSupply);
    }

    #[External, View]
    public function balanceOf(address $owner): uint256
    {
        return $this->balances[$owner];
    }

    #[External]
    public function transfer(address $to, uint256 $amount): bool
    {
        require($this->balances[msg::sender()] >= $amount, "Insufficient balance");
        $this->balances[msg::sender()] -= $amount;
        $this->balances[$to] += $amount;
        Event::emit('Transfer', msg::sender(), $to, $amount);
        return true;
    }
}
```

Transpiles to:

```solidity
// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract MyToken {

    uint256 public totalSupply;
    mapping private balances;

    event Transfer(address indexed from, address indexed to, uint256 value);

    constructor(uint256 initialSupply) {
        totalSupply = initialSupply;
        balances[msg.sender] = initialSupply;
        emit Transfer(address(0), msg.sender, initialSupply);
    }

    function balanceOf(address owner) external view returns (uint256) {
        return balances[owner];
    }

    function transfer(address to, uint256 amount) external returns (bool) {
        require(balances[msg.sender] >= amount, "Insufficient balance");
        balances[msg.sender] -= amount;
        balances[to] += amount;
        emit Transfer(msg.sender, to, amount);
        return true;
    }
}
```

---

## Installation

```bash
composer require php-solidity/php-solidity
```

Requires PHP 8.1+.

---

## Usage

### CLI

```bash
# Transpile a contract
phpsolidity compile contracts/MyToken.php

# Transpile and save to a directory
phpsolidity compile contracts/MyToken.php --output ./solidity

# Validate only (no output)
phpsolidity validate contracts/MyToken.php
```

### Programmatic API

```php
use PhpSolidity\Transpiler;

$transpiler = new Transpiler();

// From a file
$result = $transpiler->transpileFile('contracts/MyToken.php');
echo $result->soliditySource;

// From a string
$result = $transpiler->transpile($phpSourceCode);
echo $result->soliditySource;

// Transpile and save to disk
$result = $transpiler->transpileAndSave('contracts/MyToken.php', './solidity');
// Writes: ./solidity/MyToken.sol
```

---

## EVM Types

Use these types in your PHP contracts instead of PHP's native types:

| PHP-Solidity Type | Solidity Type | Description |
|---|---|---|
| `uint256` | `uint256` | Unsigned 256-bit integer |
| `uint128` | `uint128` | Unsigned 128-bit integer |
| `uint64` / `uint32` / `uint16` / `uint8` | same | Smaller unsigned integers |
| `int256` | `int256` | Signed 256-bit integer |
| `address` | `address` | 20-byte Ethereum address |
| `bool` | `bool` | Boolean |
| `bytes32` | `bytes32` | 32-byte fixed array |
| `bytes` | `bytes` | Dynamic byte array |
| `string` | `string` | UTF-8 string |
| `mapping` | `mapping(...)` | Key-value storage |

> **Blocked types:** `float`, `double`, `array`, `mixed`, `object`, `resource`, `callable` — these throw a compile-time error.

---

## Attributes Reference

### Class Attributes

```php
#[Contract(name: 'MyToken', license: 'MIT', version: '^0.8.20')]
#[SolidityEvent('Transfer', ['address indexed from', 'address indexed to', 'uint256 value'])]
#[Inherits(ERC20::class, Ownable::class)]
class MyToken { ... }
```

### Property Attributes

```php
#[Storage]              // private state variable
#[Storage(public: true)]// public state variable (auto-getter)
#[Immutable]            // set once in constructor, then read-only
#[Constant]             // compile-time constant
```

### Method Attributes

```php
#[Constructor]          // marks the constructor
#[External]             // external visibility
#[Internal]             // internal visibility
#[View]                 // read-only (view function)
#[Pure]                 // no state access (pure function)
#[Payable]              // can receive ETH
#[Modifier]             // declares a Solidity modifier
#[Guarded('modName')]   // applies a modifier to a function
```

---

## EVM Globals

Use these instead of PHP's globals inside your contracts:

```php
msg::sender()           // msg.sender  — caller address
msg::value()            // msg.value   — ETH sent
block::timestamp()      // block.timestamp
block::number()         // block.number
block::chainid()        // block.chainid
tx::origin()            // tx.origin
address::zero()         // address(0)
Event::emit('Name', ...) // emit Name(...)
```

---

## Writing a Contract

### 1. Simple Storage

```php
#[Contract]
class SimpleStorage
{
    #[Storage(public: true)]
    private uint256 $storedValue;

    #[External]
    public function set(uint256 $value): void
    {
        $this->storedValue = $value;
    }

    #[External, View]
    public function get(): uint256
    {
        return $this->storedValue;
    }
}
```

### 2. With Modifiers

```php
#[Contract]
class Ownable
{
    #[Immutable]
    private address $owner;

    #[Constructor]
    public function __construct(): void
    {
        $this->owner = msg::sender();
    }

    #[Modifier]
    private function onlyOwner(): void
    {
        require(msg::sender() == $this->owner, "Not the owner");
    }

    #[External, Guarded('onlyOwner')]
    public function doOwnerThing(): void
    {
        // Only the owner can call this
    }
}
```

### 3. Payable Contract

```php
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
        require($this->deposits[msg::sender()] >= $amount, "Insufficient balance");
        $this->deposits[msg::sender()] -= $amount;
        msg::sender()->transfer($amount);
    }
}
```

---

## How It Works

```
Your PHP File
      ↓
 ContractValidator    ← Checks for blocked types, invalid constructs
      ↓
 ContractParser       ← Uses nikic/php-parser to build an AST
      ↓
 AST (ContractNode)   ← Internal representation of the contract
      ↓
 SolidityEmitter      ← Walks the AST and outputs Solidity code
      ↓
 MyContract.sol       ← Valid Solidity, ready for solc / Hardhat / Foundry
```

---

## Next Steps After Transpiling

Once you have your `.sol` file, use standard Solidity tooling to deploy:

```bash
# Hardhat
npx hardhat compile
npx hardhat run scripts/deploy.js --network mainnet

# Foundry
forge build
forge deploy
```

---

## Limitations

PHP-Solidity transpiles a **strict subset** of PHP. The following are not supported:

- Floating point numbers (`float`, `double`)
- Dynamic PHP arrays — use `mapping` instead
- Closures / anonymous functions
- PHP I/O functions (`file_get_contents`, `curl`, etc.)
- PHP superglobals (`$_GET`, `$_POST`, `$_SESSION`)
- `eval()`, `exec()`, reflection
- Random number functions (`rand()`, `mt_rand()`) — use Chainlink VRF

---

## Contributing

Contributions welcome! See `CONTRIBUTING.md`.

```bash
git clone https://github.com/php-solidity/php-solidity
cd php-solidity
composer install
composer test
```

---

## License

MIT — © PHP-Solidity Contributors