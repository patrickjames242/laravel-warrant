---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Attributes
description: Every attribute Warrant discovers, and the interface behind the ability one.
sidebar:
  order: 4
---

## `#[Ability]`

Marks a class constant as an ability. The constant's **value** is the name; its
name is ignored, since discovery is by attribute.

```php
use Warrant\Schema\Abilities\Ability;

#[Ability] public const VIEW = 'view';
```

`requiredContext` names keys that must be present whenever **this** ability is
checked. A yes/no check throws if one is missing; enumeration skips the ability
instead.

```php
#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
```

## `IDeclaresAbility`

The interface `#[Ability]` implements, and the one discovery looks for. Any
attribute implementing it declares an ability.

```php
use Attribute;
use Warrant\Schema\Abilities\IDeclaresAbility;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class TenantAbility implements IDeclaresAbility
{
    public function __construct(private bool $scopedToBranch = false) {}

    public function requiredContext(): array
    {
        return $this->scopedToBranch ? ['tenant_id', 'branch_id'] : ['tenant_id'];
    }
}

#[TenantAbility]                       public const EDIT  = 'edit';
#[TenantAbility(scopedToBranch: true)] public const AUDIT = 'audit';
```

The interface asks only for the required context, since an ability's name is always
the constant's value. An attribute class is not an attribute by inheritance, so
your implementation carries its own `#[Attribute(...)]`.

A constant carries at most one ability attribute.

## `#[RowCondition]` and `#[GlobalCondition]`

Mark a public method as a condition. An optional key overrides the snake-cased
method name; passing `''` throws.

```php
#[RowCondition]              // key = snake_case(method)
#[RowCondition('is_owner')]  // explicit key
#[GlobalCondition]
```

The method's **first** parameter is the context object, typed to match. Parameters
after it receive the rule's arguments positionally; a variadic tail collects the
rest, and a parameter with a default is optional.

A condition may only add `where` clauses, and must add at least one. A `join`,
`groupBy`, `having`, aggregate, or `union` throws. Returning the query untouched
throws; return `true` to mean match-everything.

A condition may return a `bool`, evaluated in PHP: a global condition always may,
and a row condition may whenever it was handed the row as `$c->model`. It must
still emit SQL when `$c->model` is `null`.

A condition may return `null` to answer unknown, and must then add no `where`
clause.

A row or global condition may not return an expression or a condition builder;
that is a [`#[DerivedCondition]`](#derivedcondition).

## `#[DerivedCondition]`

Marks a public method as a condition built from other conditions. The key works as
for the other two.

```php
use Warrant\Schema\Conditions\Derived\DerivedCondition;

#[DerivedCondition]
public function canEdit(): string
{
    return 'is_owner or is_editor';
}
```

It takes **no context object**: every parameter is a DSL argument, so all of them
without a default are required. A `@context` or `@column` argument arrives as the
reference (`ContextRef`, `ColumnRef`), to be passed on through a binding. It
answers with rule text, a `WarrantConditionBuilder`, an expression node, a `bool`,
or `null` for unknown, and is expanded once per rule set before compiling. See
[conditions that are not query constraints](/schemas/conditions-beyond-sql/).

A method may carry only one of `#[RowCondition]`, `#[GlobalCondition]`,
`#[DerivedCondition]` and `#[RuleTemplate]`.

## `#[RequiredContext]`

Marks a class constant's **value** as a context key required on **every** check
against the schema.

```php
use Warrant\Schema\Context\RequiredContext;

#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

Context keys do not need declaring to be *used*. This attribute is only about
making one mandatory schema-wide. For a key mandatory only for one ability, use
`#[Ability(requiredContext: [...])]`.

## `#[RuleTemplate]`

Marks a public method as a [rule template](/rules/templates/). The name is the
method name snake-cased, overridable with a key.

```php
use Warrant\Schema\RuleTemplates\RuleTemplate;

#[RuleTemplate]
public function requiresApproval(): string
{
    return "if not is_approved they cannot because 'This needs approval first.'";
}

#[RuleTemplate('approval')]
public function requiresApproval(): string { /* ... */ }
```

The method answers with rule text as a string or a `WarrantSyntax` parsed with
bindings, a rule or `@include` that names no abilities, or an iterable of any of
these. See [what a template may answer with](/rules/templates/#what-a-template-may-answer-with).

A first parameter typed `RuleTemplateContext` is handed the abilities the `@include`
supplies, and is not a DSL argument. With `callOncePerAbility: true` the method is
called once for each of them, and `$context->ability()` returns the one; the flag
requires the context parameter. See [knowing the abilities](/rules/templates/#knowing-the-abilities).

```php
#[RuleTemplate(callOncePerAbility: true)]
public function frozen(RuleTemplateContext $context): string { /* ... */ }
```

A method cannot be both a condition and a template. Both attributes on one method is
rejected when the schema is read.

## `#[RowRelation]`

Marks a public method as a row relation: how a row of this schema reaches rows of
another. Rule text follows it by name after `its`, as in
`can(view for its folder)`. The name is the method name snake-cased, overridable
with `key`.

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;

#[RowRelation(FolderSchema::class)]
public function folder(RowRelationContext $c): Builder
{
    return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
}

#[RowRelation(FolderSchema::class, key: 'parent_folder')]
public function folder(RowRelationContext $c): Builder { /* ... */ }
```

The first argument is the schema the relation reaches, and it is required. It
must be a registered schema with rows. Validation reads it to check the ability
or predicate on the other side of `its`, and so does the
[language server](/editors/language-server/), which reads your schemas without
running your application.

The method takes exactly one parameter, the `RowRelationContext`:

| Member | Is |
|---|---|
| `$c->relatedQuery` | the related schema's rows, to narrow to the ones related to this row |
| `$c->ownColumn('folder_id')` | a column of this schema's row, qualified and quoted |
| `$c->relatedColumn('id')` | a column of the related rows, qualified and quoted |
| `$c->user` | the user being checked |
| `$c->context` | the related schema's check context, with the reference's `with` map over it |

Name every column through `ownColumn()` and `relatedColumn()`, never by table.
Either side may go by another name in the query: an alias, an enclosing hop's
rows, or a numbered copy of a table already in scope. Both return expressions, so
a builder compares them as columns in a `where`'s value position as well as in
`whereColumn()`.

The method narrows `relatedQuery` and returns it. As a condition does, it may only
add `where` clauses, and must add at least one: a `join`, `groupBy`, `having`,
aggregate or `union` throws, and so does returning the query untouched. A pivot
table goes in a `whereExists()`. `relatedQuery` carries none of the related
model's global scopes: a relation reaches the rows its method names, and nothing
the model would otherwise add.

A to-one relation, a document's folder:

```php
#[RowRelation(FolderSchema::class)]
public function folder(RowRelationContext $c): Builder
{
    return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
}
```

A to-many relation, a folder's documents:

```php
#[RowRelation(DocumentSchema::class)]
public function documents(RowRelationContext $c): Builder
{
    return $c->relatedQuery->where($c->relatedColumn('folder_id'), $c->ownColumn('id'));
}
```

A relation through a pivot table, a document's editors:

```php
#[RowRelation(UserSchema::class)]
public function editors(RowRelationContext $c): Builder
{
    return $c->relatedQuery->whereExists(fn ($pivot) => $pivot
        ->from('document_editors')
        ->whereColumn('document_editors.user_id', $c->relatedColumn('id'))
        ->whereColumn('document_editors.document_id', $c->ownColumn('id')));
}
```

A relation may reach any number of rows. `its documents` passes when **any** of
them passes, and `all of its documents` when there is at least one and every one
passes. See [following a relation](/rules/references/#following-a-relation-with-its).

## `AuthorizesWithWarrant`

A trait rather than an attribute, on the user model:

```php
use Warrant\AuthorizesWithWarrant;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;
}
```

## `Reachability`

An enum rather than an attribute. See
[Schema classes](/reference/warrant-schema/#reachability).
