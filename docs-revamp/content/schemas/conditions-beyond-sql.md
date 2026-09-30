---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Conditions that are not query constraints
description: Derived conditions that answer with an expression, and Eloquent scopes used as conditions.
sidebar:
  order: 4
---

A condition usually constrains the builder it was handed. Two other shapes are
allowed, and both are underused.

## A condition that answers with an expression

Return an expression instead of SQL and the compiler walks the result as though you
had written it inline in the rule:

```php
use Warrant\Facades\Warrant;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;

#[RowCondition]
public function isEditable(RowConditionContext $c): IBooleanExpressionNode
{
    return Warrant::condition('is_mine and not is_locked');
}
```

```warrant
if is_editable they can update
```

That produces exactly the SQL of the hand-written rule. Negation De Morgans through
the expansion, so `not is_editable` behaves correctly rather than wrapping a
black box.

This is the answer to a schema whose conditions repeat the same combination in six
rules. Name the combination once, and rules stay short:

```php
#[RowCondition]
public function needsApproval(RowConditionContext $c)
{
    return Warrant::condition('is_submitted and not is_approved');
}

#[RowCondition]
public function isVisibleInternally(RowConditionContext $c)
{
    return Warrant::condition('not is_draft or is_mine');
}
```

## Building one structurally

The builder form is what you want when the expression depends on runtime data, or
when it needs a cross-schema reference:

```php
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;

#[RowCondition]
public function parentIsOwned(RowConditionContext $c): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->ifCheck(
        'is_owner',
        FolderSchema::class,
        Ref::column('parent_id'),
        as: 'parent',
    );
}
```

That is the case a derived condition can express and a plain condition cannot: the
correlated frame is not the one the condition was handed, so there is no builder to
constrain.

A condition may also answer with a bare AST node:

```php
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;

#[GlobalCondition]
public function featureEnabled(GlobalConditionContext $c): BooleanNode
{
    return new BooleanNode(config('features.sharing'));
}
```

## What holds a derived condition to the rules

The expression a derived condition returns reaches the compiler after validation
has already run, and no pass over rule text can see it. So everything the validator
would have caught has to be caught again by the compiler, and it is: an unknown
condition name, a bad handle, a wrong arity, a `@column` naming a frame that is not
in scope. See [the validator is never stricter than the
compiler](/sql/validator-compiler-invariant/).

Expansion is bounded by the same depth budget as everything else. A condition that
expands into itself with no base case is caught:

```php
#[GlobalCondition]
public function runaway(GlobalConditionContext $c): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->if('runaway');   // caught at depth
}
```

An empty expansion folds to `false`, the same as any empty group.

## Eloquent scopes as conditions

A row condition is handed an Eloquent builder over its schema's model, wrapping the
very `where` clause the compiler reads back. So a condition may spend a scope the
model already defines rather than restating its SQL:

```php
class Document extends Model
{
    public function scopePublished($query)
    {
        return $query->where($this->warrantQualifyColumn('state'), 'published');
    }
}
```

```php
#[RowCondition]
public function isPublished(RowConditionContext $c): Builder
{
    return $c->query->published();
}
```

```sql
select * from "documents" where ("documents"."state" = 'published')
```

That is worth having when a scope is already the shared definition of something and
you do not want two copies.

Two properties of the wrapper matter, and both are silent when they break.

**The model answers to whatever the compiler named the row.** A scope that
hard-codes its table, or qualifies through `qualifyColumn()`, names the table
always and so cannot be reached through an alias or a cross-schema hop. Build the
scope on `warrantQualifyColumn()` and it follows the row:

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($this->warrantQualifyColumn('owner_id'), $userId);
}
```

It is reachable from the builder as well, so a scope may use whichever it has to
hand:

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($query->warrantQualifyColumn('owner_id'), $userId);
}
```

**The wrapper carries no global scopes.** A condition answers the question it was
asked, not whatever the model would otherwise volunteer. A global scope that is
part of your access model has to be a condition.

A scope still has to honour the ordinary condition contract, so a scope that joins
or groups throws when used this way. See [frames](/concepts/frames/) for the whole
aliasing story.
