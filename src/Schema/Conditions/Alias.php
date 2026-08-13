<?php

namespace Warrant\Schema\Conditions;

use Stringable;

/**
 * A generated, collision-safe table alias for use inside a condition's query.
 *
 * Produced by {@see AliasFactory::next()}. It carries both the real base table
 * and the generated alias, so a condition can emit whichever form it needs:
 *
 *   $s = $c->alias('course_sections');
 *   $c->query->join($s->table(), $s->col('id'), '=', $c->targetSqlId);
 *
 * `table()` yields the join/from target
 * ("course_sections as __warrant_course_sections_0") and `col()` yields a
 * qualified column reference ("__warrant_course_sections_0.id").
 */
final class Alias implements Stringable
{
    public function __construct(
        public readonly string $base,
        public readonly string $name,
    ) {
    }

    /** The join/from target: "<base> as <alias>". */
    public function table(): string
    {
        return "{$this->base} as {$this->name}";
    }

    /** A qualified column reference against the alias: "<alias>.<column>". */
    public function col(string $column): string
    {
        return "{$this->name}.{$column}";
    }

    /** The bare alias name (e.g. for interpolation or a select reference). */
    public function __toString(): string
    {
        return $this->name;
    }
}
