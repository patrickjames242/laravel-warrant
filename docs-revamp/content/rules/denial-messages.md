---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Denial messages
description: Turn a refusal into an explanation, from the rule, the builder, or the schema.
sidebar:
  order: 9
---

The strongest objection to expressing policy in a small language is that error
messages get worse. A hand-written policy method can throw whatever sentence it
likes; a compiled predicate returns false.

Warrant's answer is that the rule that did the forbidding carries the reason.

```php
Warrant::authorize('update', $document);
```

That returns nothing on success and throws
`Warrant\WarrantAuthorizationException` on failure. It extends Laravel's
`AuthorizationException`, so the framework renders it as a 403 carrying the
message, with no handler wiring. It takes the same arguments as `Warrant::can`,
and `authorizeAny` is the any-of-several variant.

## In the rule

```warrant
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
```

`because` is valid only immediately after a `cannot`, never after `can`. Only a
`cannot` actively forbids. A missing `can` is the absence of a grant, which names
no single rule, and that case is [handled separately](#when-nothing-granted-it).

Each clause carries its own message, so one rule can explain two denials:

```warrant
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
they cannot delete because 'Locked documents cannot be deleted.'
```

## With the builder

```php
WarrantRule::build()
    ->if('is_locked')
    ->theyCannotBecause('update', 'This document is locked and can no longer be edited.')
    ->theyCannotBecause('delete', 'Locked documents cannot be deleted.')
    ->toRule();
```

Abilities passed together share one message:

```php
->theyCannotBecause(['update', 'delete'], 'This document is locked.')
```

## On a rule you already have

`withDenialMessage` works whatever the rule's origin, which matters for rules
parsed from stored text. It applies to every denied ability, or to a named subset:

```php
WarrantRule::fromSyntax('if is_locked they cannot update, delete')
    ->withDenialMessage('This document is locked.');

WarrantRule::fromSyntax('if is_locked they cannot update, delete')
    ->withDenialMessage('Deletes are permanent.', ['delete']);
```

`WarrantRule` is immutable, so it returns a copy. Messaging an ability the rule
does not deny, or any rule with no `cannot`, throws.

## A message that knows the row

Pass a closure and it receives a `WarrantDenialContext`, returning either a string
or a `Throwable` to throw as-is:

```php
use Warrant\Schema\WarrantDenialContext;

->withDenialMessage(fn (WarrantDenialContext $c) =>
    "You cannot edit {$c->target->title} while it is locked.")

->withDenialMessage(fn (WarrantDenialContext $c) =>
    new DocumentLockedException($c->target))
```

Returning your own exception opts out of the automatic 403, and its own rendering
applies.

A closure can reach the language too, through a binding, since `because` itself
takes only a literal:

```php
WarrantRule::fromSyntax('if is_locked they cannot update because :msg', bindings: [
    'msg' => fn (WarrantDenialContext $c) => "You cannot edit {$c->target->title} while it is locked.",
]);
```

The context carries everything about the refusal:

| Property | Type | What it is |
|---|---|---|
| `$c->user` | `Authenticatable` | who was denied |
| `$c->target` | `?Model` | the row checked, or null for a no-row check |
| `$c->schema` | `string` | the schema class |
| `$c->context` | `array` | the effective check-time context |
| `$c->gate` | `WarrantGate` | what was asked: `abilities` and `matchMode` |
| `$c->rule` | `WarrantRule` | the responsible `cannot` |
| `$c->deniedAbilities` | `array` | the concrete abilities this message explains, with `*` resolved |

`deniedAbilities` has the wildcard expanded, so a per-clause message sees only the
abilities it is about.

## How the responsible rule is chosen

After a denial, Warrant walks the rules in resolver order, implicit rules first,
and surfaces the first message-bearing `cannot` whose condition actually matched.
If several forbid, the earliest one carrying a message wins.

Diagnosis runs the same condition SQL as the check, so it can never blame a rule
that did not fire. It works for no-row checks too, where only global or
unconditional `cannot` rules can be the cause.

## When nothing granted it

The other way a check fails is that no `cannot` forbade the user and no `can`
allowed them. There is no rule to point at, so the message lives on the schema:

```php
use Warrant\Schema\WarrantUngrantedContext;

public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
{
    return match (true) {
        in_array('approve', $c->ungrantedAbilities, true) => 'You need an approver role.',
        default => null,   // keep the generic 403
    };
}
```

The context is the same minus the rule, with `$c->ungrantedAbilities` in place of
`deniedAbilities`. Under `ANY` that is the whole gate, so you can say "you need at
least one of these"; under `ALL` it is just the missing subset.

## A default for message-less denials

A `cannot` with no message is still a deliberate forbid, so it gets its own schema
hook rather than falling through to the ungranted one:

```php
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
{
    return "You cannot {$c->deniedAbilities[0]} this document.";
}
```

It receives the full denial context, since there is a rule; it just carried no
message.

## Precedence

First non-null wins:

| Cause | Message used |
|---|---|
| a matching `cannot` with a message | that rule's message |
| a matching `cannot` without one | `forbiddenDenialMessage()` |
| nothing granted the ability | `ungrantedDenialMessage()` |
| none returned a message | the generic 403 |

Forbid sources are tried before the ungranted source. When abilities fail for mixed
reasons, being actively blocked and by what is the more specific answer.

:::tip[Route middleware surfaces these for free]
Targeted route middleware calls `authorize` under the hood, so a 403 on a
model-bound route already carries the responsible rule's message.
:::

## Round-tripping

`toSyntax()` re-renders a string message as `because '...'` and throws on a
closure, which has no inline form. `toBoundSyntax()` carries either losslessly, as
a `?` binding.
