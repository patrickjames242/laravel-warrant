---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Conditions that are not query constraints
description: Derived conditions built from other conditions, and Eloquent scopes used as conditions.
sidebar:
  order: 4
---

A row or global condition constrains the builder it was handed. Two other shapes
are allowed, and both are underused.

## A condition built from other conditions

When a condition is just a combination of others, mark it `#[DerivedCondition]` and
return the expression instead of SQL. Expansion puts the expression where the
condition was named, before anything compiles:

```php
use Warrant\Schema\DerivedCondition;

#[DerivedCondition]
public function isEditable(): string
{
    return 'is_mine and not is_locked';
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
#[DerivedCondition]
public function needsApproval(): string
{
    return 'is_submitted and not is_approved';
}

#[DerivedCondition]
public function isVisibleInternally(): string
{
    return 'not is_draft or is_mine';
}
```

A derived condition takes **no context object**. It is expanded once per rule set,
before any check, so there is no user, row or check context to hand it. Its
parameters are its DSL arguments alone. Anything that needs the user or the row
belongs in a row or global condition, which the derived one then names.

It may answer with the following. Rule text of either kind may put the expression
under a `for` header naming this schema; a header naming another is rejected.

| Answer | Meaning |
| --- | --- |
| a `string` | rule text, parsed as a condition expression |
| a `WarrantSyntax` | rule text you parsed, with bindings, holding a condition expression |
| `Warrant::condition()->…` | the expression the builder composed |
| an expression node | used as it is |
| `true` / `false` | decides the outcome outright |
| `null` | [unknown](/sql/unknown/) |

Returning an expression or a builder from a `#[RowCondition]` or
`#[GlobalCondition]` throws. Move it to a `#[DerivedCondition]` and drop the context
parameter.

## Passing arguments on

An argument written as `@context` or `@column` reaches the method as the
*reference*, not its value: at expansion there is no value yet. The method can't
read it, only pass it on. Pass it back into the expression through a binding, never
by writing it into the string:

```php
#[DerivedCondition]
public function ownedOrInTeam(mixed $team): WarrantSyntax
{
    return Warrant::parse('is_owner or in_team(:team)', ['team' => $team]);
}
```

`if owned_or_in_team(@context team) they can view` then reads `team` from the
check's context, exactly as `in_team(@context team)` written inline would. A
`@column` argument keeps meaning the row the *rule* named, however many derived
conditions pass it on.

## Building one structurally

The builder form is what you want when the expression needs a cross-schema
reference:

```php
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;

#[DerivedCondition]
public function parentIsOwned(): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->ifCheck(
        'is_owner',
        FolderSchema::class,
        Ref::column('parent_id'),
        as: 'parent',
    );
}
```

That is the case a derived condition can express and a row condition cannot: the
correlated frame is not the one the condition was handed, so there is no builder to
constrain.

A builder with no terms throws rather than matching every row: it is almost always
a forgotten branch. Return `true` or `false` to decide the outcome outright.

## Its own names

The expression is written by the schema's author, who can't see where it's reached
from, so it is read with names of its own. The schema's key means the row the
condition is being asked about, and an alias the calling rule introduced
(`check(… as p)`) is not in scope.

## What holds a derived condition to the rules

A rule set is [validated](/supplying-rules/validation/) as written and again once
expanded, so a mistake inside a derived condition's expression is reported like one
written in the rule: an unknown condition name, a bad handle, a wrong arity, a
`@column` naming a frame that is not in scope. The compiler checks the same things
again, for a rule set nobody validated. See [the validator is never stricter than
the compiler](/sql/validator-compiler-invariant/).

A derived condition may name another, or itself with different arguments. The base
case has to be a PHP one. A chain that never ends is stopped at a depth of 64, a
budget it shares with [templates](/rules/templates/):

```php
#[DerivedCondition]
public function runaway(): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->if('runaway');   // stopped at depth 64
}
```

```
Expansion exceeded the maximum nesting depth of 64.

Expansion chain (outermost first):
  documents.runaway  (repeated 65 times)
```

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
