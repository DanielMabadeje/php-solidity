// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Vault {
    error InsufficientBalance(uint256 available, uint256 requested);

    mapping(address => uint256) private balances;
    bool private locked;

    event Deposited(address indexed account, uint256 amount);
    event Withdrawn(address indexed account, uint256 amount);

    modifier nonReentrant() {
        require(!locked, "Vault: reentrant call");
        locked = true;
        _;
        locked = false;
    }

    receive() external payable {
        balances[msg.sender] += msg.value;
        emit Deposited(msg.sender, msg.value);
    }

    function deposit() external payable {
        balances[msg.sender] += msg.value;
        emit Deposited(msg.sender, msg.value);
    }

    function withdraw(uint256 amount) external nonReentrant {
        uint256 balance = balances[msg.sender];
        if (balance < amount) {
            revert InsufficientBalance(balance, amount);
        }
        balances[msg.sender] = balance - amount;
        {
            (bool _sent, ) = payable(msg.sender).call{value: amount}("");
            require(_sent, "PhpSolidity: ETH transfer failed");
        }
        emit Withdrawn(msg.sender, amount);
    }

    function balanceOf(address account) external view returns (uint256) {
        return balances[account];
    }

    function totalHeld() external view returns (uint256) {
        return address(this).balance;
    }
}
