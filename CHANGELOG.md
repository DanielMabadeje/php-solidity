# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project aims to follow [Semantic Versioning](https://semver.org/) once it reaches 1.0. Until then, minor versions may contain breaking changes, and each one is listed.

## [Unreleased]

## [0.2.0]

The translation core was rewritten. Version 0.1.0 was published before this rewrite, so this release lists everything that changed since then.

### Fixed

- `phpsolidity version` reported 0.1.0 in release 0.2.0, because the version constant was not bumped before tagging.
- **The bundled example produced invalid Solidity.** `MyToken` emitted a bare `mapping`, undeclared local variables, `new uint8(18)`, and parameters that silently hid state variables (`name = name;`). All of these are fixed and the example now compiles with `solc`.
- **Parameters and locals that share a name with a state variable** no longer collapse into one identifier. They are renamed `name_`, and the validator reports W701.
- **Operator grouping.** PHP and Solidity rank `&`, `|`, `^` differently from comparisons; nested operations are now always parenthesised.
- **The validator no longer reports false positives** from words inside strings and comments (it used regular expressions on the source text).
- **The library API validated nothing.** `Transpiler::transpile()` now runs the validator by default (`new Transpiler(validate: false)` to opt out).
- **`#[Immutable]` assigned outside the constructor** is now an error (E502). The old example did exactly that.
- Visibility is always written out. Previously a `private` variable emitted no keyword, which Solidity reads as `internal`.
- An untyped parameter or property is an error instead of silently becoming `uint256`.
- `stubs/evm.php` is no longer autoloaded. It declared global classes such as `Event`, which collide with Laravel and other frameworks.
- The installed `bin/phpsolidity` works with Composer's autoload proxy and symlinked path repositories.
- Removed `src/AST/Nodes/Nodes.php`, a duplicate of the individual node classes that was never autoloaded.
- `composer.lock` and `vendor/` are no longer committed. The README now names the package that Composer actually publishes (`danielmabadeje/php-solidity`).

### Added

- **A syntax-tree based translator** (`BodyEmitter`): local variable declarations with type inference (`/** @var type */` to override), `if`/`elseif`/`else`, `for`, `while`, `do-while`, `break`, `continue`, `unset` (as `delete`), array `push` and `.length`, ternaries, string concatenation, compound assignment, conversions, struct construction, enum cases.
- **Typed mappings and arrays** with `#[Mapping('address', 'uint256')]` and `#[SolType('uint256[]')]` (a `@sol-type` docblock also works).
- Structs (`#[Struct]`), enums (native PHP enums), interfaces (native PHP interfaces), libraries (`#[Library]`), custom errors (`#[SolidityError]`), `#[Receive]`, `#[Fallback]`, `#[Virtual]`, `#[Override]`, `#[Import]`, abstract contracts, several declarations per file, inheritance across declarations in one file.
- Modifiers that run code after the function body, with `EVM::placeholder()`.
- `EVM::sendValue()` for checked ETH sending, `EVM::revertWith()`, `EVM::keccak256()`, `EVM::encode()`, `EVM::encodePacked()`, `address::self()`.
- **A rewritten validator** that reads the PHP syntax tree: stable codes (E000 to E504, W301 to W701), line numbers, hints, and new checks (nullable and union types, reentrancy ordering, `tx.origin`, missing access control, `msg::value()` in non-payable functions, immutable and constant misuse).
- **CLI**: compile directories recursively, `--watch`, `phpsolidity.json` configuration, `build` (runs `solc`), `ast` (prints the contract tree or the raw PHP tree), `init`, `validate`, meaningful exit codes. The logic lives in a testable `Cli\Application` class.
- **Tests**: 190+ PHPUnit tests, golden-file fixtures for every language feature, and a `solc` compile check.
- **GitHub Actions**: tests on PHP 8.1 to 8.4 with lowest and highest dependencies, macOS and Windows runs, PHPStan level 8, `composer audit`, generated Solidity compiled with three `solc` versions, an install-as-dependency test, a documentation link check, and a release workflow.
- Documentation in `docs/`, including a from-scratch explanation of syntax trees, `SECURITY.md`, issue and pull request templates, and Dependabot configuration.

### Changed (breaking for library users)

- `FunctionNode::$body` is now `Stmt[]` (php-parser nodes), not a string. `ContractNode` gained `kind`, `errors` and `line`.
- `ContractParser::parseSourceUnit()` returns a `SourceUnitNode`; `parseSource()` still returns the primary `ContractNode`.
- `ContractValidator::getErrors()` and `getWarnings()` still return strings, but `getDiagnostics()` is the primary API.
- A `public` PHP property now becomes a `public` state variable, and `protected`/`private` methods without an attribute become `internal`/`private` instead of `public`.
- Property defaults now work (`#[Constant] private uint8 $decimals = 18;`). `Storage(name: ...)` is accepted but ignored.
- Requires `nikic/php-parser` ^5, PHPUnit ^10.5 or ^11, PHP 8.1 or newer.

### Upgrading from 0.1.0 code

1. Add `#[Mapping(...)]` to every `mapping` property, and give `#[Constant]` properties a value.
2. Replace `new uint8(18)` property defaults with `18`.
3. Run `phpsolidity validate` and fix what it reports; expect new warnings (W401, W602, W603, W701).
4. Remove any `require` of `stubs/evm.php` that relied on Composer autoloading it, and point your IDE at the file instead.

[Unreleased]: https://github.com/DanielMabadeje/php-solidity/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/DanielMabadeje/php-solidity/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/DanielMabadeje/php-solidity/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/DanielMabadeje/php-solidity/releases/tag/v0.1.0
