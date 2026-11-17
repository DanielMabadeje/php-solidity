<?php

declare(strict_types=1);

namespace PhpSolidity\AST\Nodes;

/**
 * Root of the Contract IR: everything that ends up in one `.sol` file.
 *
 * `declarations` keeps source order and holds ContractNode, StructNode and EnumNode items.
 */
class SourceUnitNode
{
    /**
     * @param string[]                                     $imports
     * @param array<ContractNode|StructNode|EnumNode>      $declarations
     */
    public function __construct(
        public readonly string $license,
        public readonly string $solidityVersion,
        public array $imports = [],
        public array $declarations = [],
    ) {}

    /** @return ContractNode[] */
    public function contracts(): array
    {
        return array_values(array_filter(
            $this->declarations,
            static fn ($d) => $d instanceof ContractNode,
        ));
    }

    /**
     * The contract that names the output file: the last real contract,
     * otherwise the last library, otherwise the last interface.
     */
    public function primary(): ?ContractNode
    {
        $contracts = $this->contracts();

        foreach (['contract', 'abstract contract', 'library', 'interface'] as $kind) {
            $matching = array_values(array_filter($contracts, static fn (ContractNode $c) => $c->kind === $kind));
            if ($matching !== []) {
                return $matching[count($matching) - 1];
            }
        }

        return null;
    }
}
