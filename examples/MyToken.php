<?php

declare(strict_types=1);

use PhpSolidity\Attributes\Contract;
use PhpSolidity\Attributes\SolidityEvent;
use PhpSolidity\Attributes\Storage;
use PhpSolidity\Attributes\Immutable;
use PhpSolidity\Attributes\Constructor;
use PhpSolidity\Attributes\External;
use PhpSolidity\Attributes\View;
use PhpSolidity\Attributes\Modifier;
use PhpSolidity\Attributes\Guarded;

/**
 * ─────────────────────────────────────────────────────────────
 * MyToken — ERC-20 token written in PHP.
 *
 * This is a PHP-Solidity contract file.
 * It is NOT executed as PHP — it is transpiled to Solidity.
 *
 * Run:  phpsolidity compile examples/MyToken.php
 * Out:  MyToken.sol  (deploy with Hardhat / Foundry)
 * ─────────────────────────────────────────────────────────────
 *
 * @phpstan-ignore-file
 * @noinspection ALL
 */
#[Contract(license: 'MIT', version: '^0.8.20')]
#[SolidityEvent('Transfer',             ['address indexed from', 'address indexed to', 'uint256 value'])]
#[SolidityEvent('Approval',             ['address indexed owner', 'address indexed spender', 'uint256 value'])]
#[SolidityEvent('OwnershipTransferred', ['address indexed previousOwner', 'address indexed newOwner'])]
class MyToken
{
    // ─── State Variables ──────────────────────────────────────

    #[Storage(public: true)]
    private string $name;

    #[Storage(public: true)]
    private string $symbol;

    #[Storage(public: true)]
    private uint8 $decimals;

    #[Storage(public: true)]
    private uint256 $totalSupply;

    /** @sol-type mapping(address => uint256) */
    #[Storage]
    private mapping $balances;

    /** @sol-type mapping(address => mapping(address => uint256)) */
    #[Storage]
    private mapping $allowances;

    #[Immutable]
    private address $owner;

    // ─── Constructor ──────────────────────────────────────────

    #[Constructor]
    public function __construct(
        string $name,
        string $symbol,
        uint256 $initialSupply,
    ) {
        $this->name        = $name;
        $this->symbol      = $symbol;
        $this->decimals    = new uint8(18);
        $this->totalSupply = $initialSupply;
        $this->owner       = msg::sender();

        $this->balances[msg::sender()] = $initialSupply;

        Event::emit('Transfer', address::zero(), msg::sender(), $initialSupply);
    }

    // ─── Modifiers ────────────────────────────────────────────

    #[Modifier]
    private function onlyOwner(): void
    {
        EVM::require(msg::sender() == $this->owner, "Ownable: caller is not the owner");
    }

    // ─── View Functions ───────────────────────────────────────

    #[External, View]
    public function balanceOf(address $account): uint256
    {
        return $this->balances[$account];
    }

    #[External, View]
    public function allowance(address $owner, address $spender): uint256
    {
        return $this->allowances[$owner][$spender];
    }

    // ─── Write Functions ──────────────────────────────────────

    #[External]
    public function transfer(address $to, uint256 $amount): bool
    {
        EVM::require($this->balances[msg::sender()] >= $amount, "ERC20: insufficient balance");
        EVM::require($to != address::zero(), "ERC20: transfer to zero address");

        $this->balances[msg::sender()] -= $amount;
        $this->balances[$to]           += $amount;

        Event::emit('Transfer', msg::sender(), $to, $amount);

        return true;
    }

    #[External]
    public function approve(address $spender, uint256 $amount): bool
    {
        EVM::require($spender != address::zero(), "ERC20: approve to zero address");

        $this->allowances[msg::sender()][$spender] = $amount;

        Event::emit('Approval', msg::sender(), $spender, $amount);

        return true;
    }

    #[External]
    public function transferFrom(address $from, address $to, uint256 $amount): bool
    {
        $currentAllowance = $this->allowances[$from][msg::sender()];

        EVM::require($currentAllowance >= $amount, "ERC20: insufficient allowance");
        EVM::require($this->balances[$from] >= $amount, "ERC20: insufficient balance");
        EVM::require($to != address::zero(), "ERC20: transfer to zero address");

        $this->allowances[$from][msg::sender()] -= $amount;
        $this->balances[$from]                  -= $amount;
        $this->balances[$to]                    += $amount;

        Event::emit('Transfer', $from, $to, $amount);

        return true;
    }

    // ─── Owner-only Functions ─────────────────────────────────

    #[External, Guarded('onlyOwner')]
    public function mint(address $to, uint256 $amount): void
    {
        EVM::require($to != address::zero(), "ERC20: mint to zero address");

        $this->totalSupply   += $amount;
        $this->balances[$to] += $amount;

        Event::emit('Transfer', address::zero(), $to, $amount);
    }

    #[External, Guarded('onlyOwner')]
    public function burn(address $from, uint256 $amount): void
    {
        EVM::require($this->balances[$from] >= $amount, "ERC20: burn exceeds balance");

        $this->balances[$from] -= $amount;
        $this->totalSupply     -= $amount;

        Event::emit('Transfer', $from, address::zero(), $amount);
    }

    #[External, Guarded('onlyOwner')]
    public function transferOwnership(address $newOwner): void
    {
        EVM::require($newOwner != address::zero(), "Ownable: new owner is zero address");

        Event::emit('OwnershipTransferred', $this->owner, $newOwner);

        $this->owner = $newOwner;
    }
}