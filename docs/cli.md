# Command line

```
phpsolidity <command> [arguments] [options]
```

Run it as `vendor/bin/phpsolidity` in a project, or `php bin/phpsolidity` from a clone.

## Commands

### `compile <path>...`

Validates and transpiles PHP contracts to Solidity.

```bash
phpsolidity compile contracts/MyToken.php
phpsolidity compile contracts --output build/solidity
phpsolidity compile contracts examples --output build/solidity --quiet
```

A path may be a file or a directory. Directories are searched recursively; only `.php` files that declare a `#[Contract]`, a `#[Library]` or an `interface` are compiled, and `vendor/`, `node_modules/` and `.git/` are skipped. A file you name explicitly is always processed.

Each file is validated first. Errors stop that file (the others are still compiled) and the exit code becomes 1. Warnings are printed and do not affect the exit code. The output is `<ContractName>.sol`, named after the file's primary contract. If two files define the same contract name, the later one overwrites the earlier one and an error is printed.

### `build <path>...`

Does everything `compile` does, then runs `solc` to write the ABI and bytecode into `<output>/<ContractName>/`.

```bash
phpsolidity build contracts --output build/solidity
phpsolidity build contracts --solc ./node_modules/.bin/solcjs
```

`solc` is found on your `PATH`, or with `--solc <path>`. If it is missing, the command exits with code 3 and explains how to install it. Optimiser settings come from `phpsolidity.json`.

### `validate <path>...`

Runs the validator only and writes nothing. Useful in a pre-commit hook or CI step.

### `ast <file.php> [--php]`

Prints the contract tree this tool builds from your file. With `--php`, prints the raw PHP syntax tree instead. See [Understanding ASTs](understanding-asts.md).

### `init`

Writes a starter `phpsolidity.json` in the current directory. It refuses to overwrite an existing one.

### `help`, `version`

`phpsolidity help` prints usage. `phpsolidity version` (or `--version`) prints the version.

## Options

| Option | Applies to | Meaning |
| --- | --- | --- |
| `-o`, `--output <dir>` | compile, build | Directory for `.sol` files. Created if missing. Default: `.` or the config value |
| `--config <file>` | compile, build | Use this config file instead of `./phpsolidity.json` |
| `--watch` | compile, build | Recompile when a contract file is changed or added. Stop with Ctrl+C |
| `--solc <path>` | build | Path to the `solc` binary |
| `--quiet` | compile, build, validate | Print errors only |

## Configuration file

`phpsolidity.json` in the working directory is read automatically.

```json
{
  "input": ["contracts"],
  "output": "build/solidity",
  "solc": { "optimize": true, "runs": 200 }
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `input` | `[]` | Files or directories to compile when none are given on the command line |
| `output` | `"."` | Output directory |
| `solc.optimize` | `true` | Passes `--optimize` to `solc` |
| `solc.runs` | `200` | Passes `--optimize-runs` |

Command-line arguments override the file: given paths replace `input`, and `--output` replaces `output`.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success (warnings are fine) |
| 1 | A file failed validation or translation, or a path was not found |
| 2 | Usage error: unknown command or missing argument |
| 3 | `solc` was not found (build only) |

## Using it with other tools

**Hardhat.** Write straight into the contracts directory, then compile with Hardhat as usual:

```bash
phpsolidity compile contracts-php --output hardhat/contracts --watch
```

**Foundry.** The same, into `src/`:

```bash
phpsolidity compile contracts-php --output src
forge build
```

Commit the generated `.sol` files if reviewers need to read them; they are what actually gets deployed.

**CI.** `phpsolidity validate` and `phpsolidity compile` return non-zero on errors, so they work as build steps without extra scripting:

```yaml
- run: vendor/bin/phpsolidity compile contracts --output build/solidity
- run: forge build
```
