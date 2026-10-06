---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Where unknown goes in SQL
description: The third truth value once it is a WHERE clause, and why it folds the way it does.
sidebar:
  order: 3
---

SQL predicates are three-valued. `TRUE`, `FALSE`, and `UNKNOWN`, the last being
what a comparison involving `NULL` produces. A `WHERE` keeps a row only on `TRUE`,
and `NOT UNKNOWN` is `UNKNOWN`.

Warrant does not normalize that away. The concepts are in
[true, false, and unknown](/concepts/three-truth-values/); this is what it looks
like in the emitted query.

## A null column

```php
#[RowCondition]
public function isLocked(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('locked'), true);
}
```

```warrant
if is_locked they cannot update
```

```sql
select * from "documents" where (
    ("documents"."user_id" = 42)
    and not ("documents"."locked" = 1)
)
```

For a row whose `locked` is `NULL`, the inner comparison is `UNKNOWN`, so
`not (UNKNOWN)` is `UNKNOWN`, so the whole `AND` is `UNKNOWN`, so the row is not
returned. The user loses access to a row nobody meant to lock.

That is fail-closed, and it is still surprising. Handle the null if you mean
something by it:

```php
return $c->query->where(fn ($q) => $q
    ->whereNull($c->row('locked'))
    ->orWhere($c->row('locked'), false));
```

## Tracing it both ways

- On a `can`, an `UNKNOWN` grant is not `TRUE`, so it does not fire. No access is
  added.
- On a `cannot`, the denial side is `AND NOT(condition)`. With the condition
  `UNKNOWN` that is `AND UNKNOWN`, which drops the row.

So the failure direction is always the same. The worst that happens is a legitimate
user being blocked. Never someone seeing a row they should not.

That is provable rather than incidental: in three-valued logic, replacing any part
of a predicate with `UNKNOWN` can never turn a not-`TRUE` result into `TRUE`.

## Questions the compiler cannot answer

The compiler holds itself to the same three answers, because some questions it
genuinely cannot settle:

- a **row condition with no row**, in a no-row check or inside an unbound
  `check(... for <schema>)` predicate;
- a **`@column`** naming rows that are not in scope;
- a **row selector that resolves to nothing**, such as an absent `@context` key.

A condition may reach the same conclusion about its own question and say so by
returning `null`.

None of those is `false`. `false` is an answer, and negating an answer is
legitimate, so a `false` under a `cannot` would become `true` and a question nobody
could answer would silently lift a denial. Each compiles to unknown instead, which
negates to itself.

## The consequence people trip over

```warrant
they can view
if is_owner they cannot view
```

```php
Warrant::abilities(Document::class);   // []
```

`view` is not reported. `is_owner` cannot be evaluated with no row, so the denial
cannot be evaluated, so the ability cannot be claimed. An unknown deny is not a
deny that failed to fire.

`getAbilitiesWithoutTarget()` is correspondingly conservative, and deliberately so.

## When it reaches the SQL

Where an unknown cannot be folded away, it is emitted as a literal `null` and the
database applies the same rules:

```sql
select * from "documents" where (null)
```

Seeing that is a signal. It means the whole predicate came down to a question the
compiler could not answer, which is usually a missing context key or a row
condition asked with no row.

`filterQuery()` spells constants out this way because a row filter has to say
something. The boolean checks read the constant and return without querying.

## The three constants, and what each means

| In the SQL | The rules settled on | Usually because |
|---|---|---|
| `1 = 1` | granted, without reference to a row | an unconditional `can`, or a global condition that came back true |
| `1 = 0` | denied | an unconditional `cannot`, no `can` at all, or a global condition that came back false |
| `null` | unanswerable | a row condition with no row, or a missing context key |

Telling `1 = 0` from `null` matters when diagnosing. The first means the rules said
no. The second means they could not say.

```php
$guard->compileGate($query, 'view')->decision();
// Decision::True | False | Unknown | NeedsQuery
```
