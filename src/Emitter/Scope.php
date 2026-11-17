<?php

declare(strict_types=1);

namespace PhpSolidity\Emitter;

use PhpSolidity\TypeSystem\Naming;

/**
 * Tracks the local variables of the function being emitted.
 *
 * Solidity scopes variables to the block they are declared in (like most C-style
 * languages), while PHP scopes them to the whole function. We follow Solidity's
 * rules and report a clear error if PHP code relies on the looser PHP behaviour.
 */
final class Scope
{
    /** @var array<int, array<string, array{name: string, type: string}>> */
    private array $frames = [];

    /** @param string[] $stateNames names of state variables (locals must not shadow them) */
    public function __construct(private readonly array $stateNames = [])
    {
        $this->push();
    }

    public function push(): void
    {
        $this->frames[] = [];
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }

    /** Declare a variable in the innermost block and return the name used in Solidity. */
    public function declare(string $phpName, string $type): string
    {
        $solName = Naming::safe($phpName, $this->stateNames);
        $this->frames[count($this->frames) - 1][$phpName] = ['name' => $solName, 'type' => $type];

        return $solName;
    }

    /** @return array{name: string, type: string}|null */
    public function lookup(string $phpName): ?array
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            if (isset($this->frames[$i][$phpName])) {
                return $this->frames[$i][$phpName];
            }
        }

        return null;
    }
}
