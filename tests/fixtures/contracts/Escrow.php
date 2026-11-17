<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Constructor, Contract, External, Library, Pure, View};

interface IERC20
{
    #[View]
    public function balanceOf(address $account): uint256;

    public function transfer(address $to, uint256 $amount): bool;

    public function transferFrom(address $from, address $to, uint256 $amount): bool;
}

#[Library]
class MathLib
{
    #[Pure]
    public static function min(uint256 $a, uint256 $b): uint256
    {
        return $a < $b ? $a : $b;
    }
}

#[Contract]
class Escrow
{
    private IERC20 $token;
    private address $beneficiary;
    private uint256 $deposited;

    #[Constructor]
    public function __construct(address $tokenAddress, address $payee)
    {
        $this->token       = IERC20($tokenAddress);
        $this->beneficiary = $payee;
    }

    #[External]
    public function deposit(uint256 $amount): void
    {
        $this->deposited += $amount;
        EVM::require($this->token->transferFrom(msg::sender(), address::self(), $amount), 'Escrow: transfer failed');
    }

    #[External]
    public function release(uint256 $amount): void
    {
        EVM::require(msg::sender() == $this->beneficiary, 'Escrow: only the beneficiary');

        $payout = MathLib::min($amount, $this->deposited);
        $this->deposited -= $payout;

        EVM::require($this->token->transfer($this->beneficiary, $payout), 'Escrow: transfer failed');
    }

    #[External, View]
    public function held(): uint256
    {
        return $this->token->balanceOf(address::self());
    }
}
