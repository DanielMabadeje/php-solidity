<?php
declare(strict_types=1);
namespace PhpSolidity\Attributes;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Guarded
{
    /** @var string[] */
    public readonly array $modifiers;

    public function __construct(string ...$modifiers)
    {
        $this->modifiers = $modifiers;
    }
}
