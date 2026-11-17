// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract Flow {
    function sum(uint256 n) external pure returns (uint256) {
        uint256 total = 0;
        uint256 i = 1;
        while (i <= n) {
            total += i;
            i++;
        }
        return total;
    }

    function firstPowerOfTwoAbove(uint256 limit) external pure returns (uint256) {
        uint256 x = 1;
        do {
            x *= 2;
        } while (x <= limit);
        return x;
    }

    function classify(uint256 v) external pure returns (uint256) {
        if (v == 0) {
            return 0;
        } else if (v < 10) {
            return 1;
        } else {
            return 2;
        }
    }

    function countEven(uint256 n) external pure returns (uint256) {
        uint256 count = 0;
        for (uint256 i = 0; i < n; i++) {
            if ((i % 2) == 1) {
                continue;
            }
            if (i > 100) {
                break;
            }
            count++;
        }
        return count;
    }

    function cube(uint256 base) external pure returns (uint256) {
        uint256 result = base;
        result = result ** 3;
        return result;
    }

    function narrow(uint256 value) external pure returns (uint8) {
        uint8 small = 7;
        return uint8(value % 256) + small;
    }

    function greet(string memory name) external pure returns (string memory) {
        return string.concat("Hello, ", name);
    }
}
