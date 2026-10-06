---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: From rule to query
description: The compilation pipeline end to end, worked through one rule set, one ability at a time.
sidebar:
  order: 1
---

You do not need this page to use Warrant. It explains why the semantics are what
they are.

## Before compiling: expansion

A rule set goes through three steps. It is **parsed** into a tree, **expanded**,
and **compiled**. Expansion spells out the shorthand an author wrote:

- an [ability block](/rules/ability-blocks/)'s header is applied to every clause
  under it;
- an [`@include`](/rules/templates/) is replaced by its template's rules, where the
  include stood;
- a [derived condition](/schemas/conditions-beyond-sql/) is replaced by the
  expression it answers with.

Expansion reads no user, row or check context, so it happens once per rule set and
every check against it compiles the same rules. The rule set is
[validated](/supplying-rules/validation/) as written and again once expanded,
before anything compiles. A `can(... for <schema>)` reaches rules resolved for that
schema, which are expanded the same way when it is first compiled.

## The example

A `documents` schema with three abilities and four conditions:

```php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';
    #[Ability] public const DELETE = 'delete';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->whereRaw("{$c->row('user_id')} = ?", [$c->user->getAuthIdentifier()]);
    }

    #[RowCondition]
    public function managesTeam(RowConditionContext $c): Builder
    {
        return $c->query->whereIn($c->row('team_id'), $c->user->managedTeamIds());
    }

    #[RowCondition]
    public function isLocked(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('locked'), true);
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->role === 'admin';
    }
}
```

Each condition contributes a fragment:

| Condition | SQL it adds |
| --- | --- |
| `is_mine` | `documents.user_id = 42` |
| `manages_team` | `documents.team_id in (7, 12)` |
| `is_locked` | `documents.locked = 1` |
| `is_admin` | none; a `bool` evaluated in PHP and folded |

The rules:

```warrant
if is_mine or manages_team they can view, update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

Assume the user is not an admin for now, so `is_admin` is `false`. The admin case
is [at the end](#when-a-constant-decides-everything), because a `true` swallows
whole branches.

## One predicate per ability

For each requested ability the compiler gathers every rule mentioning it, or `*`,
and folds them into one predicate. Grants `OR`; denials contribute their negation to
an `AND`:

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

A few cases resolve to a constant at the edges of that formula: an unconditional
`cannot` negates to `false` and zeroes the predicate; an ability with no `can` rule
has an empty `OR`, so it is `false`; an unconditional `can` contributes an
always-true term.

Those are real booleans while the predicate is assembled, not `1 = 1` text, which
is what lets them fold.

## Constants fold away

Warrant assembles the whole predicate as a tree and only then emits SQL, so a
branch that is provably true or false is simplified rather than printed:

| Branch | Folds to |
|---|---|
| `false AND x` | `false`; the rest never reaches the SQL |
| `true AND x` | `x` |
| `true OR x` | `true`; the rest never reaches the SQL |
| `false OR x` | `x` |

That is why `is_admin` leaves no trace in the `update` predicate below. For a
non-admin the wildcard rule contributes `false` to the grant `OR` and is dropped,
and the `and not is_admin` inside the denial De Morgans into an `or is_admin` that
is `false` and drops too.

So `1 = 1` or `1 = 0` reaches the SQL only when a constant decides the whole
predicate. Everywhere else it disappears.

The same pass drops meaningless parentheses. A group holding one branch is not
wrapped, and a condition that emitted one `where` is spliced in bare. Nesting you
see in the output reflects real boolean structure, which makes the output worth
reading.

## Conditions compile inline

Each condition is spliced directly into the `WHERE` as a nested predicate. There is
no `EXISTS` wrapper around a condition.

That is why a condition may only add `where` clauses, and must add at least one.
Whatever it emits has to be a boolean the compiler can drop into an `OR`, `AND`
together, and wrap in `NOT`. A `join` or `groupBy` is not, so emitting one throws.

To reach another table, a correlated `whereExists` stays a boolean and drops into
the same `OR` as a flat condition:

```sql
where (
    documents.user_id = 42
    or exists (
        select * from team_managers
        where team_managers.team_id = documents.team_id
          and user_id = 42
    )
)
```

## Worked examples

**`delete`** is granted only by the wildcard rule, and no denial mentions it.
`is_admin` is `false`, so the grant `OR` has nothing in it and the constant is the
whole predicate:

```sql
select * from documents where (1 = 0)
```

**`view`** is granted by three sources, `OR`ed, with nothing denying it. The
`is_admin` term is `false` and drops:

```sql
select * from documents where (
    documents.user_id = 42
    or documents.team_id in (7, 12)
)
```

**`update`** has the same three grant sources, plus a denial:

```sql
select * from documents where (
    (
        documents.user_id = 42
        or documents.team_id in (7, 12)
    )
    and not (documents.locked = 1)
)
```

The `cannot update` guarded by `is_locked and not is_admin` becomes
`NOT(is_locked AND NOT is_admin)`, which De Morgan turns into `(NOT is_locked OR
is_admin)`. Negation always lands on a leaf. `is_admin` is `false` here, so that
branch drops and only `NOT is_locked` is emitted.

**Several abilities at once** combine per the match mode.
`userHasAbility(['view', 'update'], matchMode: ALL)` `AND`s the two predicates;
`ANY` would `OR` them:

```sql
select * from documents where
      ( /* the view predicate */ )
  and ( /* the update predicate */ )
```

Folding crosses the ability boundary too. Under `ANY`, one ability held
unconditionally makes the whole gate `1 = 1`. Under `ALL`, one ability that can
never be held makes it `1 = 0`.

**Per-row abilities** run each predicate as a correlated subquery, one `UNION ALL`
branch per ability, aggregated into JSON:

```sql
select *, (
    select coalesce(json_group_array(ability), json_array())
    from (
              select 'view'   as ability where ( /* the view predicate */ )
        union all
              select 'delete' as ability where ( /* the delete predicate */ )
    ) as available_abilities
) as abilities
from documents
```

## When a constant decides everything

Run the same rules for an **admin** and `is_admin` is `true`. The wildcard rule's
`true` swallows the grant `OR` for every ability, and on `update` the De-Morgan'd
`or is_admin` swallows the denial too. All three predicates reduce to:

```sql
select * from documents where (1 = 1)
```

The row conditions are not merely `OR`ed against a true constant. They are absent
from the query, and their methods' SQL is never used. This is the case where a
`1 = 1` survives: the constant is the whole predicate, so there is nothing for it
to fold into.

## The row in hand

Two more places a constant appears, both about already knowing something.

When a check names one row and the caller passed the loaded model, that model
reaches row conditions as `$c->model`, and a condition written to use it may return
a `bool`. A rule whose conditions all answer that way compiles to a constant and
the check never queries. Anything covering more than one row has no single model to
pass, so those conditions emit SQL as always.

The same idea crosses a schema boundary, and that is in
[what joins get generated](/sql/joins/).

## One compiler behind everything

- **Which rows?** The predicate becomes your query's `WHERE`.
- **What can they do to each row?** It runs as correlated subqueries producing a
  JSON column.
- **Can they?** It runs as a scoped `EXISTS`.

Because it is one compiler, the yes/no check, the filtered list, and the per-row
abilities can never disagree.
