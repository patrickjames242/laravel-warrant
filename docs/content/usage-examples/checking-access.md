---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking access
description: Examples of asking about a user's access, for one row, a list of rows, no row, and routes.
sidebar:
  order: 5
---

Every check compiles the same rules to SQL, so a yes for one row, a filtered list,
and the abilities shown beside each row can never disagree. Each check defaults to
the authenticated user. [Asking questions](/checking/ways-in/) covers each one in
depth.

## One row

```php
use Warrant\Facades\Warrant;

Warrant::can('update', $document);                      // a model instance
Warrant::can('update', [Document::class, $documentId]); // a row by key
Warrant::can(['view', 'update'], $document);            // all of several
Warrant::canAny(['view', 'update'], $document);         // any of several
Warrant::cannot('delete', $document);

Warrant::can('view', $document, user: $user);           // someone else
```

## Throwing instead

`authorize()` throws a 403 carrying the rule's denial message:

```php
Warrant::authorize('update', $document);
Warrant::authorizeAny(['view', 'approve'], $document);
```

## Passing context

```php
Warrant::can('publish', $document, ['workspace_id' => 'ws-1']);
$user->warrant()->can('approve', $document, context: ['region' => 'us']);
```

## A filtered list

```php
Document::query()->userHasAbility('view')->paginate();

Document::query()
    ->where('state', 'published')
    ->userHasAbility('view')
    ->latest()
    ->paginate();

Document::query()->userHasAbility('update', $user)->get();
Document::query()->userHasAbility('view', context: ['workspace_id' => 'ws-1'])->get();
```

Several abilities, all or any:

```php
use Warrant\AbilityMatchMode;

Document::query()
    ->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)
    ->get();
```

It is a query scope, so counting, relations, and `whereHas` work too:

```php
Document::query()->userHasAbility('approve')->count();
$folder->documents()->userHasAbility('view')->get();
Folder::query()->whereHas('documents', fn ($q) => $q->userHasAbility('view'))->get();
```

## The abilities on each row

```php
$rows = Document::query()->selectUserAbilities()->get();

$rows->first()->abilities;   // ['view', 'update']

Document::query()->selectUserAbilities(onlyAbilities: ['update', 'delete'])->get();
Document::query()->selectUserAbilities(selectedAbilitiesKey: 'perms')->get();
```

On one row, or one you already loaded:

```php
Warrant::abilities($document);       // ['view', 'update']
$document->loadUserAbilities();      // sets $document->abilities
```

## No row

For abilities like `create`, ask about the schema rather than a row:

```php
Warrant::can('create', Document::class);       // by model class
Warrant::can('create', DocumentSchema::class); // by schema class
Warrant::can('create', 'documents');           // by schema key

Warrant::abilities(Document::class);           // ['create']
```

## Laravel's Gate

Warrant answers the Gate, so the calls you already use work:

```php
$user->can('view', $document);
$user->cannot('delete', $document);
$user->canAny(['view', 'approve'], $document);
$user->can('approve', [$document, ['region' => 'us']]);   // with context
$user->can('create', Document::class);                    // no row

Gate::authorize('update', $document);
```

```blade
@can('update', $document)
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan
```

## Routes

```php
use Warrant\Middleware\WarrantMiddleware;

Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->middleware(WarrantMiddleware::string('document', 'view'));

Route::post('/documents', [DocumentController::class, 'store'])
    ->middleware(WarrantMiddleware::canCreate('documents'));

WarrantMiddleware::guard('settings', 'manage', function () {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
});

Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

See [Route middleware](/checking/middleware/).

## Bound guards

When checking one user or one schema repeatedly, bind it once:

```php
// requires `use Warrant\AuthorizesWithWarrant;` on the User model
$user->warrant()->can('update', $document);
$user->warrant()->forSchema(Document::class)->can('update', 42);

DocumentSchema::guard($user)->can('view', $document);
DocumentSchema::guard($user)->abilities($document);
DocumentSchema::guard($user)->can('create');            // no row
```

## Could they ever?

Reachability asks whether any row could grant an ability, without touching a row.
Use it to decide what to render at all:

```php
use Warrant\Reachability;

Warrant::couldEverHave(Document::class, 'update');
Warrant::alwaysHas(Document::class, 'view');
Warrant::neverHas(Document::class, 'delete');

Warrant::possibleAbilities(Document::class);     // ['view', 'update', 'approve']

match (Warrant::reachabilityOf(Document::class, 'update')) {
    Reachability::NEVER  => null,                 // omit the Edit column
    Reachability::ALWAYS,
    Reachability::MAYBE  => $this->editColumn(),
};
```

See [Reachability](/checking/reachability/).
