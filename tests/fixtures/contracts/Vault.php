<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Constructor, Contract, External, Guarded, Mapping, Modifier, Payable, Receive, SolidityError, SolidityEvent, View};

#[Contract]
#[SolidityError('InsufficientBalance', ['uint256 available', 'uint256 requested'])]
#[SolidityEvent('Deposited', ['address indexed account', 'uint256 amount'])]
#[SolidityEvent('Withdrawn', ['address indexed account', 'uint256 amount'])]
class Vault
{
    #[Mapping('address', 'uint256')]
    private mapping $balances;

    private bool $locked;

    // Runs the function body at EVM::placeholder(), so the flag can be cleared afterwards.
    #[Modifier]
    private function nonReentrant(): void
    {
        EVM::require(! $this->locked, 'Vault: reentrant call');
        $this->locked = true;
        EVM::placeholder();
        $this->locked = false;
    }

    #[Receive]
    public function receive(): void
    {
        $this->balances[msg::sender()] += msg::value();
        Event::emit('Deposited', msg::sender(), msg::value());
    }

    #[External, Payable]
    public function deposit(): void
    {
        $this->balances[msg::sender()] += msg::value();
        Event::emit('Deposited', msg::sender(), msg::value());
    }

    #[External, Guarded('nonReentrant')]
    public function withdraw(uint256 $amount): void
    {
        $balance = $this->balances[msg::sender()];

        if ($balance < $amount) {
            EVM::revertWith('InsufficientBalance', $balance, $amount);
        }

        // Effects before interactions: update the balance, then send the ETH.
        $this->balances[msg::sender()] = $balance - $amount;
        EVM::sendValue(msg::sender(), $amount);

        Event::emit('Withdrawn', msg::sender(), $amount);
    }

    #[External, View]
    public function balanceOf(address $account): uint256
    {
        return $this->balances[$account];
    }

    #[External, View]
    public function totalHeld(): uint256
    {
        return address::self()->balance();
    }
}
