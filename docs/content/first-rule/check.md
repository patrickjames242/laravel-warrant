---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 4. Ask a question
description: Run the check, see it pass and fail, and look at the SQL it produced.
sidebar:
  order: 4
---

The rule is in place. Ask it.

```php
use Warrant\Facades\Warrant;

$mine    = Document::factory()->create(['user_id' => $user->id]);
$theirs  = Document::factory()->create(['user_id' => $other->id]);

Warrant::can('view', $mine, user: $user);    // true
Warrant::can('view', $theirs, user: $user);  // false
```

Drop the `user:` argument and Warrant uses `auth()->user()`:

```php
Warrant::can('update', $mine);   // the authenticated user
```

Laravel's own Gate works too, because Warrant registers a `Gate::before` hook:

```php
$user->can('view', $mine);              // true
Gate::authorize('view', $theirs);       // throws a 403
```

```blade
@can('update', $document)
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan
```

## What actually ran

The check is an `EXISTS` query. No record is loaded, and the condition method's
`where` is spliced straight into it:

```sql
select exists (
    select * from "documents"
    where "documents"."id" = 'doc-1'
      and ("documents"."user_id" = 'user-7')
) as "exists"
```

That second line is the row selector. The line under it is `is_mine`, exactly as
the condition method wrote it.

Add the locked rule from step two and the `update` predicate grows a second half:

```sql
select exists (
    select * from "documents"
    where "documents"."id" = 'doc-1'
      and (
          ("documents"."user_id" = 'user-7')
          and not ("documents"."locked" = 1)
      )
) as "exists"
```

The grant side is the `OR` of every `can` rule. The deny side is the `AND` of every
negated `cannot`. That is the whole combination, and it is covered in
[how a decision is made](/concepts/how-a-decision-is-made/).

## Sometimes there is no query at all

Hand the check a model you already loaded and the row's existence is already
settled, so a rule that folds to a constant never reaches the database:

```php
$document = Document::find('doc-1');   // hydrated

Warrant::can('view', $document);       // may answer with no query
Warrant::can('view', 'doc-1');         // one query, to confirm the row is there
```

Next: [filter a list with the same rule](/first-rule/filter/).
