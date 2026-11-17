# Understanding ASTs

This page explains what a syntax tree is, why a transpiler needs one, and how this project uses two of them. You do not need any compiler background. Every code sample in this page is real output from the tool, so you can reproduce all of it.

## 1. The problem with text

Consider this line of PHP:

```php
$this->count += 1;
```

To a computer it is just 18 characters. To translate it to Solidity (`count += 1;`) we have to know what the characters *mean*: that `$this->count` is a property being read and written, that `+=` is an assignment that also adds, and that `1` is a number. Searching for the text `$this->` and deleting it works for this line, and fails for thousands of others, for example:

```php
EVM::revert("Do not use $this-> in messages");   // the search-and-replace edits a string
```

What we need is a representation where the structure is already worked out, so we never have to guess from characters.

## 2. What an AST is

An **abstract syntax tree** (AST) is a tree that records the *structure* of a piece of code and throws away the punctuation. Parentheses, semicolons and whitespace are gone, because their only job was to tell the reader where things start and stop, and in a tree that is shown by nesting.

Here is `$this->count += 1;` as a tree, drawn by hand:

```
Expression statement
└── AssignOp (+=)
    ├── var:  PropertyFetch
    │         ├── var:  Variable "this"
    │         └── name: "count"
    └── expr: Integer 1
```

Each box is a **node**. A node has a type (`AssignOp`), and **children** that fill in its parts (`var` and `expr`). Leaves, such as `Integer 1`, have no children. The word *abstract* means we keep what the code says and drop how it was spelled.

You can see the real thing. This is `phpsolidity ast file.php --php` on that one line (produced by the parser library [nikic/php-parser](https://github.com/nikic/PHP-Parser)):

```
array(
    0: Stmt_Expression(
        expr: Expr_AssignOp_Plus(
            var: Expr_PropertyFetch(
                var: Expr_Variable(
                    name: this
                )
                name: Identifier(
                    name: count
                )
            )
            expr: Scalar_Int(
                value: 1
            )
        )
    )
)
```

It is the same tree as the hand drawing. `Expr_AssignOp_Plus` is the `+=`, `Expr_PropertyFetch` is `->count`, `Scalar_Int` is the `1`.

## 3. Why trees make translation easy

Once code is a tree, translating it is a **walk**: visit a node, translate its children, combine the results. For the tree above:

1. See `Expr_AssignOp_Plus`. Its Solidity form is `<left> += <right>`.
2. Translate the left child. It is a `PropertyFetch` on `this`, which means "state variable", so the result is just the name: `count`.
3. Translate the right child. It is an integer: `1`.
4. Combine: `count += 1`. Add a `;` because it is a statement.

This is exactly what `BodyEmitter` does. Open `src/Emitter/BodyEmitter.php` and look at `expr()`: it is one `match` that picks a translation for each kind of node, and each translation calls `expr()` again on its children. A function that calls itself on the children of a tree node is called **recursive descent**, and it is the standard way to process trees.

## 4. Trees fix real bugs

### Grouping

PHP and Solidity do not agree on operator priority. In PHP, `==` binds tighter than `&`. In Solidity it is the other way around. So this PHP:

```php
return $a & $b == $c;
```

means "AND `$a` with the result of comparing `$b` and `$c`". The parser shows that grouping explicitly:

```
Expr_BinaryOp_BitwiseAnd(
    left: Expr_Variable(name: a)
    right: Expr_BinaryOp_Equal(
        left: Expr_Variable(name: b)
        right: Expr_Variable(name: c)
    )
)
```

If you copied the text across to Solidity unchanged, it would silently mean `(a & b) == c`, a different program with no error. Because the translator sees the tree, it writes the grouping out: `a & (b == c)`. Nested operations are always parenthesised in the output, so the meaning never depends on which language's priority rules you remember.

### Names

`$owner` (a parameter) and `$this->owner` (a state variable) are different things in PHP. In the tree they are different nodes, `Variable` and `PropertyFetch`. Text replacement flattens both to `owner`, so a parameter silently hides the state variable. The tree walk keeps them apart and renames the parameter to `owner_`.

### Types

To write `uint256 total = a + b;` the translator must know that `a + b` is a `uint256`. In the tree, `a` is a `Variable` node, and we can look up its type in a table of the function's parameters. Text offers no such handle.

## 5. The two trees in this project

```
 PHP text ──php-parser──▶ PHP tree ──ContractParser──▶ Contract tree ──emitters──▶ Solidity text
```

**The PHP tree** describes a PHP file. It contains `Stmt_Class`, `Stmt_ClassMethod`, `Stmt_If`, `Expr_BinaryOp_Plus`, and so on. It is large because PHP is a large language.

**The Contract tree** describes a contract. It is small. Print it with `phpsolidity ast`:

```
$ phpsolidity ast tests/fixtures/contracts/Counter.php
SourceUnit (license: MIT, pragma: ^0.8.20)
├─ Contract Counter
│   ├─ StateVariable uint256 count (public)
│   ├─ Event CountChanged(address indexed by, uint256 newCount)
│   └─ Function increment() [external]
│       · Stmt_Expression  $this->count += 1;
│       · Stmt_Expression  Event::emit('CountChanged', msg::sender(), $this->count);
│   └─ Function decrement() [external]
│       · Stmt_Expression  EVM::require($this->count > 0, 'Counter: already zero');
│       · Stmt_Expression  $this->count -= 1;
│       · Stmt_Expression  Event::emit('CountChanged', msg::sender(), $this->count);
│   └─ Function current() [external view] returns uint256
│       · Stmt_Return      return $this->count;
```

Compare it with the PHP tree of the same file: nothing here mentions classes, attributes or `private`. `#[External]` has already become `[external]`, and `#[Storage(public: true)]` has become `(public)`. The classes live in `src/AST/Nodes/`:

| Node | Meaning |
| --- | --- |
| `SourceUnitNode` | One `.sol` file: pragma, imports, and a list of declarations |
| `ContractNode` | A contract, abstract contract, interface or library |
| `StateVariableNode` | A variable stored on chain, with visibility and mutability |
| `FunctionNode` | A function, constructor, `receive` or `fallback` |
| `ModifierNode` | A Solidity modifier |
| `EventNode`, `ErrorNode` | Event and custom error declarations |
| `StructNode`, `EnumNode` | User-defined types |
| `ParameterNode` | A typed parameter |

The statement lines at the bottom of each function show the one place where the two trees meet: function bodies are kept as PHP tree nodes inside the Contract tree and translated at the very end.

### Why have a second tree at all?

We could translate straight from the PHP tree. Splitting it in two buys three things:

1. **The emitter stays simple.** Deciding how a Solidity file is laid out (state variables, then events, then modifiers, then the constructor) is easy when those are already separate lists, and miserable when they are mixed together as class members.
2. **Attributes are interpreted once.** `ContractParser` is the only place that knows `#[External, View]` means `external view`. Everything after it works with plain facts.
3. **It is a stable seam.** A future front end (another input language) or back end (another output format) only has to produce or consume the Contract tree.

## 6. Walking a tree yourself

php-parser ships two tools for walking: a `NodeTraverser`, which visits every node and calls your visitor, and a `NodeFinder`, which collects nodes matching a condition. The validator uses the first. Here is the simplest possible rule, finding every `foreach`:

```php
use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);

$traverser = new NodeTraverser();
$traverser->addVisitor(new class extends NodeVisitorAbstract {
    public function enterNode(Node $node)
    {
        if ($node instanceof Foreach_) {
            echo "foreach on line {$node->getStartLine()}\n";
        }
    }
});
$traverser->traverse($ast);
```

Every node carries its source position, which is where the line numbers in our error messages come from. A regular expression over the text could not give you a reliable line number, and it would also match the word `foreach` inside a comment.

## 7. Try it

1. Run `phpsolidity ast examples/MyToken.php --php | less` and find the node for `$this->balances[$to] += $amount;`. Notice that `balances[$to]` is an `Expr_ArrayDimFetch` wrapped around an `Expr_PropertyFetch`.
2. Write `return $a - $b - $c;`, dump it, and see which `-` is the outer node. (It is the right-most, because subtraction groups from the left. That is why the output reads `(a - b) - c`.)
3. Write a function with `$x = 5;` followed by `$x = 6;`. Look at the Solidity output: the first assignment declares `uint256 x`, the second is a plain assignment. In `BodyEmitter::expressionStatement()` find where that decision is made.
4. Add a rule to the validator that warns on `while (true)` (see [Architecture](architecture.md#adding-a-validator-rule)).

## Further reading

- [PHP-Parser documentation](https://github.com/nikic/PHP-Parser/tree/master/doc): node types, traversal, pretty printing.
- [Crafting Interpreters](https://craftinginterpreters.com/): a free book that builds parsers and tree walkers step by step.
- [Solidity documentation](https://docs.soliditylang.org/): the target language.
