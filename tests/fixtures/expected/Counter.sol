// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Counter {
    uint256 public count;

    event CountChanged(address indexed by, uint256 newCount);

    function increment() external {
        count += 1;
        emit CountChanged(msg.sender, count);
    }

    function decrement() external {
        require(count > 0, "Counter: already zero");
        count -= 1;
        emit CountChanged(msg.sender, count);
    }

    function current() external view returns (uint256) {
        return count;
    }
}
