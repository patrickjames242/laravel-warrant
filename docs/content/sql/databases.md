---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Database differences
description: What Warrant supports, what differs across drivers, and what to avoid for portability.
sidebar:
  order: 5
---

Warrant compiles for three driver families:

| | |
|---|---|
| PostgreSQL | supported |
| MySQL and MariaDB | supported |
| SQLite | supported |

Any other driver works for most of Warrant and fails on one feature, covered below.

## Identifier quoting

Column references are quoted through the connection's grammar, so the same rule
produces different text on each:

```warrant
if pay_period_matches(@column pay_period_id) they can view
```

```sql
`timesheets`.`pay_period_id`     -- MySQL and MariaDB
"timesheets"."pay_period_id"     -- PostgreSQL and SQLite
```

You never write the quoting. A `@column` reference and `$c->row()` both hand your
condition an `Illuminate\Database\Query\Expression`, already quoted, which is
emitted verbatim rather than re-quoted or bound.

This is the argument against building identifiers yourself in a condition. String
concatenation produces something that works on your development SQLite and breaks
on production MySQL, or the other way round.

## The per-row ability column

The one feature that genuinely differs. `selectUserAbilities` aggregates into JSON
using each database's native aggregate:

| Driver | Aggregate |
| --- | --- |
| PostgreSQL | `coalesce(json_agg(...), '[]'::json)` |
| MySQL and MariaDB | `coalesce(json_arrayagg(...), json_array())` |
| SQLite | `coalesce(json_group_array(...), json_array())` |

Any other driver throws when the query is built:

```text
Warrant ability selection does not support the [sqlsrv] database driver.
```

Everything else, including checks, filtering, hops, and reachability, is ordinary
SQL and works anywhere Laravel does.

The returned column is JSON text, and Eloquent casts it for you if you say so:

```php
protected $casts = ['abilities' => 'array'];
```

## Booleans

A boolean column is `true`/`false` on PostgreSQL and `1`/`0` on MySQL and SQLite.
Comparing through the query builder handles it:

```php
// Portable.
return $c->query->where($c->row('locked'), true);

// Not portable.
return $c->query->whereRaw("{$c->row('locked')} = 1");
```

## Null handling

The three-valued logic is standard and behaves the same everywhere. What differs is
what ends up null in the first place, particularly the empty string. MySQL in some
configurations and PostgreSQL disagree about it, so a condition comparing a column
that might be `''` is worth testing on the driver you deploy on.

## Case sensitivity

String comparison is collation-dependent. MySQL's default collations are usually
case-insensitive; PostgreSQL's are not; SQLite's depend on the column. A condition
comparing a role name or a slug can behave differently between environments:

```php
// Explicit, and portable.
return $c->query->whereRaw("lower({$c->row('slug')}) = ?", [strtolower($slug)]);
```

Note the cost: a function on the column usually defeats an index. Prefer
normalizing on write.

## Raw SQL is yours to keep portable

```warrant
if matches(@sql "select pay_period_id from settings limit 1") they can view
```

The body is spliced verbatim, so anything driver-specific in it is yours. That
includes `limit` versus `fetch first`, date functions, and string concatenation.
See [passing values to conditions](/rules/values/).

## Testing across drivers

Warrant's own suite drives real SQLite and asserts on rows and ability lists rather
than SQL strings. That is the approach to copy.

Asserting on generated SQL couples a test to the driver's grammar, so it breaks on
a version bump or an environment change without anything being wrong:

```php
// Fragile.
expect($query->toSql())->toBe('select * from "documents" where ("documents"."user_id" = ?)');

// Durable.
expect(Document::query()->userHasAbility('view', $user)->pluck('id'))
    ->toContain($mine->id)
    ->not->toContain($theirs->id);
```

If you deploy on MySQL and test on SQLite, run at least the authorization suite
against MySQL in CI. Collation and null handling are where the two diverge, and
both are load-bearing for conditions.
