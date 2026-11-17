<?php

declare(strict_types=1);

namespace PhpSolidity\Parser;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\ParserFactory;
use PhpSolidity\AST\Nodes\ContractNode;
use PhpSolidity\AST\Nodes\EnumNode;
use PhpSolidity\AST\Nodes\ErrorNode;
use PhpSolidity\AST\Nodes\EventNode;
use PhpSolidity\AST\Nodes\EventParamNode;
use PhpSolidity\AST\Nodes\FunctionNode;
use PhpSolidity\AST\Nodes\ModifierNode;
use PhpSolidity\AST\Nodes\ParameterNode;
use PhpSolidity\AST\Nodes\SourceUnitNode;
use PhpSolidity\AST\Nodes\StateVariableNode;
use PhpSolidity\AST\Nodes\StructNode;
use PhpSolidity\Exceptions\CompileException;
use PhpSolidity\Exceptions\ParseException;
use PhpSolidity\TypeSystem\TypeMapper;

/**
 * Stage 1 of the pipeline: PHP source → Contract IR.
 *
 * nikic/php-parser turns the PHP text into a PHP syntax tree. This class walks that tree and
 * builds our own, much smaller tree (SourceUnitNode → ContractNode → FunctionNode ...) that
 * describes a *contract* rather than a PHP file. Function bodies are kept as php-parser
 * statements and translated later by BodyEmitter, so no information is lost on the way.
 */
class ContractParser
{
    public function __construct(
        private readonly TypeMapper $types = new TypeMapper(),
    ) {}

    /** @throws ParseException */
    public function parseFile(string $filePath): ContractNode
    {
        return $this->primaryOf($this->parseSourceUnitFile($filePath));
    }

    /** @throws ParseException */
    public function parseSourceUnitFile(string $filePath): SourceUnitNode
    {
        if (! is_file($filePath)) {
            throw new ParseException("File not found: {$filePath}");
        }

        return $this->parseSourceUnit((string) file_get_contents($filePath));
    }

    /**
     * Parse PHP source and return the primary contract (kept for backwards compatibility).
     *
     * @throws ParseException
     */
    public function parseSource(string $source): ContractNode
    {
        return $this->primaryOf($this->parseSourceUnit($source));
    }

    /**
     * Parse PHP source into everything that belongs in one `.sol` file.
     *
     * @throws ParseException
     * @throws CompileException
     */
    public function parseSourceUnit(string $source): SourceUnitNode
    {
        $stmts = $this->parsePhp($source);

        /** @var array<Class_|Interface_|Enum_> $declared */
        $declared = [];
        $this->collectDeclarations($stmts, $declared);

        $declarations = [];
        $imports      = [];

        foreach ($declared as $node) {
            foreach (AttributeReader::all($node, 'Import') as $import) {
                $path = AttributeReader::arguments($import)['path'] ?? null;
                if (is_string($path) && ! in_array($path, $imports, true)) {
                    $imports[] = $path;
                }
            }

            if ($node instanceof Enum_) {
                $declarations[] = $this->buildEnum($node);
            } elseif ($node instanceof Interface_) {
                $declarations[] = $this->buildInterface($node);
            } elseif (AttributeReader::has($node, 'Struct')) {
                $declarations[] = $this->buildStruct($node);
            } elseif (AttributeReader::has($node, 'Library')) {
                $declarations[] = $this->buildContract($node, 'library');
            } elseif (AttributeReader::has($node, 'Contract')) {
                $declarations[] = $this->buildContract($node, $node->isAbstract() ? 'abstract contract' : 'contract');
            }
        }

        $unit = new SourceUnitNode('MIT', '^0.8.20', $imports, $declarations);

        if ($unit->primary() === null) {
            throw new ParseException(
                'No #[Contract] class found. Mark your class with the #[Contract] attribute.'
            );
        }

        $primary = $unit->primary();

        return new SourceUnitNode($primary->license, $primary->solidityVersion, $imports, $declarations);
    }

    // ─────────────────────────────────────────────────────────
    // php-parser entry points
    // ─────────────────────────────────────────────────────────

    /** @return Stmt[] */
    private function parsePhp(string $source): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $stmts = $parser->parse($source);
        } catch (\PhpParser\Error $e) {
            throw new ParseException(
                'PHP parse error: ' . $e->getRawMessage(),
                $e->getStartLine() > 0 ? $e->getStartLine() : null,
                $e,
            );
        }

        return $stmts ?? [];
    }

    /**
     * @param Node[]                            $stmts
     * @param array<Class_|Interface_|Enum_>    $out
     */
    private function collectDeclarations(array $stmts, array &$out): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $this->collectDeclarations($stmt->stmts, $out);
            } elseif ($stmt instanceof Class_ || $stmt instanceof Interface_ || $stmt instanceof Enum_) {
                $out[] = $stmt;
            }
        }
    }

    private function primaryOf(SourceUnitNode $unit): ContractNode
    {
        $primary = $unit->primary();
        if ($primary === null) {
            throw new ParseException('No #[Contract] class found. Mark your class with the #[Contract] attribute.');
        }

        return $primary;
    }

    // ─────────────────────────────────────────────────────────
    // Enums and structs
    // ─────────────────────────────────────────────────────────

    private function buildEnum(Enum_ $enum): EnumNode
    {
        $cases = [];
        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof Stmt\EnumCase) {
                $cases[] = $stmt->name->toString();
            }
        }

        return new EnumNode($enum->name?->toString() ?? '', $cases, $enum->getStartLine());
    }

    private function buildStruct(Class_ $class): StructNode
    {
        $members = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property && ! $stmt->isStatic()) {
                foreach ($stmt->props as $prop) {
                    $members[] = new ParameterNode(
                        $prop->name->toString(),
                        $this->resolveType($stmt, $stmt->type, $stmt->getDocComment()?->getText(), 'struct member $' . $prop->name->toString(), $stmt->getStartLine()),
                    );
                }
            }

            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $members[] = new ParameterNode(
                            $param->var->name,
                            $this->resolveType($param, $param->type, null, 'struct member $' . $param->var->name, $param->getStartLine()),
                        );
                    }
                }
            }
        }

        return new StructNode($class->name?->toString() ?? '', $members, $class->getStartLine());
    }

    // ─────────────────────────────────────────────────────────
    // Contracts, libraries and interfaces
    // ─────────────────────────────────────────────────────────

    private function buildContract(Class_ $class, string $kind): ContractNode
    {
        $attribute = AttributeReader::find($class, $kind === 'library' ? 'Library' : 'Contract');
        $options   = $attribute !== null ? AttributeReader::arguments($attribute) : [];

        $stateVariables = [];
        $functions      = [];
        $modifiers      = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property && ! $stmt->isStatic()) {
                foreach ($this->buildStateVariables($stmt) as $variable) {
                    $stateVariables[] = $variable;
                }
            }

            if ($stmt instanceof ClassMethod) {
                if (AttributeReader::has($stmt, 'Modifier')) {
                    $modifiers[] = $this->buildModifier($stmt);
                } else {
                    $functions[] = $this->buildFunction($stmt, $kind);
                }
            }
        }

        $name = is_string($options['name'] ?? null) ? $options['name'] : ($class->name?->toString() ?? '');

        return new ContractNode(
            name:            $name,
            license:         is_string($options['license'] ?? null) ? $options['license'] : 'MIT',
            solidityVersion: is_string($options['version'] ?? null) ? $options['version'] : '^0.8.20',
            stateVariables:  $stateVariables,
            events:          $this->extractEvents($class),
            modifiers:       $modifiers,
            functions:       $functions,
            inherits:        $this->extractInherits($class),
            errors:          $this->extractErrors($class),
            kind:            $kind,
            line:            $class->getStartLine(),
        );
    }

    private function buildInterface(Interface_ $interface): ContractNode
    {
        $functions = [];
        foreach ($interface->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $functions[] = $this->buildFunction($stmt, 'interface');
            }
        }

        $inherits = array_map(static fn (Node\Name $n) => $n->getLast(), $interface->extends);

        return new ContractNode(
            name:            $interface->name?->toString() ?? '',
            license:         'MIT',
            solidityVersion: '^0.8.20',
            functions:       $functions,
            inherits:        $inherits,
            events:          $this->extractEvents($interface),
            errors:          $this->extractErrors($interface),
            kind:            'interface',
            line:            $interface->getStartLine(),
        );
    }

    // ─────────────────────────────────────────────────────────
    // State variables
    // ─────────────────────────────────────────────────────────

    /** @return StateVariableNode[] */
    private function buildStateVariables(Property $property): array
    {
        $isConstant  = AttributeReader::has($property, 'Constant');
        $isImmutable = AttributeReader::has($property, 'Immutable');
        $explicitPub = AttributeReader::argument($property, 'Storage', 'public') === true;

        $mutability = match (true) {
            $isConstant  => 'constant',
            $isImmutable => 'immutable',
            default      => '',
        };

        $visibility = match (true) {
            $explicitPub || $property->isPublic() => 'public',
            $property->isProtected()              => 'internal',
            default                               => 'private',
        };

        $nodes = [];

        foreach ($property->props as $prop) {
            $phpName = $prop->name->toString();

            $solidityType = $this->resolveType(
                $property,
                $property->type,
                $property->getDocComment()?->getText(),
                "property \${$phpName}",
                $property->getStartLine(),
            );

            $nodes[] = new StateVariableNode(
                name:         $phpName,
                solidityType: $solidityType,
                visibility:   $visibility,
                mutability:   $mutability,
                defaultValue: $prop->default,
                line:         $property->getStartLine(),
            );
        }

        return $nodes;
    }

    // ─────────────────────────────────────────────────────────
    // Functions and modifiers
    // ─────────────────────────────────────────────────────────

    private function buildFunction(ClassMethod $method, string $contractKind): FunctionNode
    {
        $phpName = $method->name->toString();

        $kind = match (true) {
            $phpName === '__construct' || AttributeReader::has($method, 'Constructor') => 'constructor',
            AttributeReader::has($method, 'Receive')                                  => 'receive',
            AttributeReader::has($method, 'Fallback')                                 => 'fallback',
            default                                                                   => 'function',
        };

        $isPayable = AttributeReader::has($method, 'Payable');

        $visibility = $this->functionVisibility($method, $kind, $contractKind);

        $mutability = match (true) {
            AttributeReader::has($method, 'View')   => 'view',
            AttributeReader::has($method, 'Pure')   => 'pure',
            $isPayable || $kind === 'receive'       => 'payable',
            default                                 => '',
        };

        $returnType = '';
        $override   = AttributeReader::argument($method, 'SolType', 'type');
        if (is_string($override)) {
            $returnType = $override;
        } elseif ($method->returnType !== null && $kind === 'function') {
            $phpReturn = $this->typeName($method->returnType, "return type of {$phpName}()", $method->getStartLine());
            if (! $this->types->isVoid($phpReturn)) {
                $returnType = $this->types->toSolidity($phpReturn);
            }
        }

        $guards = [];
        $guard  = AttributeReader::find($method, 'Guarded');
        if ($guard !== null) {
            $guards = array_values(array_filter(
                AttributeReader::values($guard),
                static fn ($v) => is_string($v) && $v !== '',
            ));
        }

        $name = match ($kind) {
            'constructor' => 'constructor',
            'receive'     => 'receive',
            'fallback'    => 'fallback',
            default       => $phpName,
        };

        return new FunctionNode(
            name:          $name,
            visibility:    $visibility,
            mutability:    $mutability,
            returnType:    $returnType,
            isConstructor: $kind === 'constructor',
            params:        $this->buildParameters($method->params),
            modifiers:     $guards,
            body:          $method->stmts,
            kind:          $kind,
            isVirtual:     AttributeReader::has($method, 'Virtual'),
            isOverride:    AttributeReader::has($method, 'Override'),
            line:          $method->getStartLine(),
        );
    }

    private function functionVisibility(ClassMethod $method, string $kind, string $contractKind): string
    {
        if ($kind === 'constructor') {
            return '';
        }

        if ($kind === 'receive' || $kind === 'fallback' || $contractKind === 'interface') {
            return 'external';
        }

        return match (true) {
            AttributeReader::has($method, 'External')        => 'external',
            AttributeReader::has($method, 'Internal')        => 'internal',
            AttributeReader::has($method, 'SolidityPrivate') => 'private',
            AttributeReader::has($method, 'SolidityPublic')  => 'public',
            $contractKind === 'library'                      => 'internal',
            $method->isPrivate()                             => 'private',
            $method->isProtected()                           => 'internal',
            default                                          => 'public',
        };
    }

    private function buildModifier(ClassMethod $method): ModifierNode
    {
        return new ModifierNode(
            name:   $method->name->toString(),
            params: $this->buildParameters($method->params),
            body:   $method->stmts ?? [],
            line:   $method->getStartLine(),
        );
    }

    /**
     * @param Node\Param[] $params
     * @return ParameterNode[]
     */
    private function buildParameters(array $params): array
    {
        $nodes = [];

        foreach ($params as $param) {
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '';

            $nodes[] = new ParameterNode(
                $name,
                $this->resolveType($param, $param->type, null, "parameter \${$name}", $param->getStartLine()),
                $param->default,
            );
        }

        return $nodes;
    }

    // ─────────────────────────────────────────────────────────
    // Class-level attributes
    // ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function extractInherits(Class_|Interface_ $class): array
    {
        $parents = [];

        if ($class instanceof Class_) {
            if ($class->extends !== null) {
                $parents[] = $class->extends->getLast();
            }
            foreach ($class->implements as $interface) {
                $parents[] = $interface->getLast();
            }
        }

        foreach (AttributeReader::all($class, 'Inherits') as $attribute) {
            foreach (AttributeReader::values($attribute) as $value) {
                if (is_string($value) && $value !== '' && ! in_array($value, $parents, true)) {
                    $parents[] = $value;
                }
            }
        }

        return $parents;
    }

    /** @return EventNode[] */
    private function extractEvents(Class_|Interface_ $class): array
    {
        $events = [];

        foreach (AttributeReader::all($class, 'SolidityEvent') as $attribute) {
            $args   = AttributeReader::arguments($attribute);
            $name   = is_string($args['name'] ?? null) ? $args['name'] : 'UnnamedEvent';
            $params = is_array($args['params'] ?? null) ? $args['params'] : [];

            $events[] = new EventNode($name, $this->parseSignatureParams($params));
        }

        return $events;
    }

    /** @return ErrorNode[] */
    private function extractErrors(Class_|Interface_ $class): array
    {
        $errors = [];

        foreach (AttributeReader::all($class, 'SolidityError') as $attribute) {
            $args   = AttributeReader::arguments($attribute);
            $name   = is_string($args['name'] ?? null) ? $args['name'] : 'UnnamedError';
            $params = is_array($args['params'] ?? null) ? $args['params'] : [];

            $errors[] = new ErrorNode($name, $this->parseSignatureParams($params));
        }

        return $errors;
    }

    /**
     * Parse ['address indexed from', 'uint256 value'] into EventParamNode objects.
     *
     * @param array<mixed> $params
     * @return EventParamNode[]
     */
    private function parseSignatureParams(array $params): array
    {
        $nodes = [];

        foreach ($params as $param) {
            if (! is_string($param) || trim($param) === '') {
                continue;
            }

            $parts   = preg_split('/\s+/', trim($param)) ?: [];
            $name    = array_pop($parts);
            $indexed = in_array('indexed', $parts, true);
            $type    = implode(' ', array_filter($parts, static fn ($p) => $p !== 'indexed'));

            $nodes[] = new EventParamNode($type, (string) $name, $indexed);
        }

        return $nodes;
    }

    // ─────────────────────────────────────────────────────────
    // Type resolution
    // ─────────────────────────────────────────────────────────

    /**
     * Work out the Solidity type of a property, parameter or struct member.
     *
     * Order of precedence: #[SolType] > #[Mapping] > `@sol-type` docblock > the PHP type.
     */
    private function resolveType(Node $holder, ?Node $phpType, ?string $docblock, string $what, int $line): string
    {
        $override = AttributeReader::argument($holder, 'SolType', 'type');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $mapping = AttributeReader::find($holder, 'Mapping');
        if ($mapping !== null) {
            $args = AttributeReader::arguments($mapping);
            if (is_string($args['key'] ?? null) && is_string($args['value'] ?? null)) {
                return "mapping({$args['key']} => {$args['value']})";
            }
        }

        if ($docblock !== null && preg_match('/@sol-type\s+([^\r\n*]+)/', $docblock, $m)) {
            return trim($m[1]);
        }

        if ($phpType === null) {
            throw new CompileException(
                "The {$what} has no type. Every value in a contract needs an explicit type (uint256, address, bool ...).",
                $line,
            );
        }

        $solidityType = $this->types->toSolidity($this->typeName($phpType, $what, $line));

        if ($solidityType === 'mapping') {
            throw new CompileException(
                "The {$what} is a mapping without key and value types. " .
                "Add #[Mapping('address', 'uint256')] above it.",
                $line,
            );
        }

        return $solidityType;
    }

    private function typeName(Node $type, string $what, int $line): string
    {
        if ($type instanceof Node\Name) {
            return $type->getLast();
        }

        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        throw new CompileException(
            "The {$what} uses a nullable, union or intersection type. Solidity has no such types; use one plain type.",
            $line,
        );
    }
}
