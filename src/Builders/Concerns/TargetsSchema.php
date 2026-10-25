<?php

namespace Warrant\Builders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Warrant\Builders\NoRow;
use Warrant\DSL\Parsing\ASTNodes\Node;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\Facades\Warrant;
use Warrant\Schema\WarrantSchema;

/**
 * The handle half of a cross-schema reference — `for <schema>[(<key>)] [as
 * <alias>] [with <map>]` — shared by `can(...)` and `check(...)`. The class
 * using it uses {@see PlacesNodes} too.
 */
trait TargetsSchema
{
    private ?string $schemaKey = null;

    private mixed $key = null;

    private bool $isRowBound = false;

    /** @var array<string, mixed> */
    private array $with = [];

    private ?string $as = null;

    /**
     * The schema the reference crosses to, and the row it selects there.
     *
     * The schema is resolved to its key here, through the registry, so the node
     * carries the key the parser would have lexed.
     *
     * @param Model|WarrantSchema|string $schema A schema key, a schema instance or
     *   class-string, or a model instance or class-string.
     * @param mixed $key The row selector: a key, the target schema's model, a
     *   {@see \Warrant\Builders\Ref}, a list of these for a key of several parts,
     *   or {@see NoRow} (the default) for an unbound handle. An explicit `null`
     *   stays row-bound and is rejected at validation; only omitting the argument
     *   means "no row".
     */
    public function for(Model|WarrantSchema|string $schema, mixed $key = new NoRow): static
    {
        $this->schemaKey = Warrant::registry()->resolveSchemaKeyOrFail($schema);
        $this->key = $key;
        $this->isRowBound = ! $key instanceof NoRow;

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

    private function schemaKeyNode(SyntaxSource $source): ?NameNode
    {
        return $this->schemaKey === null ? null : self::nameNode($this->schemaKey, $source);
    }

    /**
     * The handle's key arguments. A list is the arguments themselves, which is
     * how a key of several parts is written; anything else is a single argument.
     * Keys are dropped from an associative array: arguments bind positionally to
     * the key's parameters, exactly as a condition's do.
     *
     * @return list<Node>
     */
    private function keyArgumentNodes(SyntaxSource $source): array
    {
        if (! $this->isRowBound) {
            return [];
        }

        $arguments = is_array($this->key) ? array_values($this->key) : [$this->key];

        return array_map(fn (mixed $argument): Node => self::argumentNode($argument, $source), $arguments);
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
