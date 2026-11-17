<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Constructor, Contract, External, Mapping, Payable, SolType, Storage, Struct, View};

enum Status
{
    case Open;
    case Funded;
    case Failed;
}

#[Struct]
class Contribution
{
    public address $backer;
    public uint256 $amount;
}

#[Contract]
class Crowdfund
{
    #[Storage(public: true)]
    private Status $status;

    #[SolType('Contribution[]')]
    private array $contributions;

    #[Mapping('address', 'string')]
    private mapping $notes;

    private uint256 $goal;
    private uint256 $raised;

    #[Constructor]
    public function __construct(uint256 $fundingGoal)
    {
        $this->goal   = $fundingGoal;
        $this->status = Status::Open;
    }

    #[External, Payable]
    public function contribute(): void
    {
        EVM::require($this->status == Status::Open, 'Crowdfund: campaign is not open');

        $this->contributions[] = new Contribution(msg::sender(), msg::value());
        $this->raised += msg::value();

        if ($this->raised >= $this->goal) {
            $this->status = Status::Funded;
        }
    }

    #[External]
    public function leaveNote(string $note): void
    {
        $this->notes[msg::sender()] = $note;
    }

    #[External]
    public function clearNote(): void
    {
        unset($this->notes[msg::sender()]);
    }

    #[External, View]
    public function contributionCount(): uint256
    {
        return count($this->contributions);
    }

    #[External, View]
    public function totalFrom(address $backer): uint256
    {
        $total = 0;

        for ($i = 0; $i < count($this->contributions); $i++) {
            if ($this->contributions[$i]->backer == $backer) {
                $total += $this->contributions[$i]->amount;
            }
        }

        return $total;
    }

    #[External, View]
    public function percentFunded(): uint256
    {
        return $this->goal == 0 ? 0 : ($this->raised * 100) / $this->goal;
    }
}
