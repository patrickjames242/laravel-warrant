---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Requiring context
description: Making a key mandatory, schema-wide or per ability, so a missing frame fails loudly.
sidebar:
  order: 5
---

Keys are optional by default. A check with the key absent runs, the condition gets
`null`, and the result is fail-closed and silent.

Silent is the problem. Marking a key required turns a whole class of rows quietly
going missing into an error with a name.

## Schema-wide

`#[RequiredContext]` on a class constant. The constant's value is the key string;
its name is ignored:

```php
use Warrant\Schema\RequiredContext;

class DocumentSchema extends WarrantSchema
{
    #[RequiredContext] public const WORKSPACE = 'workspace_id';
}
```

Now no check against this schema runs without a workspace:

```php
Warrant::can('view', $document);
```

```text
Schema [App\Warrant\DocumentSchema] requires context key(s) [workspace_id];
supply them at the check or via defaultContext().
```

Read them back with `DocumentSchema::requiredContextKeys()`.

## Per ability

When only one ability needs the frame, require it there:

```php
use Warrant\Schema\Ability;

#[Ability] public const VIEW = 'view';
#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
```

```php
Warrant::can('view', $document);      // fine
Warrant::can('publish', $document);   // throws without workspace_id
```

The two surfaces behave differently on purpose. A yes/no check throws. Enumeration
skips the ability instead, since listing what a user can do should not blow up
because one ability wanted a frame nobody supplied:

```php
Warrant::abilities($document);   // 'publish' is simply absent from the list
```

## Inside a rule

A `can(...)` or `check(...)` in rule text is not a check, and how it treats a
missing required key depends on where its context comes from.

A `can(publish)` of the same schema keeps the context the check was given. A key it
lacks is one the caller did not pass, so the reference answers unknown, which
neither grants nor lifts a deny.

A `can(... for <schema>)` or `check(... for <schema>)` hands the other schema only
its own defaults and the `with` map. A required key missing from those throws,
because only the rule text can supply it. See
[context across boundaries](/concepts/context/across-boundaries/#required-keys-at-the-boundary).

## Your own ability attribute

When the required context follows from something you would rather say once, write
an attribute that implements `DeclaresAbility`. That interface is what discovery
actually looks for, and `#[Ability]` is just one implementation of it.

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
```

```php
#[TenantAbility]                        public const EDIT  = 'edit';
#[TenantAbility(scopedToBranch: true)]  public const AUDIT = 'audit';
```

The interface asks only for the required context. An ability's name is always the
constant's value, so it is never the attribute's to answer. An attribute class is
not an attribute by inheritance, so your implementation carries its own
`#[Attribute(...)]` line. A constant may carry at most one ability attribute.

## When to require

Require any key that gates a `cannot`. A missing key there blocks rows rather than
exposing them, which is safe, and invisible, which is not.

Require any key your whole application is scoped by, such as a tenant. Then pair it
with a [`defaultContext()`](/concepts/context/supplying/) so ordinary calls do not
have to pass it, and the requirement only ever fires when the frame genuinely is
not set up.
