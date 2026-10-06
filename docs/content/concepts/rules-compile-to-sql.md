---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rules compile to SQL
description: The one fact the rest of Warrant follows from, and the consequences it forces.
sidebar:
  order: 0
---

Everything people find strange about Warrant follows from one fact.

**A rule is not code that runs. It is a predicate that compiles to a `WHERE`
clause.**

Read this rule:

```warrant
if is_mine or in_my_team they can view
```

It is not a function that returns `true` or `false` when called with a document.
It is a description of which documents match. Warrant turns it into this:

```sql
select * from "documents"
where (
    "documents"."user_id" = 'user-7'
    or exists (
        select * from "team_members"
        where "team_members"."team_id" = "documents"."team_id"
          and "team_members"."user_id" = 'user-7'
    )
)
```

Ask about one document and the same predicate runs inside an `EXISTS` with the row
pinned:

```sql
select exists (
    select * from "documents"
    where "documents"."id" = 'doc-1'
      and ( /* the same predicate, unchanged */ )
) as "exists"
```

Ask what the user can do to each row of a list and it runs once per ability as a
correlated subquery. Same predicate again.

## What that forces

Six things about Warrant look arbitrary until you have read the sentence above.
Each one is a consequence of it.

**Conditions hand back query builders.** A condition cannot be a PHP function that
inspects a model, because most of the time there is no model. There are ten
thousand rows and a query that has not run yet. So a condition describes rows:

```php
#[RowCondition]
public function isMine(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
}
```

**There is a third truth value.** SQL predicates answer yes, no, or unknown, and
Warrant does not paper over the third. A comparison against `NULL` is unknown, and
so is a question the compiler genuinely cannot settle, such as a row condition
asked with no row. An unknown grants nothing and cannot lift a denial, which is
the safe direction. [True, false, and unknown](/concepts/three-truth-values/).

**`cannot` cannot be appealed.** The predicate for an ability is the `OR` of the
grants, `AND`ed with the negation of every denial. There is no position in that
formula for "unless", because there is no order in an `AND`. A matching `cannot`
zeroes the predicate wherever it appears. [Grants, denials, and
appeals](/concepts/grants-and-denials/).

**Arbitrary PHP mid-rule is impossible.** The database evaluates the predicate,
and your PHP is not there when it does. Anything a rule needs has to be a column,
a bound value, or a value supplied at check time. [What a rule cannot
do](/concepts/what-rules-cannot-do/).

**Reachability is cheap.** Asking "could this user ever edit anything?" is a
question about the rules, not about rows. Warrant can answer it by reading the rule
set with no row or global condition evaluated and no query run.
[Reachability](/concepts/reachability/).

**One rule answers both questions.** "Can this user?" and "which rows can they?"
are the same predicate, run two ways. A hand-written policy method and a
hand-written query scope are two definitions that drift. This is one.

## Where the seams are

The model is not airtight, and the seams are worth knowing.

A condition that was handed the actual row may decide in PHP and return a `bool`,
which folds into the predicate as a constant. A global condition, which asks about
the user rather than a row, always may. So PHP does participate, at the leaves,
where it can produce a value the compiler can fold before any SQL is emitted.

```php
#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): bool
{
    return $c->user->role === 'admin';   // no SQL at all
}
```

For an admin, `if is_admin they can *` makes every predicate `true`, so the other
conditions are never emitted and the query is `where (1 = 1)`.

The full compilation story, with worked examples for each ability, is in
[understanding the SQL](/sql/rule-to-query/).
