<?php

declare(strict_types=1);

use PhpSolidity\Attributes\{Contract, External, Pure};

#[Contract]
class Bits
{
    #[External, Pure]
    public function masked(uint256 $a, uint256 $b, uint256 $c): bool
    {
        return ($a & $b) == $c;
    }

    #[External, Pure]
    public function arithmetic(uint256 $a, uint256 $b): uint256
    {
        return $a + $b * 2 - ($a - $b) ** 2;
    }

    #[External, Pure]
    public function inRange(uint256 $a): bool
    {
        return ! ($a > 5 && $a < 10) || $a == 0;
    }

    #[External, Pure]
    public function rotated(uint256 $x): uint256
    {
        return ($x << 8) | ($x >> 8);
    }
}
