---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Requiring context
description: Making a key mandatory, schema-wide or per ability, so a missing frame fails loudly.
sidebar:
  order: 5
---

Keys are optional by default. A check with the key absent runs, and the condition
gets `null`. What that matches is up to the condition: passed to Laravel's
`where`, it becomes an `IS NULL` test, which grants rows rather than blocking them.
See [a missing context key is not unknown](/concepts/three-truth-values/#a-missing-context-key-is-not-unknown).

Marking a key required turns that into an error with a name. A key that is present
with a `null` value counts as supplied, so a default that has no value should leave
the key out rather than set it to `null`.

## Schema-wide

`#[RequiredContext]` on a class constant. The constant's value is the key string;
its name is ignored:

```php
use Warrant\Schema\Context\RequiredContext;

class DocumentSchema extends RowlessSchema
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

Read them back with `(new DocumentSchema)->requiredContextKeys()`.

## Per ability

When only one ability needs the frame, require it there:

```php
use Warrant\Schema\Abilities\Ability;

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
an attribute that implements `IDeclaresAbility`. That interface is what discovery
actually looks for, and `#[Ability]` is just one implementation of it.

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

Require any key that gates a `cannot`. Handed `null`, a condition built on `where`
matches only the rows whose column is `NULL`, so the denial is lifted from every
other row.

Require any key your whole application is scoped by, such as a tenant. Then pair it
with a [`defaultContext()`](/concepts/context/supplying/) so ordinary calls do not
have to pass it, and the requirement only ever fires when the frame genuinely is
not set up.
