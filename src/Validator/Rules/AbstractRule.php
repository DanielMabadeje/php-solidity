<?php

declare(strict_types=1);

namespace PhpSolidity\Validator\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpSolidity\Parser\AttributeReader;

abstract class AbstractRule implements RuleInterface
{
    /**
     * Visit every node of the tree except attribute groups (their arguments are
     * configuration, not contract code, so `['uint256 value']` is not an array literal).
     *
     * @param Node[]               $nodes
     * @param callable(Node): void $callback
     */
    protected function walk(array $nodes, callable $callback): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($callback) extends NodeVisitorAbstract {
            /** @param callable(Node): void $callback */
            public function __construct(private $callback) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\AttributeGroup) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                ($this->callback)($node);

                return null;
            }
        });
        $traverser->traverse($nodes);
    }

    /**
     * All class-like declarations in the file (descends into namespaces).
     *
     * @param Node[] $ast
     * @return array<Class_|Interface_>
     */
    protected function classLikes(array $ast): array
    {
        $found = [];
        $this->walk($ast, static function (Node $node) use (&$found): void {
            if ($node instanceof Class_ || $node instanceof Interface_) {
                $found[] = $node;
            }
        });

        return $found;
    }

    /**
     * Classes that become Solidity contracts or libraries.
     *
     * @param Node[] $ast
     * @return Class_[]
     */
    protected function contractClasses(array $ast): array
    {
        return array_values(array_filter(
            $this->classLikes($ast),
            static fn ($c) => $c instanceof Class_
                && (AttributeReader::has($c, 'Contract') || AttributeReader::has($c, 'Library')),
        ));
    }

    /** @return ClassMethod[] */
    protected function methodsOf(Class_|Interface_ $class): array
    {
        return array_values(array_filter($class->stmts, static fn ($s) => $s instanceof ClassMethod));
    }

    /** Is this class-like a struct (plain data holder)? */
    protected function isStruct(Class_|Interface_ $class): bool
    {
        return $class instanceof Class_ && AttributeReader::has($class, 'Struct');
    }

    /** Does the node carry an attribute that supplies an explicit Solidity type? */
    protected function hasTypeOverride(Node $node): bool
    {
        return AttributeReader::has($node, 'SolType') || AttributeReader::has($node, 'Mapping');
    }
}
