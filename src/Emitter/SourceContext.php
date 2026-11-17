<?php

declare(strict_types=1);

namespace PhpSolidity\Emitter;

use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EnumNode;
use PhpSolidity\AST\Nodes\SourceUnitNode;
use PhpSolidity\AST\Nodes\StructNode;

/**
 * Facts about the whole source file that the body translator needs:
 * which names are structs, enums, libraries, and what interface methods return.
 */
final class SourceContext
{
    /**
     * @param string[]                                $structNames
     * @param array<string, array<string, string>>    $structMembers    struct => member => type
     * @param string[]                                $enumNames
     * @param string[]                                $libraryNames
     * @param array<string, array<string, string>>    $methodReturns    contract/interface => method => return type
     * @param array<string, ContractNode>             $contracts
     */
    public function __construct(
        public readonly array $structNames = [],
        public readonly array $structMembers = [],
        public readonly array $enumNames = [],
        public readonly array $libraryNames = [],
        public readonly array $methodReturns = [],
        public readonly array $contracts = [],
    ) {}

    public static function fromUnit(SourceUnitNode $unit): self
    {
        $structs = $members = $enums = $libraries = $returns = $contracts = [];

        foreach ($unit->declarations as $declaration) {
            if ($declaration instanceof StructNode) {
                $structs[] = $declaration->name;
                foreach ($declaration->members as $member) {
                    $members[$declaration->name][$member->name] = $member->solidityType;
                }
            } elseif ($declaration instanceof EnumNode) {
                $enums[] = $declaration->name;
            } else {
                if ($declaration->kind === 'library') {
                    $libraries[] = $declaration->name;
                }
                $contracts[$declaration->name] = $declaration;
                foreach ($declaration->functions as $fn) {
                    $returns[$declaration->name][$fn->name] = $fn->returnType;
                }
            }
        }

        return new self($structs, $members, $enums, $libraries, $returns, $contracts);
    }

    /**
     * Return types of every function visible in a contract, including ones
     * inherited from bases declared in the same file.
     *
     * @return array<string, string>
     */
    public function functionReturnsFor(ContractNode $contract): array
    {
        $result = [];

        foreach ($contract->inherits as $parent) {
            if (isset($this->contracts[$parent]) && $parent !== $contract->name) {
                $result += $this->functionReturnsFor($this->contracts[$parent]);
            }
        }

        foreach ($contract->functions as $fn) {
            $result[$fn->name] = $fn->returnType;
        }

        return $result;
    }

    /**
     * State variable types visible in a contract, including inherited ones.
     *
     * @return array<string, string>
     */
    public function stateVariablesFor(ContractNode $contract): array
    {
        $result = [];

        foreach ($contract->inherits as $parent) {
            if (isset($this->contracts[$parent]) && $parent !== $contract->name) {
                $result += $this->stateVariablesFor($this->contracts[$parent]);
            }
        }

        foreach ($contract->stateVariables as $variable) {
            $result[$variable->name] = $variable->solidityType;
        }

        return $result;
    }
}
