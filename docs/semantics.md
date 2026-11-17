# PHP vs Solidity semantics

The syntax is PHP. **The meaning is Solidity's.** The translator moves your code across; it does not make Solidity behave like PHP. This page lists the places where the same-looking code does something different, so none of it surprises you on chain.

## Integers

PHP integers are 64-bit and silently become floats when they overflow. Solidity integers have an explicit width and a fixed range.

| | PHP | Solidity (this project) |
| --- | --- | --- |
| Overflow | Becomes a float | **Reverts** (checked arithmetic since 0.8) |
| `/` | Float result when not divisible | **Integer division**, truncates toward zero |
| `7 / 2` | `3.5` | `3` |
| `%` | Sign follows the dividend | Same |
| Underflow of unsigned | Negative number | **Reverts** (`0 - 1` on a `uint256`) |
| `int` | 64-bit | `int256` |
| Literals | Up to `PHP_INT_MAX` | Up to 256 bits, but you can only *write* what PHP can parse; build bigger ones with `10 ** 18` |

Checked arithmetic is a safety feature: an overflow stops the transaction instead of wrapping. Remember it when you design: `$a - $b` with `$b > $a` on unsigned types is a revert, not a negative number. Compare first if you need to handle that case.

Because there are no floats, money is stored as integers in the smallest unit (wei, or 10 ** decimals tokens) and fractions are expressed by scaling:

```php
// 2.5% fee on $amount
$fee = ($amount * 25) / 1000;
```

Multiply before dividing. Division truncates, so `(a / b) * c` loses precision that `(a * c) / b` keeps.

## Equality

`==` and `===` both become `==`. Solidity has no loose comparison: `"1" == 1` does not type juggle, and mixing types is a compile error in `solc`. Likewise `!=` and `!==` become `!=`.

## Operator priority

PHP and Solidity rank some operators differently. The notable case is bitwise AND/OR/XOR versus comparisons:

| Expression | PHP groups it as | Solidity would group the same text as |
| --- | --- | --- |
| `$a & $b == $c` | `$a & ($b == $c)` | `($a & $b) == $c` |

The translator reads the PHP grouping from the syntax tree and writes parentheses around every nested operation, so the Solidity output means what the PHP *syntax tree* means. The `solc` type checker then rejects nonsense such as `uint256 & bool`. If you meant `($a & $b) == $c`, write the parentheses in the PHP. See [Understanding ASTs](understanding-asts.md#4-trees-fix-real-bugs).

## Evaluation order

Solidity does not define the order in which sub-expressions are evaluated. Avoid putting two side effects in one expression (`$a[$i++] = $i`, or a call whose result depends on another call in the same statement). Use separate statements.

## Variables and scope

- **Block scope.** A variable declared inside an `if`, `for` or `while` body exists only there. PHP keeps it for the rest of the function. The translator reports an error when code relies on PHP's looser rule.
- **Declaration is by first assignment**, and the type is inferred or taken from a `@var` docblock. There is no untyped variable.
- **No null, no undefined.** Every variable starts at the zero value of its type: `0`, `false`, the zero address, an empty string. Reading a mapping key that was never set returns that zero value instead of failing. Use this deliberately, and do not use `isset()`; compare with zero.
- **`$this->x` is a state variable**, stored on chain and costing gas to write. `$x` is a local, held in memory and free after the call.
- **Parameters that collide** with a state variable are renamed `name_`.

## Strings and bytes

Solidity strings are byte arrays with no built-in operations. You can concatenate (`.` becomes `string.concat`), compare by hash, and pass them around. You cannot index them, slice them or get their length without converting to `bytes`. There is no interpolation, `sprintf` or `strlen`.

## Values and references

In PHP, objects are passed by handle. In Solidity, reference types (arrays, structs, strings) are either copied into memory or referenced in storage, depending on where the variable lives. By default, a local initialised from storage is a **copy** (`memory`), so changing it does not change the stored value. To get a pointer into storage, declare it with `@var Type storage` (see [Local variables](language-reference.md#local-variables)).

## Mappings and arrays

- Mappings cannot be iterated, counted or cleared. Keep a separate array of keys if you need them.
- `unset($m[$k])` resets that entry to the zero value (`delete`); it does not shrink anything.
- `count($array)` is `array.length`. Appending with `$a[] = $v` is `push`. Reading past the end reverts.
- Loops over storage arrays cost gas on every iteration. Unbounded loops can make a function impossible to call. Keep them bounded.

## Errors

PHP has exceptions. Solidity has **reverts**: the whole transaction is undone and the caller receives the error. Use `EVM::require()`, `EVM::revert()` and `EVM::revertWith()`. `try`/`catch` and `throw` are not supported.

## What does not exist

No floats, no `null`, no closures, no `foreach`, no file or network access, no randomness, no clocks except `block::timestamp()`, no `static` or global variables, no references (`&`), no string interpolation. The [validator](validator.md) names each one when you use it.

## Gas

Every operation costs gas, and writing to state costs the most. Code that is idiomatic PHP can be expensive on chain: re-reading `$this->items[$i]` in a loop, or copying large structs to memory. The transpiler does not optimise for gas. It translates what you wrote.
