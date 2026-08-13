<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Support\Str;

/**
 * Hands out deterministic, collision-safe table aliases for joins added inside a
 * single condition invocation.
 *
 * One factory is created per condition evaluation and exposed on the condition
 * context (`$c->alias(...)`). It makes two guarantees:
 *
 *  - Deterministic: an alias depends only on the order `next()` is called and the
 *    base name — no clock, RNG, or object id — so the same condition body always
 *    compiles to the same SQL. (This holds only while `next()` is called in a
 *    stable order: drive it from straight-line code, not unordered iteration.)
 *
 *  - Unique: a single monotonic counter distinguishes every alias within the
 *    condition (even when two bases share a truncated slug), and the reserved
 *    `$prefix` namespace keeps them from colliding with the app's own identifiers.
 *
 * The prefix is a documented reservation, not a physical guarantee — apps must
 * not name tables or aliases beginning with it. Pass a custom prefix to scope
 * aliases to your own schema if the default is not distinctive enough.
 */
final class AliasFactory
{
    /** Reserved identifier namespace — apps must not use tables/aliases starting with this. */
    public const DEFAULT_PREFIX = '__warrant_';

    private int $seq = 0;

    public function __construct(
        private readonly string $prefix = self::DEFAULT_PREFIX,
    ) {
    }

    /**
     * Generate the next unique alias for a table joined inside this condition.
     *
     * @param  string  $base  The real table name being aliased (e.g. "course_sections").
     */
    public function next(string $base): Alias
    {
        $n = $this->seq++;
        $slug = Str::substr(Str::slug($base, '_'), 0, 18); // cosmetic; aids debugging

        return new Alias($base, "{$this->prefix}{$slug}_{$n}");
    }
}
