---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Declaring abilities
description: Ability constants, required context, standard names, and your own attribute.
sidebar:
  order: 2
---

Abilities are the verbs a rule can grant or deny. Declare each as a class constant
marked `#[Ability]`:

```php
use Warrant\Schema\Ability;

#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
#[Ability] public const UNLOCK  = 'unlock';
```

The constant's **value** is the name a rule writes. The constant's *name* is
irrelevant to Warrant, since discovery is by attribute rather than by naming.
There is no fixed list, so declare whatever verbs your domain has:

```warrant
if is_author they can submit
if is_payroll_admin they can approve, unlock
```

A rule naming an ability the schema does not declare is rejected when the rule set
is validated:

```text
Ability [aprove] is not declared by the schema.
```

A different message fires at check time when the ability you *request* is not
declared, which is the same root cause from the other end:

```php
Warrant::can('destroy', $document);
```

```text
Ability [destroy] is not defined on schema [App\Warrant\DocumentSchema].
```

## Declaration order matters

`abilityNames()` returns abilities in declaration order rather than sorted, and
that is also the order they appear in the per-row ability column:

```php
DocumentSchema::abilityNames();   // ['view', 'approve', 'unlock']
```

```php
Document::query()->selectUserAbilities()->first()->abilities;   // ['view', 'unlock']
```

So ordering the constants the way your UI wants to read them is worth a moment.

## Standard names

If you want a shared vocabulary across schemas, `StandardAbilities` ships common
ones:

```php
use Warrant\Schema\StandardAbilities;

#[Ability] public const VIEW = StandardAbilities::VIEW;   // 'view'
```

```php
StandardAbilities::VIEW;     // 'view'
StandardAbilities::CREATE;   // 'create'
StandardAbilities::UPDATE;   // 'update'
StandardAbilities::DELETE;   // 'delete'
StandardAbilities::ARCHIVE;  // 'archive'
```

The `canView`, `canCreate`, and friends [middleware
helpers](/checking/middleware/) map to these constants, so using them saves a
little typing on routes.

## Requiring context per ability

An ability may require context keys that only it needs:

```php
#[Ability] public const VIEW = 'view';
#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
```

A yes/no check throws when the key is missing. Enumeration skips the ability
instead, so listing what a user can do does not blow up over one ability's frame:

```php
Warrant::can('publish', $document);   // throws without workspace_id
Warrant::abilities($document);        // 'publish' is simply absent
```

## Your own ability attribute

`#[Ability]` is one implementation of the `DeclaresAbility` interface, and
discovery looks for the interface. Write your own when the required context follows
from something you would rather say once than restate as a literal list on every
constant:

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
#[TenantAbility]                       public const EDIT  = 'edit';
#[TenantAbility(scopedToBranch: true)] public const AUDIT = 'audit';
```

The interface asks only for the required context, since an ability's name is always
the constant's value. An attribute class is not an attribute by inheritance, so
your implementation carries its own `#[Attribute(...)]`. A constant carries at most
one ability attribute, because an ability has one set of required context.

## Enum-backed names

Ability names are strings, and a backed enum's case is a fine source for one:

```php
enum DocumentAbility: string
{
    case View    = 'view';
    case Approve = 'approve';
}
```

```php
#[Ability] public const VIEW    = DocumentAbility::View->value;
#[Ability] public const APPROVE = DocumentAbility::Approve->value;
```

```php
Warrant::can(DocumentAbility::View->value, $document);
```
