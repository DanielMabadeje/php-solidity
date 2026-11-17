# Testing and CI

A transpiler is only as trustworthy as its tests. This project tests at four levels, and CI runs all of them on every push.

## The four levels

### 1. Unit tests (`tests/Unit/`)

Fast, focused PHPUnit tests, mostly written as **data providers**: one row per behaviour, so adding a case is one line.

| File | What it pins down |
| --- | --- |
| `TypeMapperTest` | Every type mapping and every rejected type |
| `BodyEmitterTest` | PHP statement in, Solidity lines out (operators, locals, loops, name collisions, `memory`, structs) and every "unsupported construct" error, including that it carries a line number |
| `ValidatorTest` | One row per validator code, plus regression tests for the false positives of the old regex validator |
| `ParserTest` | The Contract tree built from attributes |
| `TranspilerTest` | The public API: validation on by default, warnings returned, file output, determinism |
| `CliTest` | Every command, exit codes, directory discovery, watch, config file, error output |
| `NamingAndConfigTest` | Name collision rules and `phpsolidity.json` |

### 2. Golden-file tests (`tests/Golden/`)

Each PHP contract in `tests/fixtures/contracts/` and `examples/` has a matching expected file in `tests/fixtures/expected/`. The test transpiles the PHP and requires the output to match byte for byte.

When you change the emitter on purpose:

```bash
composer golden:update        # rewrites tests/fixtures/expected/*.sol
git diff tests/fixtures/expected
```

Review that diff as you would review code, because it is exactly what users will see change. Do not regenerate blindly.

The fixtures are chosen to cover the whole language: `Counter` (basics), `Vault` (payable, receive, custom errors, modifiers with a placeholder, safe ETH sending), `Crowdfund` (enums, structs, struct arrays, loops, ternaries, `unset`), `Escrow` (interfaces, libraries, external calls), `Treasury` (inheritance, `virtual`/`override`), `Bits` (operator grouping), `Flow` (every loop and branch form, `@var`), and `MyToken` (a full ERC-20).

### 3. The solc check

Passing tests prove the output is *stable*. They do not prove it is *valid Solidity*. `scripts/solc-check.js` compiles every generated file with the real Solidity compiler (solc-js) and fails on any error.

```bash
cd scripts && npm install && cd ..
composer solc:check
```

CI runs it against **three solc versions** (0.8.20, 0.8.26, 0.8.28) and also compiles the committed golden files directly. This is the check that would have caught the original example, whose output did not compile.

### 4. Static analysis

PHPStan at level 8 over `src/`, and `composer audit` for known vulnerabilities in dependencies.

## CI jobs

Defined in `.github/workflows/ci.yml`. They run on pushes to `main`, on pull requests, on demand, and **weekly**, so a new release of PHP, php-parser, PHPUnit or solc that breaks us is noticed within days.

| Job | What it covers |
| --- | --- |
| `composer-validate` | `composer.json` is valid and strict-clean |
| `tests` | The full suite on PHP **8.1, 8.2, 8.3 and 8.4**, each with the **lowest and highest** allowed dependency versions (8 combinations) |
| `tests-os` | The suite and a CLI smoke test on **macOS and Windows** (path separators, line endings, process handling) |
| `static-analysis` | PHPStan level 8 and `composer audit` |
| `solidity` | Generated Solidity compiles with solc 0.8.20, 0.8.26 and 0.8.28 |
| `install-as-dependency` | Installs the package into a fresh project with Composer and runs `vendor/bin/phpsolidity`, and checks that the IDE stubs are *not* autoloaded into the consumer |
| `docs` | Every relative link and anchor in the Markdown files resolves (`scripts/check-docs.php`) |

`.github/workflows/release.yml` runs when you push a tag such as `v0.1.0`. It checks that the tag matches `Application::VERSION`, runs the tests, takes the release notes from `CHANGELOG.md` and publishes a GitHub release. Packagist picks the tag up through its GitHub webhook.

`.github/dependabot.yml` keeps GitHub Actions, Composer and npm dependencies current.

## Running everything locally

```bash
composer install
composer check                     # PHPStan + tests
composer solc:check                # needs Node.js and `npm install` in scripts/
php scripts/check-docs.php         # documentation links
```

## Writing a test for your change

| You changed... | Add... |
| --- | --- |
| How a PHP construct is translated | A row in `BodyEmitterTest::statements()`. If it changes a whole contract's output, a fixture too |
| What the tool rejects | A row in `BodyEmitterTest::unsupported()` or `ValidatorTest::memberCases()` |
| Attribute handling | A test in `ParserTest` and, if it changes output, a fixture |
| The CLI | A test in `CliTest` using the in-memory streams `Application` accepts |
| A new language feature | A **fixture** that uses it, then `composer golden:update`, then `composer solc:check` |

Adding a fixture means dropping a `.php` file in `tests/fixtures/contracts/`. It is picked up automatically by the golden tests, the solc check, and the CI jobs.

## What is not tested yet

Tests prove the translation is stable and compiles. They do not yet **execute** the contracts. Running each fixture on a local EVM and checking its behaviour (for example with Foundry) would catch a translation that compiles but does the wrong thing. It is the most valuable next layer; see the [roadmap](roadmap.md).
