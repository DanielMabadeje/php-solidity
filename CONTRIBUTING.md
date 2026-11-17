# Contributing

Thanks for helping. This project translates code that handles money, so the bar for changes is "does the output stay correct", and the way we check that is tests, fixtures and the real Solidity compiler.

## Set up

```bash
git clone https://github.com/DanielMabadeje/php-solidity.git
cd php-solidity
composer install
composer check                  # PHPStan level 8 + the full test suite
```

For the Solidity compile check you also need Node.js:

```bash
cd scripts && npm install && cd ..
composer solc:check
```

## The most useful contribution: a failing fixture

If the tool crashes, rejects valid code, or emits wrong Solidity, the best bug report is the smallest PHP contract that shows it. If you can, add it as a file in `tests/fixtures/contracts/` and open a pull request, even before the fix exists.

## Making a change

1. **Read the relevant page** of [Architecture](docs/architecture.md). It has recipes for adding a type, attribute, statement, expression, EVM helper or validator rule.
2. **Write the test first.**

   | Change | Test |
   | --- | --- |
   | New translation | A row in `BodyEmitterTest::statements()` |
   | New error or rejection | A row in `BodyEmitterTest::unsupported()` or `ValidatorTest::memberCases()` |
   | New language feature | A fixture in `tests/fixtures/contracts/` |
   | CLI behaviour | A test in `CliTest` |

3. **Implement it.**
4. **If generated Solidity changed on purpose**, run `composer golden:update`, then read `git diff tests/fixtures/expected` carefully. Then run `composer solc:check`.
5. **Update the docs**: `docs/language-reference.md` for syntax, `docs/validator.md` for codes.
6. **Add a line to `CHANGELOG.md`** under "Unreleased".
7. Run `composer check` and `php scripts/check-docs.php`.

## Standards

- PHP 8.1 minimum, `declare(strict_types=1)`, PSR-12 style, 4-space indentation (see `.editorconfig`).
- PHPStan level 8 must pass with no baseline.
- Never translate by editing PHP source text. Walk the syntax tree.
- Every error a developer can cause needs a line number and, where possible, a hint.
- Generated Solidity must be deterministic.
- When unsure whether to guess or fail, fail with a clear message. A silently wrong contract is worse than a rejected one.

## Pull requests

Keep them focused: one change, its tests, its docs. The pull request template lists the checklist. CI must be green: tests on four PHP versions with the lowest and highest dependencies, macOS and Windows, PHPStan, and compiling the output with three `solc` versions.

## Releasing (maintainers)

1. Move the "Unreleased" entries in `CHANGELOG.md` under a new version heading.
2. Set `Application::VERSION` in `src/Cli/Application.php` to the same version.
3. Commit, then `git tag v0.1.0 && git push --tags`.
4. The release workflow checks the tag against `Application::VERSION`, runs the tests, and publishes the GitHub release from the changelog. Packagist updates through its webhook.

## Reporting security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
