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
use Warrant\Schema\Ability;

#[Ability] public const VIEW = 'view';
```

`requiredContext` names keys that must be present whenever **this** ability is
checked. A yes/no check throws if one is missing; enumeration skips the ability
instead.

```php
#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
```

## `DeclaresAbility`

The interface `#[Ability]` implements, and the one discovery looks for. Any
attribute implementing it declares an ability.

```php
use Attribute;
use Warrant\Schema\DeclaresAbility;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class TenantAbility implements DeclaresAbility
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
use Warrant\Schema\DerivedCondition;

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
use Warrant\Schema\RequiredContext;

#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

Context keys do not need declaring to be *used*. This attribute is only about
making one mandatory schema-wide. For a key mandatory only for one ability, use
`#[Ability(requiredContext: [...])]`.

## `#[RuleTemplate]`

Marks a public method as a [rule template](/rules/templates/). The name is the
method name snake-cased, overridable with a key.

```php
use Warrant\Schema\RuleTemplate;

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

A method cannot be both a condition and a template. Both attributes on one method is
rejected when the schema is read.

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
[WarrantSchema](/reference/warrant-schema/#reachability).
