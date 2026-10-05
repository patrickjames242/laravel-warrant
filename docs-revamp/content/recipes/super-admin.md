---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Super admins
description: A blanket grant, and why it should still be a rule.
sidebar:
  order: 6
---

Every application has someone who can do everything. The temptation is to bypass
authorization for them. Do not: a bypass is invisible to every other part of the
system.

## The rule

```php
#[GlobalCondition]
public function isSuperAdmin(GlobalConditionContext $c): bool
{
    return $c->user->is_super_admin;
}
```

```warrant
if is_super_admin they can *
```

`*` is every ability the schema declares, so an ability added tomorrow is covered
with no edit.

## Put it in implicit rules

A super admin grant that lives in the resolver is one refactor away from being
dropped, and it is exactly the kind of thing your operations team depends on:

```php
public function implicitRules(): array|RuleSetNode
{
    return [
        WarrantSyntax::parse('if is_super_admin they can *')->rule(),
    ];
}
```

Better still, put it on a base schema so every resource inherits it:

```php
abstract class AppSchema extends WarrantSchema
{
    #[GlobalCondition]
    public function isSuperAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->is_super_admin;
    }

    public function implicitRules(): array|RuleSetNode
    {
        return [WarrantSyntax::parse('if is_super_admin they can *')->rule()];
    }
}
```

A subclass overriding `implicitRules()` should merge rather than replace:

```php
public function implicitRules(): array|RuleSetNode
{
    return [
        ...parent::implicitRules(),
        WarrantSyntax::parse('if is_suspended they cannot *')->rule(),
    ];
}
```

## What it costs, which is nothing

`isSuperAdmin` is a global condition returning a `bool`, so it is a constant to the
compiler. For a super admin it is `true`, the wildcard grant swallows the grant
side of every predicate, and everything else folds away:

```sql
select * from "documents" where (1 = 1)
```

The other conditions are absent from the query, and their methods' SQL is never
built. For everyone else it is `false`, that branch drops out, and the query looks
as though the rule were not there.

## Why it is still better than a bypass

**It composes with denials.** A super admin is still stopped by a `cannot`:

```warrant
if is_super_admin they can *
if is_legally_sealed they cannot view, export
    because 'This record is sealed by court order.'
```

A bypass has no way to express that exception.

**It shows up in reachability.** `Warrant::guaranteedAbilities(Document::class)`
returns everything for a super admin, so the UI renders correctly with no separate
admin branch.

**It shows up in the list.** `selectUserAbilities()` gives them every ability on
every row, so your frontend needs no special case.

**It shows up in filtering.** `userHasAbility('view')` returns everything, rather
than every list endpoint needing an `if ($user->isSuperAdmin())` around it.

## Admins scoped to something

A tenant administrator is not a super admin. Keep them a row condition so the scope
stays in the predicate:

```php
#[RowCondition]
public function inMyAdministeredTenant(RowConditionContext $c): Builder
{
    return $c->query->whereIn($c->row('tenant_id'), $c->user->administeredTenantIds());
}
```

```warrant
if in_my_administered_tenant they can *
```

The difference matters. A global condition that ignores the row would grant across
tenants.

## Impersonation

When staff act as a user, the question is whether the check is about the staff
member or the person being impersonated. Usually the latter, with the staff
member's own powers suppressed:

```php
#[GlobalCondition]
public function isSuperAdmin(GlobalConditionContext $c): bool
{
    return $c->user->is_super_admin && ! session()->has('impersonating');
}
```

Cleaner is to run the check as the impersonated user, which every entry point
supports:

```php
Warrant::can('view', $document, user: $impersonated);
Document::query()->userHasAbility('view', $impersonated)->get();
```

## Testing

```php
it('gives a super admin everything, and still honours a seal', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $ordinary = Document::factory()->create();
    $sealed   = Document::factory()->create(['sealed' => true]);

    expect(Warrant::can('delete', $ordinary, user: $admin))->toBeTrue();
    expect(Warrant::can('view', $sealed, user: $admin))->toBeFalse();
});
```
