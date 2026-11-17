# Security

Smart contracts hold real value and cannot be patched after deployment. Please read this page before deploying anything written with this tool.

## What this project does not promise

- **The transpiler is not audited, and has not been formally verified.** A bug in it could produce Solidity that behaves differently from your PHP. The tests and the `solc` check reduce that risk but cannot remove it.
- **Valid Solidity is not safe Solidity.** `solc` accepting the output tells you it is well-formed. It says nothing about reentrancy, access control, economic attacks, or whether the logic is what you intended.
- **The validator's security warnings are heuristics.** A clean validator run is not an audit.
- **Writing in PHP does not make a contract easier to secure.** The EVM's rules (checked arithmetic, reverts, gas, public state, front-running) apply in full. See [PHP vs Solidity semantics](semantics.md).

## Review the generated Solidity

The `.sol` file is what is deployed. Read it. It is intentionally close to the PHP, with explicit visibility and parentheses, so it should be easy to compare line by line. Keep the generated files in version control and review changes to them in pull requests.

## Before you deploy

1. **Read every warning** from `phpsolidity validate`. Fix or consciously accept each one.
2. **Test the compiled contract**, not the PHP. Use Foundry or Hardhat to run unit tests, fuzz tests and invariant tests against the deployed bytecode on a local chain. This project's own tests prove the translation compiles, not that your contract is correct.
3. **Check the access control** of every function that changes state. Who can call it? What happens if a stranger does?
4. **Order state changes before external calls** (checks-effects-interactions), and consider a reentrancy guard for anything that sends ETH or calls untrusted contracts.
5. **Deploy to a testnet** and exercise it with real transactions.
6. **Get an independent audit** before holding meaningful value.

## Patterns that work

**Access control.**

```php
#[Modifier]
protected function onlyOwner(): void
{
    EVM::require(msg::sender() == $this->owner, 'caller is not the owner');
}

#[External, Guarded('onlyOwner')]
public function setFee(uint256 $fee): void { $this->fee = $fee; }
```

**Checks, effects, interactions.**

```php
#[External, Guarded('nonReentrant')]
public function withdraw(uint256 $amount): void
{
    $balance = $this->balances[msg::sender()];
    EVM::require($balance >= $amount, 'insufficient balance');   // check

    $this->balances[msg::sender()] = $balance - $amount;          // effect
    EVM::sendValue(msg::sender(), $amount);                        // interaction
}
```

The complete contract, including the `nonReentrant` modifier written with `EVM::placeholder()`, is in [`tests/fixtures/contracts/Vault.php`](../tests/fixtures/contracts/Vault.php).

**Do not:**

- Use `tx::origin()` for authorization.
- Use `block::timestamp()`, `block::number()` or `block::prevrandao()` as a source of randomness.
- Use `->transfer()` or `->send()` to pay contract addresses (use `EVM::sendValue`).
- Loop over unbounded storage arrays in a function anyone can call.
- Assume a `string` or `bytes` parameter is short or well-formed.

## Reporting a vulnerability

If the transpiler can produce Solidity that **loses funds, bypasses a check, or silently changes meaning**, please do not open a public issue. Follow [SECURITY.md](../SECURITY.md).

For ordinary bugs (a crash, a valid construct rejected, output that fails to compile), open a regular issue with the smallest PHP contract that shows it.
