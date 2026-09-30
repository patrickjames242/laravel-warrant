---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 6. Explain a denial
description: Turn "no" into a sentence a person can read.
sidebar:
  order: 6
---

The usual objection to writing rules in a small language is that error messages
get worse. They do not have to. A `cannot` can carry its own reason, and the
throwing check surfaces it.

```warrant
if is_mine they can view, update

if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
```

```php
Warrant::authorize('update', $lockedDocument);
```

That throws a `Warrant\WarrantAuthorizationException`, which extends Laravel's
`AuthorizationException`, so the framework renders it as a 403 carrying the
message. Nothing to wire up.

```json
{ "message": "This document is locked and can no longer be edited." }
```

`Warrant::can` still returns a plain boolean. Reach for `authorize` when the
answer should explain itself.

## One reason per ability

Each `cannot` clause carries its own message, so one rule can deny two things for
two reasons:

```warrant
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
they cannot delete because 'Locked documents cannot be deleted.'
```

## A message that knows the row

Pass a closure instead of a string and it receives the denial context:

```php
use Warrant\Schema\WarrantDenialContext;

WarrantRule::fromSyntax('if is_locked they cannot update')
    ->withDenialMessage(fn (WarrantDenialContext $c) =>
        "You cannot edit {$c->target->title} while it is locked.");
```

## When nothing granted it

Being forbidden and never having been granted are different failures. A message on
a `cannot` explains the first. For the second there is no rule to point at, so the
message lives on the schema:

```php
public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
{
    return in_array('approve', $c->ungrantedAbilities, true)
        ? 'You need an approver role to do that.'
        : null;   // fall back to the generic 403
}
```

Full behaviour, including closures that return their own exception, is in
[denial messages](/rules/denial-messages/).

That is the loop. From here, [where to go next](/first-rule/next/).
