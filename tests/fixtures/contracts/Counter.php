<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Contract, External, SolidityEvent, Storage, View};

#[Contract]
#[SolidityEvent('CountChanged', ['address indexed by', 'uint256 newCount'])]
class Counter
{
    #[Storage(public: true)]
    private uint256 $count;

    #[External]
    public function increment(): void
    {
        $this->count += 1;
        Event::emit('CountChanged', msg::sender(), $this->count);
    }

    #[External]
    public function decrement(): void
    {
        EVM::require($this->count > 0, 'Counter: already zero');
        $this->count -= 1;
        Event::emit('CountChanged', msg::sender(), $this->count);
    }

    #[External, View]
    public function current(): uint256
    {
        return $this->count;
    }
}
