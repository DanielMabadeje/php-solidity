# Getting started

This page takes you from nothing to a compiled contract in about ten minutes.

## Requirements

- PHP 8.1 or newer and Composer.
- To turn the generated Solidity into bytecode you also need a Solidity toolchain: [Foundry](https://book.getfoundry.sh/), [Hardhat](https://hardhat.org/), or the `solc` compiler on its own. PHP-Solidity writes `.sol` files; it does not replace the Solidity compiler.

## Install

```bash
composer require --dev danielmabadeje/php-solidity
```

The command line tool is then available as `vendor/bin/phpsolidity`. Check it:

```bash
vendor/bin/phpsolidity version
```

## Write a contract

Create `contracts/Counter.php`:

```php
<?php

declare(strict_types=1);

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

    #[External]
    public function decrement(): void
    {
        EVM::require($this->count > 0, 'Counter: already zero');
        $this->count -= 1;
        Event::emit('CountChanged', msg::sender(), $this->count);
    }

    #[External, View]
    public function current(): uint256
    {
        return $this->count;
    }
}
```

Three things to notice:

- **The file is never executed as PHP.** It is read as text and translated. Types such as `uint256`, and helpers such as `msg::sender()`, `EVM::require()` and `Event::emit()`, only need to be understood by the transpiler.
- **Attributes carry the Solidity meaning.** `#[External]` becomes `external`, `#[View]` becomes `view`, `#[Storage(public: true)]` becomes a `public` state variable with a getter.
- **`$this->count` is the state variable `count`.** Plain `$variables` are function parameters or locals.

## Compile it

```bash
vendor/bin/phpsolidity compile contracts --output build/solidity
```

```
compiled contracts/Counter.php -> build/solidity/Counter.sol

1 of 1 file(s) compiled.
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

    function decrement() external {
        require(count > 0, "Counter: already zero");
        count -= 1;
        emit CountChanged(msg.sender, count);
    }

    function current() external view returns (uint256) {
        return count;
    }
}
```

`compile` accepts files or directories (searched recursively). Before translating, every file goes through the [validator](validator.md), which rejects PHP that cannot work on the EVM and prints a line number for each problem.

## Turn it into bytecode

If `solc` is installed, `build` compiles and then runs it for you:

```bash
vendor/bin/phpsolidity build contracts --output build/solidity
```

This writes the ABI and bytecode next to the `.sol` file. Alternatively, point the output at your Hardhat or Foundry project and let that toolchain take over:

```bash
# Hardhat: contracts/   Foundry: src/
vendor/bin/phpsolidity compile contracts --output ../my-hardhat-project/contracts
```

While you work, `--watch` recompiles whenever a contract file changes:

```bash
vendor/bin/phpsolidity compile contracts --output build/solidity --watch
```

## Look inside

Two commands show what the tool understood:

```bash
vendor/bin/phpsolidity ast contracts/Counter.php          # the contract tree this tool builds
vendor/bin/phpsolidity ast contracts/Counter.php --php    # the raw PHP syntax tree
```

[Understanding ASTs](understanding-asts.md) explains what you are looking at.

## Editor support

Your editor will complain that `uint256` and `msg` are unknown classes. `stubs/evm.php` declares them with docblocks so autocompletion works. It is deliberately **not** loaded by Composer, because it defines global names such as `Event` that clash with frameworks like Laravel. Point your IDE (or a PHPStan bootstrap file) at `vendor/danielmabadeje/php-solidity/stubs/evm.php`.

Importing the attribute classes with `use PhpSolidity\Attributes\...` is recommended so your editor can resolve them, but the transpiler matches attributes by their short name and does not need the imports.

## Project configuration

```bash
vendor/bin/phpsolidity init
```

creates `phpsolidity.json`:

```json
{
  "input": ["contracts"],
  "output": "build/solidity",
  "solc": { "optimize": true, "runs": 200 }
}
```

After that, `vendor/bin/phpsolidity compile` with no arguments is enough. See the [command line reference](cli.md).

## Where to go next

- [Language reference](language-reference.md): everything you can write.
- [PHP vs Solidity semantics](semantics.md): read this before writing arithmetic or loops.
- [Security](security.md): read this before deploying anything that holds value.
