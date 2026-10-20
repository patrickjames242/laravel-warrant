<?php

namespace Warrant\Builders;

use Warrant\Builders\Concerns\PlacesNodes;
use Warrant\Builders\Concerns\TargetsSchema;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * A cross-schema ability reference — `can(<ability> [for <handle>] [as <alias>]
 * [with <map>])` — made by {@see Build::can()}: does the user hold the ability on
 * another schema? It consults that schema's whole rule set.
 *
 * ```php
 * Build::can('approve')->for(PayPeriod::class, Ref::context('period_id'))
 * ```
 *
 * Without {@see for()} the reference crosses to no schema at all: another
 * ability of the schema the rule is written on, over the row and context it
 * already has, which takes no key, `with` or alias. Supplying one anyway is
 * reported by validation.
 *
 * It holds what it was given and makes its node when it is resolved, in the
 * source of the syntax resolving it.
 */
final class CrossSchemaCanBuilder
{
    use PlacesNodes;
    use TargetsSchema;

    public function __construct(private readonly string $ability) {}

    public function toNode(SyntaxSource $source): CrossSchemaCanNode
    {
        return new CrossSchemaCanNode(
            $this->schemaKeyNode($source),
            self::nameNode($this->ability, $source),
            $this->isRowBound,
            $this->keyArgumentNodes($source),
            $this->withEntryNodes($source),
            $this->aliasNode($source),
            self::location($source),
        );
    }
}
