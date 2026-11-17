// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

enum Status {
    Open,
    Funded,
    Failed
}

struct Contribution {
    address backer;
    uint256 amount;
}

contract Crowdfund {
    Status public status;
    Contribution[] private contributions;
    mapping(address => string) private notes;
    uint256 private goal;
    uint256 private raised;

    constructor(uint256 fundingGoal) {
        goal = fundingGoal;
        status = Status.Open;
    }

    function contribute() external payable {
        require(status == Status.Open, "Crowdfund: campaign is not open");
        contributions.push(Contribution(msg.sender, msg.value));
        raised += msg.value;
        if (raised >= goal) {
            status = Status.Funded;
        }
    }

    function leaveNote(string memory note) external {
        notes[msg.sender] = note;
    }

    function clearNote() external {
        delete notes[msg.sender];
    }

    function contributionCount() external view returns (uint256) {
        return contributions.length;
    }

    function totalFrom(address backer) external view returns (uint256) {
        uint256 total = 0;
        for (uint256 i = 0; i < contributions.length; i++) {
            if (contributions[i].backer == backer) {
                total += contributions[i].amount;
            }
        }
        return total;
    }

    function percentFunded() external view returns (uint256) {
        return (goal == 0) ? 0 : ((raised * 100) / goal);
    }
}
