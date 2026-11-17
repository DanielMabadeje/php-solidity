<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Contract, External, Pure};

#[Contract]
class Flow
{
    #[External, Pure]
    public function sum(uint256 $n): uint256
    {
        $total = 0;
        $i     = 1;

        while ($i <= $n) {
            $total += $i;
            $i++;
        }

        return $total;
    }

    #[External, Pure]
    public function firstPowerOfTwoAbove(uint256 $limit): uint256
    {
        $x = 1;

        do {
            $x *= 2;
        } while ($x <= $limit);

        return $x;
    }

    #[External, Pure]
    public function classify(uint256 $v): uint256
    {
        if ($v == 0) {
            return 0;
        } elseif ($v < 10) {
            return 1;
        } else {
            return 2;
        }
    }

    #[External, Pure]
    public function countEven(uint256 $n): uint256
    {
        $count = 0;

        for ($i = 0; $i < $n; $i++) {
            if ($i % 2 == 1) {
                continue;
            }
            if ($i > 100) {
                break;
            }
            $count++;
        }

        return $count;
    }

    #[External, Pure]
    public function cube(uint256 $base): uint256
    {
        $result = $base;
        $result **= 3;

        return $result;
    }

    #[External, Pure]
    public function narrow(uint256 $value): uint8
    {
        /** @var uint8 */
        $small = 7;

        return uint8($value % 256) + $small;
    }

    #[External, Pure]
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
