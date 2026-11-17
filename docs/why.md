# Why PHP-Solidity?

## The idea

A lot of working developers know PHP and Laravel well, and know nothing about Solidity. PHP-Solidity lets them express a contract in syntax they read fluently, and produces plain Solidity that goes through the normal tooling (Foundry, Hardhat, Etherscan verification, audits).

It is a **syntax layer, not a new language and not a new runtime.** The output is ordinary Solidity, you can read it, and nothing about deployment or the EVM changes. Compare NativePHP, which keeps PHP as the language and swaps what it runs on.

## What it gives you

- **A familiar editor experience.** Your IDE, your formatter, your static analysis, your Composer workflow.
- **Typed, explicit code.** The transpiler refuses anything ambiguous: no untyped variables, no floats, no silent defaults. Several mistakes common in first contracts are caught before `solc` sees them (see the [validator](validator.md)).
- **Reviewable output.** The generated `.sol` is the artifact reviewers and auditors read. It stays close to your source.
- **A way in.** Reading the generated Solidity next to your own PHP is a decent way to learn Solidity.

## What it costs

Be honest with yourself about these:

- **You still have to learn the EVM.** Checked arithmetic, gas, storage layout, reentrancy, front-running and upgrade patterns are not hidden by the syntax. [PHP vs Solidity semantics](semantics.md) exists because code that *looks* like PHP behaves like Solidity.
- **An extra layer between you and the deployed code.** Bugs in the transpiler are possible, and it has not been audited. You must read the generated Solidity. See [Security](security.md).
- **Not all of Solidity is supported.** There is no inline assembly, no `try`/`catch`, no multiple return values, no `unchecked`, no overloaded functions. See the [roadmap](roadmap.md).
- **A smaller ecosystem.** Every tutorial, library and audit report is written in Solidity. You will translate in your head when you read them.
- **Libraries still come from Solidity.** You can import OpenZeppelin and inherit from it, but the tool does not know the members of imported contracts.

## When to use it

- You are a PHP developer exploring smart contracts, prototyping, or building a small contract you will test and have reviewed.
- You want the ergonomics of Composer, PHPStan and your usual editor for a contract-heavy project, and you read the output.
- You are teaching or learning how a transpiler works. The code is deliberately small and documented ([Understanding ASTs](understanding-asts.md)).

## When to write Solidity directly

- You need inline assembly, gas-golfing, or the newest language features.
- You are porting or auditing existing contracts.
- Your team already writes Solidity fluently. An extra layer buys them nothing.
- You are deploying something high-value and want zero tooling risk between source and bytecode.

Neither choice is wrong. The aim is to make the first one possible, not to claim it is always better.
