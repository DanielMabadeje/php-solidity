// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Bits {
    function masked(uint256 a, uint256 b, uint256 c) external pure returns (bool) {
        return (a & b) == c;
    }

    function arithmetic(uint256 a, uint256 b) external pure returns (uint256) {
        return (a + (b * 2)) - ((a - b) ** 2);
    }

    function inRange(uint256 a) external pure returns (bool) {
        return !((a > 5) && (a < 10)) || (a == 0);
    }

    function rotated(uint256 x) external pure returns (uint256) {
        return (x << 8) | (x >> 8);
    }
}
