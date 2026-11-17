// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

abstract contract Ownable {
    address internal owner;

    modifier onlyOwner() {
        require(msg.sender == owner, "Ownable: caller is not the owner");
        _;
    }

    constructor() {
        owner = msg.sender;
    }

    function ownerOf() external view virtual returns (address) {
        return owner;
    }
}

contract Treasury is Ownable {
    uint256 public fee;

    function setFee(uint256 newFee) external onlyOwner {
        require(newFee <= 100, "Treasury: fee above 100");
        fee = newFee;
    }

    function ownerOf() external view override returns (address) {
        return owner;
    }
}
