<?php

declare(strict_types=1);

namespace PhpSolidity\Parser;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EventNode;
use PhpSolidity\AST\Nodes\EventParamNode;
use PhpSolidity\AST\Nodes\FunctionNode;
use PhpSolidity\AST\Nodes\ModifierNode;
use PhpSolidity\AST\Nodes\ParameterNode;
use PhpSolidity\AST\Nodes\StateVariableNode;
use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\TypeSystem\TypeMapper;

class ContractParser
{
    private TypeMapper $typeMapper;
    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->typeMapper = new TypeMapper();
        $this->printer    = new PrettyPrinter();
    }

    /**
     * Parse a PHP source file and return a ContractNode.
     *
     * @throws ParseException
     */
    public function parseFile(string $filePath): ContractNode
    {
        if (! file_exists($filePath)) {
            throw new ParseException("File not found: {$filePath}");
        }

        return $this->parseSource(file_get_contents($filePath));
    }

    /**
     * Parse PHP source code string and return a ContractNode.
     *
     * @throws ParseException
     */
    public function parseSource(string $source): ContractNode
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $stmts = $parser->parse($source);
        } catch (\PhpParser\Error $e) {
            throw new ParseException("PHP parse error: " . $e->getMessage());
        }

        // Find the first class with a #[Contract] attribute
        $contractClass = $this->findContractClass($stmts);

        if ($contractClass === null) {
            throw new ParseException(
                "No #[Contract] class found. Mark your class with the #[Contract] attribute."
            );
        }

        return $this->buildContractNode($contractClass);
    }

    // ─────────────────────────────────────────────────────────
    // Find the #[Contract] class
    // ─────────────────────────────────────────────────────────

    /** @param Node[] $stmts */
    private function findContractClass(array $stmts): ?Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Class_) {
                foreach ($stmt->attrGroups as $attrGroup) {
                    foreach ($attrGroup->attrs as $attr) {
                        $attrName = $attr->name->toString();
                        if (in_array($attrName, ['Contract', 'PhpSolidity\\Attributes\\Contract'], true)) {
                            return $stmt;
                        }
                    }
                }
            }

            // Recurse into namespaces
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $found = $this->findContractClass($stmt->stmts);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────
    // Build the ContractNode
    // ─────────────────────────────────────────────────────────

    private function buildContractNode(Class_ $class): ContractNode
    {
        // Extract #[Contract] attribute options
        $contractAttr    = $this->getContractAttribute($class);
        $contractName    = $contractAttr['name']    ?? $class->name->toString();
        $license         = $contractAttr['license'] ?? 'MIT';
        $solidityVersion = $contractAttr['version'] ?? '^0.8.20';

        // Extract #[Inherits] parents
        $inherits = $this->extractInherits($class);

        // Extract #[SolidityEvent] definitions
        $events = $this->extractEvents($class);

        $stateVariables = [];
        $functions      = [];
        $modifiers      = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $vars = $this->parseProperty($stmt);
                foreach ($vars as $var) {
                    $stateVariables[] = $var;
                }
            }

            if ($stmt instanceof ClassMethod) {
                if ($this->hasAttribute($stmt, 'Modifier')) {
                    $modifiers[] = $this->parseModifier($stmt);
                } else {
                    $functions[] = $this->parseMethod($stmt);
                }
            }
        }

        return new ContractNode(
            name:            $contractName,
            license:         $license,
            solidityVersion: $solidityVersion,
            stateVariables:  $stateVariables,
            events:          $events,
            modifiers:       $modifiers,
            functions:       $functions,
            inherits:        $inherits,
        );
    }

    // ─────────────────────────────────────────────────────────
    // Parse a property/state variable
    // ─────────────────────────────────────────────────────────

    /** @return StateVariableNode[] */
    private function parseProperty(Property $property): array
    {
        $nodes       = [];
        $isConstant  = $this->hasAttribute($property, 'Constant');
        $isImmutable = $this->hasAttribute($property, 'Immutable');
        $isPublic    = $this->hasAttribute($property, 'Storage')
            && $this->getAttributeArg($property, 'Storage', 'public') === true;

        $mutability = match (true) {
            $isConstant  => 'constant',
            $isImmutable => 'immutable',
            default      => '',
        };

        $visibility = match (true) {
            $isPublic                        => 'public',
            $property->isProtected()         => 'internal',
            $property->isPrivate()           => 'private',
            default                          => 'private',
        };

        // Extract the PHP type
        $phpType = $this->extractPropertyType($property);
        $solidityType = $this->typeMapper->toSolidity($phpType);

        foreach ($property->props as $prop) {
            $defaultValue = null;
            if ($prop->default !== null) {
                $defaultValue = $this->printer->prettyPrintExpr($prop->default);
                $defaultValue = $this->translateExpression($defaultValue);
            }

            $nodes[] = new StateVariableNode(
                name:         ltrim($prop->name->toString(), '$'),
                solidityType: $solidityType,
                visibility:   $visibility,
                mutability:   $mutability,
                defaultValue: $defaultValue,
            );
        }

        return $nodes;
    }

    // ─────────────────────────────────────────────────────────
    // Parse a method/function
    // ─────────────────────────────────────────────────────────

    private function parseMethod(ClassMethod $method): FunctionNode
    {
        $isConstructor = $method->name->toString() === '__construct'
            || $this->hasAttribute($method, 'Constructor');

        // Visibility
        $visibility = 'public';
        if ($this->hasAttribute($method, 'External'))        $visibility = 'external';
        elseif ($this->hasAttribute($method, 'Internal'))    $visibility = 'internal';
        elseif ($this->hasAttribute($method, 'SolidityPrivate')) $visibility = 'private';
        elseif ($isConstructor)                              $visibility = '';

        // Mutability
        $mutability = '';
        if ($this->hasAttribute($method, 'View'))            $mutability = 'view';
        elseif ($this->hasAttribute($method, 'Pure'))        $mutability = 'pure';
        elseif ($this->hasAttribute($method, 'Payable'))     $mutability = 'payable';

        // Return type
        $returnType = '';
        if ($method->returnType !== null && ! $isConstructor) {
            $phpReturnType = $this->nodeToTypeString($method->returnType);
            if (! $this->typeMapper->isVoid($phpReturnType)) {
                $returnType = $this->typeMapper->toSolidity($phpReturnType);
            }
        }

        // Parameters
        $params = $this->parseParameters($method->params);

        // Applied modifiers
        $appliedModifiers = [];
        if ($this->hasAttribute($method, 'Guarded')) {
            $guardedMods = $this->getAttributeArgs($method, 'Guarded');
            $appliedModifiers = array_values($guardedMods);
        }

        // Body
        $body = $this->parseBody($method->stmts ?? []);

        $name = $isConstructor ? 'constructor' : $method->name->toString();

        return new FunctionNode(
            name:          $name,
            visibility:    $visibility,
            mutability:    $mutability,
            returnType:    $returnType,
            isConstructor: $isConstructor,
            params:        $params,
            modifiers:     $appliedModifiers,
            body:          $body,
        );
    }

    // ─────────────────────────────────────────────────────────
    // Parse a modifier method
    // ─────────────────────────────────────────────────────────

    private function parseModifier(ClassMethod $method): ModifierNode
    {
        $params = $this->parseParameters($method->params);
        $body   = $this->parseBody($method->stmts ?? []);

        return new ModifierNode(
            name:   $method->name->toString(),
            params: $params,
            body:   $body,
        );
    }

    // ─────────────────────────────────────────────────────────
    // Parse parameters
    // ─────────────────────────────────────────────────────────

    /**
     * @param Node\Param[] $params
     * @return ParameterNode[]
     */
    private function parseParameters(array $params): array
    {
        $nodes = [];

        foreach ($params as $param) {
            $phpType      = $param->type ? $this->nodeToTypeString($param->type) : 'uint256';
            $solidityType = $this->typeMapper->toSolidity($phpType);
            $name         = ltrim($param->var->name, '$');
            $default      = $param->default
                ? $this->translateExpression($this->printer->prettyPrintExpr($param->default))
                : null;

            $nodes[] = new ParameterNode($name, $solidityType, $default);
        }

        return $nodes;
    }

    // ─────────────────────────────────────────────────────────
    // Parse method body
    // ─────────────────────────────────────────────────────────

    /** @param Node\Stmt[] $stmts */
    private function parseBody(array $stmts): string
    {
        if (empty($stmts)) {
            return '';
        }

        $lines = [];
        foreach ($stmts as $stmt) {
            $phpLine = $this->printer->prettyPrint([$stmt]);
            $lines[] = $this->translateStatement($phpLine);
        }

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────
    // PHP → Solidity translation helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Translate a PHP statement string to Solidity.
     * This handles the common patterns.
     */
    private function translateStatement(string $php): string
    {
        $sol = $php;

        // $this->property → property (storage vars)
        $sol = preg_replace('/EVM::require\(/', 'require(', $sol);
        $sol = preg_replace('/EVM::revert\(/',  'revert(',  $sol);
        $sol = preg_replace('/EVM::assert\(/',  'assert(',  $sol);
        $sol = preg_replace('/\$this->(\w+)/', '$1', $sol);

        // msg::sender() → msg.sender
        $sol = str_replace('msg::sender()', 'msg.sender', $sol);
        $sol = str_replace('msg::value()', 'msg.value', $sol);
        $sol = str_replace('msg::data()', 'msg.data', $sol);

        // block::timestamp() → block.timestamp
        $sol = str_replace('block::timestamp()', 'block.timestamp', $sol);
        $sol = str_replace('block::number()', 'block.number', $sol);
        $sol = str_replace('block::chainid()', 'block.chainid', $sol);

        // tx::origin() → tx.origin
        $sol = str_replace('tx::origin()', 'tx.origin', $sol);

        // Event::emit('Transfer', ...) → emit Transfer(...)
        $sol = preg_replace(
            "/Event::emit\('(\w+)',\s*(.*)\)/",
            'emit $1($2)',
            $sol
        );

        // address::zero() → address(0)
        $sol = str_replace('address::zero()', 'address(0)', $sol);

        // require() — already matches Solidity syntax
        // Remove variable sigil from local vars
        $sol = preg_replace('/\$(\w+)/', '$1', $sol);

        // Remove semicolons that printer adds (we add them in emitter)
        // Actually keep them — the body will be indented as-is

        return $sol;
    }

    private function translateExpression(string $php): string
    {
        return $this->translateStatement($php);
    }

    // ─────────────────────────────────────────────────────────
    // Attribute helpers
    // ─────────────────────────────────────────────────────────

    private function hasAttribute(Node $node, string $attrName): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->getLast();
                if ($name === $attrName) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getAttributeArg(Node $node, string $attrName, string $argName): mixed
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->getLast() === $attrName) {
                    foreach ($attr->args as $arg) {
                        if ($arg->name?->toString() === $argName) {
                            return $this->resolveScalar($arg->value);
                        }
                    }
                }
            }
        }
        return null;
    }

    private function getAttributeArgs(Node $node, string $attrName): array
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->getLast() === $attrName) {
                    return array_map(
                        fn($arg) => $this->resolveScalar($arg->value),
                        $attr->args
                    );
                }
            }
        }
        return [];
    }

    private function getContractAttribute(Class_ $class): array
    {
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->getLast() === 'Contract') {
                    $result = [];
                    foreach ($attr->args as $arg) {
                        if ($arg->name) {
                            $result[$arg->name->toString()] = $this->resolveScalar($arg->value);
                        }
                    }
                    return $result;
                }
            }
        }
        return [];
    }

    private function resolveScalar(Node\Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\String_)  return $expr->value;
        if ($expr instanceof Node\Scalar\Int_)     return $expr->value;
        if ($expr instanceof Node\Scalar\Float_)   return $expr->value;
        if ($expr instanceof Node\Expr\ConstFetch) return $expr->name->toString() === 'true';
        return null;
    }

    private function extractInherits(Class_ $class): array
    {
        $parents = [];
        if ($class->extends) {
            $parents[] = $class->extends->getLast();
        }

        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->getLast() === 'Inherits') {
                    foreach ($attr->args as $arg) {
                        // ::class reference → last part of class name
                        if ($arg->value instanceof Node\Expr\ClassConstFetch) {
                            $parents[] = $arg->value->class->getLast();
                        }
                    }
                }
            }
        }

        return $parents;
    }

    /** @return EventNode[] */
    private function extractEvents(Class_ $class): array
    {
        $events = [];

        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->getLast() === 'SolidityEvent') {
                    $args = $attr->args;

                    $eventName = $args[0]->value instanceof Node\Scalar\String_
                        ? $args[0]->value->value
                        : 'UnnamedEvent';

                    $params = [];
                    if (isset($args[1]) && $args[1]->value instanceof Node\Expr\Array_) {
                        foreach ($args[1]->value->items as $item) {
                            if ($item->value instanceof Node\Scalar\String_) {
                                $parts   = explode(' ', trim($item->value->value));
                                $name    = array_pop($parts);
                                $indexed = in_array('indexed', $parts, true);
                                $type    = implode(' ', array_filter($parts, fn($p) => $p !== 'indexed'));

                                $params[] = new EventParamNode($type, $name, $indexed);
                            }
                        }
                    }

                    $events[] = new EventNode($eventName, $params);
                }
            }
        }

        return $events;
    }

    // ─────────────────────────────────────────────────────────
    // Type helpers
    // ─────────────────────────────────────────────────────────

    private function extractPropertyType(Property $property): string
    {
        if ($property->type !== null) {
            return $this->nodeToTypeString($property->type);
        }

        // Try to infer from the docblock (future enhancement)
        return 'uint256';
    }

    private function nodeToTypeString(Node $type): string
    {
        if ($type instanceof Node\Name)               return $type->toString();
        if ($type instanceof Node\Identifier)         return $type->toString();
        if ($type instanceof Node\Name\FullyQualified) return $type->getLast();
        if ($type instanceof Node\NullableType)       return $this->nodeToTypeString($type->type);

        return 'uint256'; // safe default
    }
}