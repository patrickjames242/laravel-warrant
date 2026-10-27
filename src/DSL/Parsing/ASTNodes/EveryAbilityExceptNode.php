<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * `* except <abilities>` in an ability list: every ability the schema declares
 * but the ones it leaves out. It stands wherever `*` may, and like `*` alone
 * in its list.
 */
final readonly class EveryAbilityExceptNode implements INode
{
    /**
     * @param NameNode $star The `*` as written.
     * @param list<NameNode|BindingNode> $exceptions The abilities left out, and
     *   bindings whose values name them until expansion puts those names in
     *   their place.
     */
    public function __construct(
        public NameNode $star,
        public array $exceptions,
        public SyntaxLocation $location,
    ) {}

    public function children(): array
    {
        return [$this->star, ...$this->exceptions];
    }
}
