---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: How it compiles to SQL
description: Why Warrant's semantics are what they are — EXISTS leaves, De Morgan, and how can/cannot combine.
sidebar:
  order: 11
---

Warrant never evaluates rules in PHP. Every rule set is compiled to SQL and
resolved by the database — that's the whole model, and it's what lets one check
filter a list, decorate each row, or answer a yes/no question without ever loading
a record. This page is how that compilation works.

You don't need it to _use_ Warrant, but it explains _why_ the semantics are what
they are.

## The example.

**The schema** — a `documents` entity with three abilities and four
[conditions](/guides/conditions/):

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\{Ability, TargetedCondition, GlobalCondition};
use Warrant\Schema\WarrantSchema;
use Warrant\Schema\Conditions\{TargetedConditionContext, GlobalConditionContext};

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW = 'view';
    #[Ability] public const UPDATE = 'update';
    #[Ability] public const DELETE = 'delete';

    #[TargetedCondition]
    public function isSelf(TargetedConditionContext $c): Builder
    {
        return $c->query->whereRaw('documents.user_id = ?', [$c->user->getAuthIdentifier()]);
    }

    #[TargetedCondition]
    public function managesTeam(TargetedConditionContext $c): Builder
    {
        return $c->query->whereIn('documents.team_id', $c->user->managedTeamIds());
    }

    #[TargetedCondition]
    public function isLocked(TargetedConditionContext $c): Builder
    {
        return $c->query->where('documents.locked', true);
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->role === 'admin';
    }
}
```

So each condition contributes this fragment of SQL:

| Condition                 | SQL it adds                                                                      |
| ------------------------- | -------------------------------------------------------------------------------- |
| `is_self` (targeted)      | `documents.user_id = 42`                                                         |
| `manages_team` (targeted) | `documents.team_id in (7, 12)`                                                   |
| `is_locked` (targeted)    | `documents.locked = 1`                                                           |
| `is_admin` (global)       | `1 = 1` — a `bool` evaluated in PHP, baked in as a constant (`1 = 0` when false) |

**The rules** ([resolved](/guides/rules/) for the current user):

```text
if is_self or manages_team they can view, update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

For the SQL below, assume the current user **is** an admin — so `is_admin`
evaluates to `true` in PHP and compiles to the constant `1 = 1`. (For a non-admin
it would be `1 = 0`.)

## One predicate per ability

For each requested ability, the compiler gathers every rule that mentions it (or
`*`) and folds them into a single predicate. Each `can` rule contributes its
if-expression to a big `OR` — any one grant is enough — and each `cannot` rule
contributes the _negation_ of its if-expression to a big `AND`, so a single matching
`cannot` is enough to take the ability away. In other words:

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

A few cases collapse to constants at the edges of that formula:

- An **unconditional `cannot`** negates to `AND NOT(true)`, i.e. `1 = 0`, which
  zeroes out the whole predicate — the ability is gone on every row, no matter what
  any `can` rule says.
- An ability with **no `can` rule** at all has an empty `OR`, so it's `1 = 0` too:
  denied by default.
- An **unconditional `can`** contributes an always-true `1 = 1` term, granting the
  ability on every row.

See [Grants and denials](/guides/grants-and-denials/) for how this plays out in practice.

## A rough translation

Here is a rough translation of the rules from [the example](#the-example). Warrant `OR`'s the `can` rules
together, and `AND`'s the negated `cannot` rules. For `update`, that's roughly:

```sql
select * from documents
where (
    documents.user_id = 42             -- is_self
    or documents.team_id in (7, 12)    -- manages_team
    or 1 = 1                           -- is_admin (a global bool, true for this user)
)
and not (
    documents.locked = 1               -- is_locked
    and not 1 = 1                      -- ...and not is_admin
)
```

That's a good enough mental model for reasoning about which rows a query returns.
It isn't quite what Warrant emits, though — the real output is a little more
careful, for the two reasons the next sections cover.

:::note[Work in progress]
I'm currently working on collapsing redundant branches like the `1 = 1` above.
When a bool global condition resolves to a constant, the branch it sits in can
often be simplified away — an `... or 1 = 1` makes the whole `OR` true, and an
`and not 1 = 1` makes the whole `AND` false — so the compiled SQL will get tighter
in a future release.
:::

## Every condition is an `EXISTS` leaf

The rough shape above drops each condition straight into the `WHERE` as a bare
predicate. The real output wraps each one in its own `EXISTS` subquery instead. Two
things make that necessary.

**1. Negation is not sound in SQL's three-valued logic.**

SQL predicates aren't booleans — they're _three_-valued: `TRUE`, `FALSE`, or
`UNKNOWN` (what you get when `NULL` is involved). The second rule denies `update`
when `is_locked`, so it has to negate that condition. Drop it in directly and the
deny side reads:

```sql
and not (documents.locked = 1)
```

Now consider a row where `locked` is `NULL`. `documents.locked = 1` is `UNKNOWN`,
so `NOT (UNKNOWN)` is **still `UNKNOWN`** — and the outer `WHERE` drops any row that
isn't `TRUE`. The result: a `cannot` rule that _silently fails to deny_ on exactly
the rows with a null column. In an authorization system, that's a hole.

`EXISTS` collapses the three values back to two. `EXISTS (…)` is always `TRUE` or
`FALSE`, never `UNKNOWN`, so `NOT EXISTS (…)` is its **exact** complement:

```sql
and not exists (
    select 1 from (select 1) as warrant_exists
    where documents.locked = 1        -- NULL locked → EXISTS is false → NOT EXISTS is true
)
```

**2. A condition is a query fragment, not a predicate.**

A condition method is handed its own query builder and can do whatever it needs —
a `whereIn` with a subquery, a join, several `where`s at once. There's no
guarantee it reduces to a single boolean expression you can splice into the outer
`WHERE` with an `or`. Wrapping it in its own `EXISTS` subquery gives every
condition a private scope to build in, and hands the outer query back a single,
well-behaved boolean, so the outer query only ever sees `EXISTS (…)`.

For example, `manages_team` probably isn't a literal `in (7, 12)` in real life —
it more likely looks the teams up with a subquery:

```php
#[TargetedCondition]
public function managesTeam(TargetedConditionContext $c): Builder
{
    return $c->query->whereIn('documents.team_id', fn ($sub) => $sub
        ->select('team_id')
        ->from('team_managers')
        ->where('user_id', $c->user->getKey()));
}
```

That subquery just rides along inside the condition's own `EXISTS` leaf, and the
leaf drops into the same `OR` as the flat `is_self` one — neither needs to know
what the other looks like:

```sql
where (
    exists (select 1 from (select 1) as warrant_exists
        where documents.user_id = 42)                    -- is_self
    or exists (select 1 from (select 1) as warrant_exists
        where documents.team_id in (
            select team_id from team_managers where user_id = 42
        ))                                               -- manages_team
)
```

Try to flatten that into a bare `... or documents.team_id in (select …)` and it
still happens to work — but a condition that added a `join` instead of a subquery
would have nowhere to go. The `EXISTS` wrapper is what makes every condition splice
in the same way, regardless of the SQL it emits.

### The result

Because each leaf is a strict boolean:

- A condition that touches a `NULL` column yields `false`, not SQL's "unknown."
- Negation via `NOT EXISTS` is exact.

This is why `not` / `cannot` behave predictably — no three-valued-logic surprises
leak into your authorization results. Boolean structure (`and` / `or` / `not`)
becomes nested `WHERE` groups, with **negation pushed to the leaves via De Morgan**
so it always lands on an `EXISTS`, never on a group.

(The `from (select 1) as warrant_exists` is just a portable one-row dummy table to
hang the correlated `where` off — it's what lets `documents.user_id` refer back to
the outer row. A global condition like `is_admin` that returns a `bool` doesn't need
a row — or even a subquery — at all: the compiler evaluates it in PHP and drops the
result in as a bare `1 = 1` or `1 = 0`, no `EXISTS` wrapper.)

## Targeted conditions with no row

In a no-target check (for example `getUserAbilities()` with no target),
a targeted condition has no row to correlate against, so the compiler forces it to
`1 = 0` (false) — and, under negation, `1 = 1` (true). Global conditions still
evaluate normally. This is the same rule that makes an absent optional
[`@context`](/guides/context/#the-fail-open-caveat) key soft-false its condition.

## Worked examples

Now compile [the example](#the-example)'s rule set, one ability at a time. Each
ability gets its own predicate, and its shape comes entirely from which rules
mention it. Our user is an admin, so the wildcard `is_admin` rule folds a `1 = 1`
into every grant — for a non-admin that term would be `1 = 0` and the `EXISTS`
leaves would decide instead.

**`delete`** is the simplest — only the wildcard `is_admin they can *` grants it,
and no `cannot` mentions it. `is_admin` is a global `bool`, `1 = 1` for this admin,
so the whole predicate is that one constant (no `EXISTS` at all):

```sql
select * from documents where 1 = 1
```

**`view`** is granted by three sources — `is_self`, `manages_team`, and the
wildcard `is_admin` — `OR`'d together, with nothing denying it. The `is_admin` term
is `1 = 1`, so for this admin the `OR` is already true; the two `EXISTS` leaves are
what would decide it for a non-admin:

```sql
select * from documents where (
    exists (select 1 from (select 1) as warrant_exists where documents.user_id = 42)           -- is_self
    or exists (select 1 from (select 1) as warrant_exists where documents.team_id in (7, 12))  -- manages_team
    or 1 = 1                                                                                    -- is_admin (from `they can *`)
)
```

**`update`** has the same three grant sources as `view`, but the second rule adds a
`cannot` — so its predicate `AND`s a deny side onto the grant:

```sql
select * from documents where
  (
    -- grant side: the two-part first rule, OR the wildcard `is_admin` rule
    exists (select 1 from (select 1) as warrant_exists where documents.user_id = 42)           -- is_self
    or exists (select 1 from (select 1) as warrant_exists where documents.team_id in (7, 12))  -- manages_team
    or 1 = 1                                                                                    -- is_admin (from `they can *`)
  )
  and (
    -- deny side: NOT(is_locked and not is_admin), De-Morgan'd onto the leaves
    not exists (select 1 from (select 1) as warrant_exists where documents.locked = 1)         -- not is_locked
    or 1 = 1                                                                                    -- or is_admin
  )
```

The `cannot update` guarded by `is_locked and not is_admin` becomes
`NOT(is_locked AND NOT is_admin)`, which De Morgan turns into
`(NOT is_locked OR is_admin)` — negation always lands on a leaf (an `EXISTS`, or a
constant for a global `bool`), never on a group, so it stays a strict two-valued
boolean.

**Several abilities at once** combine per the [match mode](/guides/checking-access/#match-modes).
`userHasAbility(['view', 'update'], matchMode: ALL)` `AND`s the `view` and `update`
predicates from above (`ANY` would `OR` them):

```sql
select * from documents where
      ( /* the view predicate */ )
  and ( /* the update predicate */ )
```

**Per-row abilities** ([`selectUserAbilities`](/guides/checking-access/#per-row-abilities))
run each ability's predicate as a correlated subquery per row — one
`SELECT '<ability>' as ability WHERE <predicate>` `UNION ALL` branch per requested
ability, aggregated into a JSON array:

```sql
select *, (
    select coalesce(json_group_array(ability), json_array())
    from (
              select 'view' as ability   where ( /* the view predicate */ )
        union all
              select 'delete' as ability where ( /* the delete predicate */ )
    ) as available_abilities
) as abilities
from documents
```

Each row's `abilities` column ends up holding just the abilities whose predicate
held for that row — e.g. `["view"]` for a document the user owns but can't
delete. (The JSON aggregate differs by driver — see [below](#per-row-aggregation-is-driver-specific).)

## One compiler behind every check

- **"Which rows?"** — the predicates become your query's `WHERE`
  ([`userHasAbility`](/guides/checking-access/#filtering-queries)).
- **"What can they do to each row?"** — the predicates run as correlated
  subqueries producing a JSON column
  ([`selectUserAbilities`](/guides/checking-access/#per-row-abilities)).
- **"Can they?"** — the predicate runs as a scoped `EXISTS`
  ([`userHasAbilities`](/guides/checking-access/#boolean-checks)).

Because everything is one compiler, the yes/no check, the filtered list, and the
per-row abilities can never disagree.

## Per-row aggregation is driver-specific

The `selectUserAbilities` JSON column uses each database's native JSON aggregate:

| Driver          | Aggregate                                       |
| --------------- | ----------------------------------------------- |
| PostgreSQL      | `coalesce(json_agg(...), '[]'::json)`           |
| MySQL / MariaDB | `coalesce(json_arrayagg(...), json_array())`    |
| SQLite          | `coalesce(json_group_array(...), json_array())` |

Any other driver throws at query-build time. The subquery builds one `UNION ALL`
branch per ability, which is why
[narrowing with `onlyAbilities`](/guides/checking-access/#per-row-abilities) is a
real cost saving on wide lists.

## Validation happens at compile time

Compilation validates every ability and condition name against the schema; an
unknown name is a hard error, so a typo in a stored rule fails loudly rather than
silently granting or denying. Context-key references are validated the same way.
See [Errors & exceptions](/reference/errors/).
