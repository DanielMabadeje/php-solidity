<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Constructor, Contract, External, Guarded, Modifier, Override, Storage, View, Virtual};

#[Contract]
abstract class Ownable
{
    protected address $owner;

    #[Constructor]
    public function __construct()
    {
        $this->owner = msg::sender();
    }

    #[Modifier]
    protected function onlyOwner(): void
    {
        EVM::require(msg::sender() == $this->owner, 'Ownable: caller is not the owner');
    }

    #[External, View, Virtual]
    public function ownerOf(): address
    {
        return $this->owner;
    }
}

#[Contract]
class Treasury extends Ownable
{
    #[Storage(public: true)]
    private uint256 $fee;

    #[External, Guarded('onlyOwner')]
    public function setFee(uint256 $newFee): void
    {
        EVM::require($newFee <= 100, 'Treasury: fee above 100');
        $this->fee = $newFee;
    }

    #[External, View, Override]
    public function ownerOf(): address
    {
        return $this->owner;
    }
}
