# Roadmap

What is missing today. These are ideas in rough priority order, not promises. Open an issue if one matters to you.

## Highest value

- **Execution tests.** Run every fixture on a local EVM (Foundry) and assert behaviour, so a translation that compiles but behaves wrongly is caught. Today the tests prove the output is stable and compiles.
- **Multiple return values.** `returns (uint256, bool)` and tuple destructuring. PHP has no direct syntax, so it needs a design (probably an attribute plus array-like returns).
- **`try` / `catch` for external calls**, and `unchecked { }` blocks.
- **Version-aware validation.** Warn when a feature needs a newer `pragma` than the contract declares (custom errors 0.8.4, `string.concat` 0.8.12, `prevrandao` 0.8.18).

## Language coverage

- Overloaded functions and function types.
- `using ... for ...` directives and user-defined value types.
- Ordering declarations by inheritance automatically, instead of requiring base contracts to come first.
- Checksummed address literals.
- `switch` as `if` chains, and `foreach` over arrays (as a bounded `for`).
- Backed enums, constants of non-literal expressions, `abi.decode`.
- Knowing the members of imported contracts (for example by reading the imported `.sol` or an ABI), so type inference works for OpenZeppelin bases.

## Tooling

- Source maps from `.sol` lines back to PHP lines, so a revert in Foundry can point at your PHP.
- `phpsolidity new` to scaffold a Hardhat or Foundry project around a contracts directory.
- Gas and size reporting in `build`.
- A language server, or at least better IDE stubs including attribute completion.
- Machine-readable diagnostics (`--format json`) for editors and CI annotations.

## Quality

- Property-based tests that generate random expressions and compare PHP evaluation with an EVM evaluation.
- A published comparison of generated gas costs against hand-written Solidity for the same contracts.
- An independent security review of the transpiler.

## Not planned

- Running PHP on chain, or a PHP runtime for the EVM.
- Supporting the whole of PHP. The point is a small, strict subset that maps cleanly.
- Inline assembly.
