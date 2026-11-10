<?php

namespace Warrant\Builders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Warrant\Builders\NoRow;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\IHandleNode;
use Warrant\DSL\Parsing\ASTNodes\Quantifier;
use Warrant\DSL\Parsing\ASTNodes\RelationHandleNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaHandleNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * The handle half of a cross-schema reference — `for <schema>[(<key>)] [as
 * <alias>] [with <map>]` — shared by `can(...)` and `check(...)`. The class
 * using it uses {@see PlacesNodes} too.
 */
trait TargetsSchema
{
    private ?string $schemaKey = null;

    private ?string $relation = null;

    private mixed $key = null;

    private bool $isRowBound = false;

    private ?Quantifier $quantifier = null;

    /** @var array<string, mixed> */
    private array $with = [];

    private ?string $as = null;

    /**
     * The schema the reference crosses to, and the row it selects there.
     *
     * The schema is resolved to its key here, through the registry, so the node
     * carries the key the parser would have lexed.
     *
     * @param Model|ISchema|string $schema A schema key, a schema instance or
     *   class-string, or a model instance or class-string.
     * @param mixed $key The row selector: a key, the target schema's model, a
     *   {@see \Warrant\Builders\Ref}, a list of these for a key of several parts,
     *   or {@see NoRow} (the default) for an unbound handle. An explicit `null`
     *   stays row-bound and is rejected at validation; only omitting the argument
     *   means "no row".
     */
    public function for(Model|ISchema|string $schema, mixed $key = new NoRow): static
    {
        $this->schemaKey = Warrant::registry()->resolveSchemaKeyOrFail($schema);
        $this->relation = null;
        $this->key = $key;
        $this->isRowBound = ! $key instanceof NoRow;
        $this->quantifier = null;

        return $this;
    }

    /**
     * As {@see for()}, asking whether any of the rows the key matches passes:
     * `for any of <schema>(<key>)`.
     */
    public function forAnyOf(Model|ISchema|string $schema, mixed $key = new NoRow): static
    {
        $this->for($schema, $key);
        $this->quantifier = Quantifier::Any;

        return $this;
    }

    /**
     * As {@see for()}, asking whether the key matches at least one row and every
     * one of them passes: `for all of <schema>(<key>)`.
     */
    public function forAllOf(Model|ISchema|string $schema, mixed $key = new NoRow): static
    {
        $this->for($schema, $key);
        $this->quantifier = Quantifier::All;

        return $this;
    }

    /**
     * The rows a row relation of the schema the rule is for reaches from the row
     * it is about: `for its <relation>`. It replaces a {@see for()} on the same
     * reference, as each of these replaces the others.
     *
     * @param string $relation The relation's name, as rule text writes it.
     */
    public function its(string $relation): static
    {
        $this->relation = $relation;
        $this->schemaKey = null;
        $this->key = null;
        $this->isRowBound = true;
        $this->quantifier = null;

        return $this;
    }

    /**
     * As {@see its()}, asking whether any of the related rows passes:
     * `for any of its <relation>`.
     */
    public function anyOfIts(string $relation): static
    {
        $this->its($relation);
        $this->quantifier = Quantifier::Any;

        return $this;
    }

    /**
     * As {@see its()}, asking whether there is at least one related row and every
     * one of them passes: `for all of its <relation>`.
     */
    public function allOfIts(string $relation): static
    {
        $this->its($relation);
        $this->quantifier = Quantifier::All;

        return $this;
    }

    /**
     * Names the rows this reference selects, so a {@see \Warrant\Builders\Ref::column()}
     * can tell them apart from a table of the same name already in scope further
     * out. Requires a row key.
     */
    public function as(string $alias): static
    {
        $this->as = $alias;

        return $this;
    }

    /**
     * Explicit boundary context for the target schema, keyed by its context key
     * names; values are scalars or {@see \Warrant\Builders\Ref}s.
     *
     * @param array<string, mixed> $with
     */
    public function with(array $with): static
    {
        $this->with = $with;

        return $this;
    }

    private function handleNode(SyntaxSource $source): ?IHandleNode
    {
        if ($this->relation !== null) {
            return new RelationHandleNode(self::nameNode($this->relation, $source), $this->quantifier, self::location($source));
        }

        if ($this->schemaKey === null) {
            return null;
        }

        return new SchemaHandleNode(
            self::nameNode($this->schemaKey, $source),
            $this->isRowBound,
            $this->keyArgumentNodes($source),
            $this->quantifier,
            self::location($source),
        );
    }

    /**
     * The handle's key arguments. A list is the arguments themselves, which is
     * how a key of several parts is written; anything else is a single argument.
     * Keys are dropped from an associative array: arguments bind positionally to
     * the key's parameters, exactly as a condition's do.
     *
     * @return list<INode>
     */
    private function keyArgumentNodes(SyntaxSource $source): array
    {
        if (! $this->isRowBound) {
            return [];
        }

        $arguments = is_array($this->key) ? array_values($this->key) : [$this->key];

        return array_map(fn (mixed $argument): INode => self::argumentNode($argument, $source), $arguments);
    }

    /** @return list<WithEntryNode> */
    private function withEntryNodes(SyntaxSource $source): array
    {
        $entries = [];

        foreach ($this->with as $name => $value) {
            $entries[] = new WithEntryNode(self::nameNode($name, $source), self::argumentNode($value, $source), self::location($source));
        }

        return $entries;
    }

    private function aliasNode(SyntaxSource $source): ?NameNode
    {
        return $this->as === null ? null : self::nameNode($this->as, $source);
    }
}
