// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

interface IERC20 {
    function balanceOf(address account) external view returns (uint256);

    function transfer(address to, uint256 amount) external returns (bool);

    function transferFrom(address from, address to, uint256 amount) external returns (bool);
}

library MathLib {
    function min(uint256 a, uint256 b) internal pure returns (uint256) {
        return (a < b) ? a : b;
    }
}

contract Escrow {
    IERC20 private token;
    address private beneficiary;
    uint256 private deposited;

    constructor(address tokenAddress, address payee) {
        token = IERC20(tokenAddress);
        beneficiary = payee;
    }

    function deposit(uint256 amount) external {
        deposited += amount;
        require(token.transferFrom(msg.sender, address(this), amount), "Escrow: transfer failed");
    }

    function release(uint256 amount) external {
        require(msg.sender == beneficiary, "Escrow: only the beneficiary");
        uint256 payout = MathLib.min(amount, deposited);
        deposited -= payout;
        require(token.transfer(beneficiary, payout), "Escrow: transfer failed");
    }

    function held() external view returns (uint256) {
        return token.balanceOf(address(this));
    }
}
