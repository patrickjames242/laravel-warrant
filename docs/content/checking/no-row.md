---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking without a row
description: "Can they create? Can they reach settings? Questions that name no target."
sidebar:
  order: 4
---

Not every check is about a row. "Can this user create documents?" has no document
to point at. Neither does "can they reach the settings section?".

```php
Warrant::can('create', Document::class);       // by model class
Warrant::can('create', DocumentSchema::class); // by schema class
Warrant::can('create', 'documents');           // by schema key
Warrant::can('manage', 'settings');

DocumentSchema::guard($user)->can('create');   // bound guard: omit the row
```

Listing every ability a user holds with no row:

```php
Warrant::abilities(Document::class);    // ['create']
Warrant::abilities('settings');         // ['manage']
```

## What can grant one

Nothing about an ability makes it target-free. A no-row check just asks whether the
user holds it without naming a row, so only rules whose conditions do not need a
row can grant it: global conditions, and unconditional rules.

```warrant
they can create                       # grants: unconditional
if is_admin they can create           # grants: is_admin is global
if is_mine they can create            # does not: is_mine needs a row
```

A row condition asked with no row is *unanswerable* rather than false. It grants
nothing, and negating it grants nothing either, so it cannot lift a denial.

That last part surprises people, so it is worth spelling out:

```warrant
they can view
if is_owner they cannot view
```

```php
Warrant::abilities(Document::class);   // []
```

`view` is not reported. The deny cannot be evaluated, so the ability cannot be
claimed. An unknown deny is not a deny that failed to fire. See
[true, false, and unknown](/concepts/three-truth-values/).

## Schemas with no rows at all

A section with no model is the natural home for this. Declare `const model = ''`
and only global conditions:

```php
class SettingsSchema extends WarrantSchema
{
    public const model = '';

    #[Ability] public const MANAGE = 'manage';
    #[Ability] public const VIEW_BILLING = 'view_billing';

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }

    #[GlobalCondition]
    public function onPaidPlan(GlobalConditionContext $c): bool
    {
        return $c->user->account->plan !== 'free';
    }
}
```

```warrant
for settings {
    if is_admin they can manage
    if is_admin and on_paid_plan they can view_billing
}
```

```php
Warrant::can('manage', 'settings');
Warrant::abilities('settings');   // ['manage', 'view_billing']
```

A targeted check against such a schema is refused up front rather than failing
strangely later:

```text
Schema [settings] has no rows and does not support targeted checks; use a no-target
check instead.
```

## In rules

The unbound handle form asks another schema a no-row question:

```warrant
if can(manage for settings) they can view
if can(access for billing) they can export
```

The target's row conditions have nothing to run against there, so they are
unanswerable while their siblings answer as usual:

```warrant
if check(tenant_ok or is_open for pay_periods) they can view
```

## Gating a route

```php
Route::post('/documents', [DocumentController::class, 'store'])
    ->middleware(WarrantMiddleware::canCreate('documents'));

WarrantMiddleware::guard('settings', 'manage', function () {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
});
```

## The lower-level form

```php
$guard->getAbilitiesWithoutTarget(
    abilities: null,                      // null enumerates every held ability
    matchMode: AbilityMatchMode::ANY,
    context: [],
);
```

:::caution[Its default differs]
Most entry points default to `ALL`. `getAbilitiesWithoutTarget()` defaults to
`ANY`. Pass the mode explicitly if you call it directly, or prefer
`Warrant::abilities(Document::class)`, which is the same question with fewer
surprises.
:::
