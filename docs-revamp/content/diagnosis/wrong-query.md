---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The query looks wrong
description: Reading the generated SQL, and the aliasing bug it usually reveals.
sidebar:
  order: 2
---

## Get the SQL

```php
Document::query()->userHasAbility('view')->dd();
Document::query()->userHasAbility('view')->toRawSql();

$guard = Warrant::forSchema(Document::class, $user);
$guard->filterQuery($guard->query(), 'view')->toRawSql();
```

Or watch everything:

```php
DB::listen(fn ($q) => logger($q->sql, $q->bindings));
```

## The three constants

If the whole predicate is one of these, the rules settled the question without
reference to any row:

| You see | Means |
|---|---|
| `where (1 = 1)` | granted unconditionally; usually a wildcard rule and a global condition that came back true |
| `where (1 = 0)` | denied; an unconditional `cannot`, or nothing granted the ability |
| `where (null)` | unanswerable; almost always a missing context key or a row condition with no row |

Telling `1 = 0` from `null` matters. The first means the rules said no. The second
means they could not say. See [where unknown goes in SQL](/sql/unknown/).

## A table the query does not have

```sql
select * from "documents" as "d"
where ("documents"."user_id" = 7)
```

The query aliased the table to `d` and the condition emitted `documents`. That is a
condition hard-coding its table:

```php
// The bug.
return $c->query->where('documents.user_id', $c->user->getAuthIdentifier());

// The fix.
return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
```

The same bug appears inside a subquery, where the rows have a hop's alias:

```sql
exists (
    select * from "folders" as "parent"
    where "parent"."id" = "documents"."parent_id"
      and ("folders"."owner_id" = 7)      -- names the wrong thing
)
```

Depending on the database that is either an error or, worse, a reference to an outer
table that happens to be in scope, which silently answers a different question.

In a model scope, the fix is `warrantQualifyColumn()`:

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($this->warrantQualifyColumn('owner_id'), $userId);
}
```

See [frames](/concepts/frames/).

## More subqueries than you expected

One `EXISTS` per row-bound cross-schema reference, plus any a condition adds
internally. Two references to the same schema on sibling branches produce two
independent subqueries, which is correct.

If there are more than your rules have hops, look for a condition that emits a
`whereExists` of its own, or a [derived condition](/schemas/conditions-beyond-sql/)
expanding into a `check(...)`.

## Nesting that looks arbitrary

It is not. The compiler drops every group that carries no meaning: a group holding
one branch is not wrapped, and a condition that emitted a single `where` is spliced
in bare. So the nesting you see is the boolean structure of your rules.

If a group looks wrong, compare it against the rule's precedence, which is `not`,
then `and`, then `or`:

```warrant
if is_mine or not is_manager and is_owner
```

parses as `is_mine or ((not is_manager) and is_owner)`.

## Negation in the wrong place

Negation is pushed to the leaves by De Morgan, so you should never see `not (a and
b)` in the output. A `cannot` guarded by `is_locked and not is_admin` becomes:

```sql
and (not ("documents"."locked" = 1) or 1 = 1)
```

and folds from there. If negation appears around a group, that is worth reporting.

## A condition emitting nothing

```text
Condition [x] on schema [Y] added no where clause; a condition must add at least
one where clause, return true/false to decide the outcome outright, or return null
to answer unknown.
```

A branch fell through without constraining anything. Returning the query untouched
would silently mean "match every row", so it throws instead.

## A condition emitting the wrong shape

```text
Condition [manages_team] on schema [documents] may only add where clauses, but it
emitted a [join]; ...
```

Use a correlated `whereExists` rather than a join. The restriction is on the outer
query's shape, so a subquery may join however it likes:

```php
return $c->query->whereExists(fn ($sub) => $sub
    ->from('team_managers')
    ->join('teams', 'teams.id', '=', 'team_managers.team_id')
    ->whereColumn('team_managers.team_id', $c->row('team_id'))
    ->where('team_managers.user_id', $c->user->getKey()));
```

## Bindings in the wrong order

`toRawSql()` inlines them, which is the fastest way to see a mis-ordered positional
binding. Remember that positional `?` bindings fill left to right across the whole
string, while `@context`, `@column`, and `@sql` string literals consume none.

## Comparing against what you meant

Once you have the SQL, run it. The predicate is an ordinary `WHERE`:

```sql
select id, user_id, team_id, locked from documents
where ( /* paste the predicate */ );
```

That is usually the step that finds the empty team id list, the wrong column, or
the state string that is `'Published'` on some rows and `'published'` on others.
