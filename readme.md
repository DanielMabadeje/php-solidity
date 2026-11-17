# PHP-Solidity

[![CI](https://github.com/DanielMabadeje/php-solidity/actions/workflows/ci.yml/badge.svg)](https://github.com/DanielMabadeje/php-solidity/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/danielmabadeje/php-solidity)](https://packagist.org/packages/danielmabadeje/php-solidity)
[![PHP](https://img.shields.io/packagist/php-v/danielmabadeje/php-solidity)](https://packagist.org/packages/danielmabadeje/php-solidity)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Write Ethereum smart contracts in PHP. Get plain Solidity out.**

PHP-Solidity reads a PHP 8 class with typed properties and attributes, checks it against EVM rules, and writes a `.sol` file you can compile and deploy with Foundry, Hardhat or `solc`. It works on PHP's syntax tree, not by editing text, so errors point at the exact line and operator grouping stays correct.

> **Status: pre-1.0 and unaudited.** Review the generated Solidity, test the compiled contract, and get an independent audit before deploying anything of value. See [Security](docs/security.md).

## Quick start

```bash
composer require --dev danielmabadeje/php-solidity
```

`contracts/Counter.php`:

```php
<?php

use PhpSolidity\Attributes\{Contract, External, SolidityEvent, Storage, View};

#[Contract]
#[SolidityEvent('CountChanged', ['address indexed by', 'uint256 newCount'])]
class Counter
{
    #[Storage(public: true)]
    private uint256 $count;

    #[External]
    public function increment(): void
    {
        $this->count += 1;
        Event::emit('CountChanged', msg::sender(), $this->count);
    }

    #[External, View]
    public function current(): uint256
    {
        return $this->count;
    }
}
```

```bash
vendor/bin/phpsolidity compile contracts --output build/solidity
```

`build/solidity/Counter.sol`:

```solidity
// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Counter {
    uint256 public count;

    event CountChanged(address indexed by, uint256 newCount);

    function increment() external {
        count += 1;
        emit CountChanged(msg.sender, count);
    }

    function current() external view returns (uint256) {
        return count;
    }
}
```

The PHP file is never executed. It is parsed, validated and translated. Walk through the full tutorial in [Getting started](docs/getting-started.md).

## What you can write

| | |
| --- | --- |
| **Types** | `uint8`..`uint256`, `int8`..`int256`, `bool`, `address`, `string`, `bytes`, `bytes1`..`bytes32`, structs, enums, interfaces |
| **Storage** | Typed state variables, constants, immutables, mappings (including nested) and arrays |
| **Functions** | Visibility and mutability, constructors, `receive`, `fallback`, modifiers (with code before and after), `virtual`/`override` |
| **Control flow** | `if`/`elseif`/`else`, `for`, `while`, `do-while`, `break`, `continue`, ternaries |
| **EVM features** | `msg`, `block`, `tx`, events, custom errors, safe ETH sending, `keccak256`, ABI encoding |
| **Structure** | Several declarations per file, inheritance, imports (OpenZeppelin works), libraries |
| **Locals** | Declared on first assignment with an inferred type, or `/** @var type */` when it cannot be inferred |

A larger example, an ERC-20 token, is in [`examples/MyToken.php`](examples/MyToken.php). The full syntax is in the [language reference](docs/language-reference.md).

## What it protects you from

The validator reports problems **before** translation, each with a code and a line number:

```
contracts/Token.php:18  [E101] Blocked type 'float' in property $rate: Solidity has no floating-point type. ...
contracts/Token.php:31  [E301] rand() is not safe in smart contracts: block data can be manipulated ...
contracts/Vault.php:52  [W603] State is changed after an external call (line 49). This is the pattern behind reentrancy attacks.
```

It rejects floats, `foreach`, closures, I/O, randomness and `null`, and warns about `tx.origin`, missing access checks, reentrancy ordering and `transfer()`. Every code is listed in [Validator rules](docs/validator.md).

Solidity does not behave like PHP even when it looks the same: integer division truncates, overflow reverts, and variables are block scoped. Read [PHP vs Solidity semantics](docs/semantics.md) before writing arithmetic.

## Command line

```bash
phpsolidity compile <path>...     # PHP -> Solidity (files or directories)
phpsolidity build <path>...       # compile, then run solc for ABI and bytecode
phpsolidity validate <path>...    # check only
phpsolidity ast <file.php>        # show the contract tree (--php for the raw PHP tree)
phpsolidity init                  # create phpsolidity.json
```

Add `--watch` to recompile on change and `--output <dir>` to choose where files go. See the [command line reference](docs/cli.md).

## Using it from PHP

```php
use PhpSolidity\Transpiler;

$result = (new Transpiler())->transpileFile('contracts/Counter.php');

echo $result->soliditySource;
$result->warnings;   // validator warnings
```

## Documentation

| | |
| --- | --- |
| [Getting started](docs/getting-started.md) | Install, first contract, Hardhat and Foundry |
| [How it works](docs/how-it-works.md) | The pipeline and the design decisions |
| [Understanding ASTs](docs/understanding-asts.md) | Syntax trees from scratch, with real output |
| [Language reference](docs/language-reference.md) | Every type, attribute, global and statement |
| [PHP vs Solidity semantics](docs/semantics.md) | Where the same code means something different |
| [Validator rules](docs/validator.md) | All error and warning codes |
| [Security](docs/security.md) | What is not promised, and a pre-deployment checklist |
| [Architecture](docs/architecture.md) | Code map and how to extend it |
| [Testing and CI](docs/testing-and-ci.md) | How the output is verified |
| [Why PHP-Solidity?](docs/why.md) and [Roadmap](docs/roadmap.md) | Trade-offs and what is missing |

## How it is tested

Every PHP fixture is transpiled and compared with a stored `.sol` file, and every generated file is compiled with the real Solidity compiler (three versions of `solc`) in CI. The suite runs on PHP 8.1 to 8.4 with both the lowest and highest dependency versions, and on macOS and Windows. Details are in [Testing and CI](docs/testing-and-ci.md).

## Contributing

Bug reports, fixtures that expose wrong output, and pull requests are welcome. Start with [CONTRIBUTING.md](CONTRIBUTING.md). To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
