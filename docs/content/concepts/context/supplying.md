---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Supplying context
description: Passing context at the check, and defaulting it on the schema.
sidebar:
  order: 4
---

Every check API takes a context array. It threads through boolean checks, query
scopes, and the ability column alike.

```php
// Boolean check: context is the third argument.
Warrant::can('update', $document, ['workspace_id' => $id]);

// Through a user-bound guard, named:
$user->warrant()->can('approve', $document, context: ['region' => 'us']);

// Filtering:
Document::query()->userHasAbility('update', context: ['workspace_id' => $id])->paginate();

// The per-row ability column, evaluated in one fixed frame:
Document::query()->selectUserAbilities(context: ['workspace_id' => $id])->get();

// No-row checks:
Warrant::can('create', Document::class, ['workspace_id' => $id]);
```

Through Laravel's Gate, context rides along as a tuple, since the Gate has no
separate argument for it:

```php
$user->can('approve', [$document, ['region' => 'us']]);
$user->can('create', [Document::class, ['region' => 'us']]);
```

## Defaults

`defaultContext()` on the schema supplies values so callers may omit them:

```php
protected function defaultContext(): array
{
    return ['workspace_id' => app('tenant')->id];
}
```

Whatever the caller passes is merged **over** the defaults, key by key, so
overriding one key leaves the rest:

```php
// workspace_id from the default, region from the caller
Warrant::can('view', $document, ['region' => 'us']);
```

A default can satisfy a required key, so a required key with a default never
throws.

## Why defaults are not optional in practice

Several paths cannot pass context at all, because they have nowhere to put it:

```php
// Route middleware calls authorize() with no context array.
Route::get('/documents/{document}', ...)
    ->middleware(WarrantMiddleware::string('document', 'view'));

// A bare query scope.
Document::query()->userHasAbility('view')->get();

// Laravel's can: middleware.
Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

If your rules reference `@context workspace_id` and nothing defaults it, every one
of those paths silently grants nothing, or throws if the key is required. For a
tenant-scoped application, `defaultContext()` is the fix, and it belongs on every
tenant-scoped schema:

```php
protected function defaultContext(): array
{
    return ['tenant_id' => app(Tenancy::class)->currentId()];
}
```

## Reachability takes none

There is no `context:` argument on any reachability method. Context only ever
feeds row and global conditions, and reachability evaluates neither.

```php
Warrant::couldEverHave(Document::class, 'update');   // no context, by design
```
