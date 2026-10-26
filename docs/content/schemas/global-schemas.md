---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Global schemas
description: Conditions and rule templates every schema's rules may use, and how a name finds them.
sidebar:
  order: 8
---

Some conditions belong to no one schema. Whether the user is staff, whether the
check is made in the user's own tenant, whether the account is suspended: every
schema's rules want to ask, and copying the condition into each schema means
keeping every copy the same.

A global schema declares them once. Its global conditions, derived conditions and
rule templates may be used by name in any schema's rules, as if that schema
declared them.

```php
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\GlobalSchema;

final class TenancySchema extends GlobalSchema
{
    #[GlobalCondition]
    public function inCurrentTenant(GlobalConditionContext $c): bool
    {
        return $c->user->tenant_id === $c->context['tenant_id'];
    }

    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return $c->user->is_staff;
    }

    #[DerivedCondition]
    public function isTenantStaff(): string
    {
        return 'is_staff and in_current_tenant';
    }

    #[RuleTemplate]
    public function tenantStaffOnly(): string
    {
        return 'if is_tenant_staff they can';
    }
}
```

```warrant
for documents {
    if is_owner and in_current_tenant they can edit
    @include tenant_staff_only for view
}
```

A global schema declares no abilities, no rules and no required context: no
check is ever made against one. It has no rows either, so it declares no row
conditions.

## Listing them

Global schemas go under `global` in `config/warrant.php`, as a list:

```php
'global' => [
    App\Warrant\TenancySchema::class,
    App\Warrant\AuditSchema::class,
],
```

A global schema has no key. No rule names it, so there is nothing for a key to
do: its constructs are used by their own names, and it is known by its class. A
`for` header, `can(... for ...)` or `check(... for ...)` has no way to name one.

The globals are searched in the order they are listed, but no two of them may
declare the same name, so the order never decides which one a name means.

A class may be listed once across `schemas` and `global`; listing one twice is
refused when the registry is built. A class listed under `global` must extend
`GlobalSchema`, which is checked the first time a name is looked up among the
globals, and one listed under `schemas` must not, which is checked when that
schema is first used.

Listing a global schema does not build it. The first time a lookup reaches the
globals, every global is built, so that a name two of them declare is refused
before either can be used.

## How a name is found

What a name means depends on the schema the text naming it was written in.

**Text written for a schema** looks the name up in that schema first, then in each
global schema in the order they are listed. A schema's rules, its rule provider's
rules for it, and what its own templates and derived conditions answer with are
all written for the schema.

**Text a global schema answers with** looks the name up in the global schemas
alone, in the same order. The global schema the text came from gets no priority
over the ones listed above it, and the schema whose rules reached the template or
derived condition is never searched. So a global's text means the same thing
wherever it is used.

The first schema that declares the name is the one it means. Take a schema that
declares an `is_staff` of its own, beside `TenancySchema`'s:

```php
final class DocumentSchema extends ModelSchema
{
    #[RowCondition]
    public function isStaff(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('staff_id'), $c->user->getAuthIdentifier());
    }
}
```

| Written in | `is_staff` means |
| --- | --- |
| `DocumentSchema`'s rules | `DocumentSchema::isStaff`: the schema's own comes first |
| `TenancySchema::isTenantStaff`'s answer | `TenancySchema::isStaff`: the globals alone |
| any other global's templates or derived conditions | `TenancySchema::isStaff`: the globals alone |

A schema's own construct hides a global one by the same name, and a rule in that
schema cannot reach the hidden one. A schema that needs the global one renames
its own.

Names are shared across kinds within a schema, as they are for any schema: a
schema's `is_staff` row condition hides a global `is_staff` global condition.
Conditions and templates are separate names.

### No two globals share a name

Two global schemas may not declare the same condition, or the same template.
The one listed later could never be found, so it is a mistake rather than an
override, and every lookup that reaches the globals throws until one is renamed:

```text
Global schemas [App\Warrant\TenancySchema] and [App\Warrant\AuditSchema] both declare condition [is_staff]; a name may be declared by only one global schema, since the one listed first would always be found. Rename one of them.
```

The language server marks the later declaration with the same message.

### A `check(...)` names its own schema

Inside a global's text, the predicate of `check(... for posts(...))` is looked up
in `posts`, then the globals, as rules written for `posts` are:

```php
#[RuleTemplate]
public function staffOrAuthor(): string
{
    // is_staff is looked up in the globals; is_author in posts, then the globals.
    return 'if is_staff or check(is_author for posts(@context post_id)) they can';
}
```

A global template cannot use the including schema's row conditions by name,
since its names are not looked up there. It reaches another schema's rows through
`check(...)`.

## What a global's text may hold

A global's templates and derived conditions answer with text as any schema's do,
with one difference: no `for` header. A header says whose names the text uses,
and a global's text uses the global schemas' names, which no key can name.

```text
Rule template [App\Warrant\TenancySchema::tenantStaffOnly] answered under a `for` header naming schema [documents]; a global schema answers with no header, since its names are looked up in the global schemas.
```

A global condition is called with the check's user and context. The schema being
checked says which context keys are required; a global schema requires none, so a
global condition that reads `$c->context` must cope with a key the check did not
supply.

## In the editor

The [language server](/editors/language-server/) reads the `global` list too. It
checks a global's templates and derived conditions as answers whose names are
looked up in the global schemas, and hover, go-to-definition and completion find
a global's conditions and templates where they are declared.
