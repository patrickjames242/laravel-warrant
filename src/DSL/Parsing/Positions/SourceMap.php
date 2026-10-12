<?php

namespace Warrant\DSL\Parsing\Positions;

use LogicException;
use Warrant\DSL\Lexing\Token;

/**
 * Where each part of a parsed tree was written, kept beside the tree rather than
 * on it, so the nodes stay plain values that compare equal however they were
 * written.
 *
 * A node is found by identity. A string inside a node has none, so it is found
 * by its owner, the property it is stored in, and its index or key there: the
 * ability in `can(view for docs)` is that node's
 * {@see \Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode::PART_ABILITY} part. Each node
 * declares its part names as constants.
 */
final class SourceMap
{
    /** @var list<SourceEntry> */
    private array $entries = [];

    /** @var array<string, SourceEntry> */
    private array $index = [];

    /** @var array<int, list<int>> Where in $entries each node's entries are, by object id. */
    private array $entriesOf = [];

    public function record(object $node, Token $start, Token $end): void
    {
        $this->add(new SourceEntry($node, null, null, Span::between($start, $end)));
    }

    /**
     * Record where one part of $node was written: from $start to $end, or the
     * single token $start when the part is one token.
     */
    public function recordPart(object $node, string $part, int|string|null $key, Token $start, ?Token $end = null): void
    {
        $this->add(new SourceEntry($node, $part, $key, Span::between($start, $end ?? $start)));
    }

    /**
     * Where a node, or one part of it, was written; null when it was not
     * recorded, as for a node a builder made.
     */
    public function spanOf(object $node, ?string $part = null, int|string|null $key = null): ?Span
    {
        return ($this->index[self::keyOf($node, $part, $key)] ?? null)?->span;
    }

    /**
     * Make every entry about $old, the node and each of its parts, an entry
     * about $new: for a tree rebuilt with $new in the place of $old, which was
     * written exactly where $old was. Each entry keeps its place in the order
     * entries were recorded, which {@see containing()} reads.
     */
    public function replace(object $old, object $new): void
    {
        $positions = $this->entriesOf[spl_object_id($old)] ?? [];
        unset($this->entriesOf[spl_object_id($old)]);

        foreach ($positions as $position) {
            $entry = $this->entries[$position];
            unset($this->index[self::keyOf($old, $entry->part, $entry->key)]);

            $this->add(new SourceEntry($new, $entry->part, $entry->key, $entry->span), $position);
        }
    }

    /**
     * Every entry whose range holds $offset, widest first. The last is what the
     * offset is on; the ones before it say what that sits inside, such as the
     * check(...) whose schema a condition name belongs to.
     *
     * Entries can cover the same text: a node and its own part, as a condition
     * written without arguments is just its name, or a node and the part of the
     * node around it that it is, as `@context org` is both a reference and an
     * argument of the condition it is passed to. The one holding the other comes
     * first. Entries are kept in the order they were recorded, and a node is
     * recorded only once everything inside it has been, so of two over the same
     * text the one recorded later holds the other.
     *
     * @return list<SourceEntry>
     */
    public function containing(int $offset): array
    {
        $found = array_filter(
            $this->entries,
            static fn (SourceEntry $entry): bool => $entry->span->contains($offset),
        );

        uksort($found, static fn (int $a, int $b): int =>
            [$found[$b]->span->length(), $b] <=> [$found[$a]->span->length(), $a]);

        return array_values($found);
    }

    /**
     * A node or part is written in one place, so recording it twice is a parser
     * mistake.
     *
     * @param int|null $position Where in the recording order the entry goes:
     *   the end, or the place of the entry it stands in for.
     */
    private function add(SourceEntry $entry, ?int $position = null): void
    {
        $key = self::keyOf($entry->node, $entry->part, $entry->key);

        if (isset($this->index[$key])) {
            throw new LogicException(sprintf(
                'Where %s%s was written is already recorded.',
                get_debug_type($entry->node),
                $entry->part === null ? '' : " part [{$entry->part}]",
            ));
        }

        $position ??= count($this->entries);
        $this->entries[$position] = $entry;
        $this->index[$key] = $entry;
        $this->entriesOf[spl_object_id($entry->node)][] = $position;
    }

    /**
     * An object id is reused only once its object is freed, and every entry
     * holds its node, so an id names one node for as long as this map exists.
     * Part names and keys are names from the grammar or list indexes, so the
     * separator never appears in them.
     */
    private static function keyOf(object $node, ?string $part, int|string|null $key): string
    {
        return spl_object_id($node) . '|' . $part . '|' . $key;
    }
}
