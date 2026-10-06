---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking one row
description: Boolean checks and authorize-or-throw, and every target form they accept.
sidebar:
  order: 2
---

A check runs as a scoped `EXISTS`. No record is loaded.

```php
Warrant::can('update', $document);                      // a model instance
Warrant::can('update', [Document::class, $documentId]); // a row by key
Warrant::can(['view', 'update'], $document);            // all of several
Warrant::canAny(['view', 'update'], $document);         // any of several
Warrant::cannot('delete', $document);                   // the negation
```

There is no `matchMode:` argument on these. `can` requires every listed ability and
`canAny` requires one, expressed through the method name.

Through a bound guard the target is just the row:

```php
DocumentSchema::guard($user)->can('update', $document);
DocumentSchema::guard($user)->can('update', 'doc-9');
$user->warrant()->forSchema(Document::class)->can('update', 42);
```

## Throwing instead of returning

```php
Warrant::authorize('update', $document);
Warrant::authorizeAny(['view', 'approve'], $document);
```

Both return nothing on success and throw a 403 carrying the responsible rule's
message on failure. See [denial messages](/rules/denial-messages/).

## Passing context

The third argument, merged over the schema's `defaultContext()`:

```php
Warrant::can('publish', $document, ['workspace_id' => 'ws-1']);
$user->warrant()->can('approve', $document, context: ['region' => 'us']);
```

## Target forms on the facade

```php
Warrant::can('update', $document);                  // model instance, a row
Warrant::can('update', [Document::class, $id]);     // [class, id], a row by key
Warrant::can('create', Document::class);            // model class, no row
Warrant::can('create', DocumentSchema::class);      // schema class, no row
Warrant::can('create', 'documents');                // schema key, no row
```

A bare scalar here names the *schema*, never a row, which is why the facade takes
no bare int: an int could not identify a schema. Use the tuple to name a row
schema-lessly, or reach for the schema-bound guard where a bare key is
unambiguous.

## What it costs

Sometimes nothing. A predicate that folds to a constant may answer without a query
at all, and whether it can depends on whether the row's existence is already
established:

```php
$guard->can('view', $documentFromQuery);   // hydrated: no query
$guard->can('view', 42);                   // one query, for existence
$guard->can('view', new Document);         // one query, for existence
```

A folded `false` always short-circuits, since no row could pass. A folded `true` is
one step short of an answer on a targeted check, because the `EXISTS` was also
confirming the row is there, and only a hydrated model has already done that.

An empty ability set folds to `true`, which is the match-all an empty gate has
always meant.

## Checking a row you do not have

Passing a key rather than a model is the right call when loading the row would be
wasted work:

```php
if (Warrant::can('view', [Document::class, $request->route('id')])) {
    // ...
}
```

Passing the model is the right call when you already have it, because it is both
cheaper and better: a hydrated model reaches row conditions as `$c->model`, which
lets them [answer in PHP](/schemas/conditions/).
