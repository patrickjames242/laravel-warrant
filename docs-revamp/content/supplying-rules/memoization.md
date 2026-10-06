---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Memoization and flushing
description: When your provider is called, when the memo is dropped, and how to flush after a change.
sidebar:
  order: 6
---

Your provider is not called once per check. Warrant memoizes a guard per user for
the life of the request, and each guard memoizes the rule set it resolved. So
`rules()` runs at most once per user and schema, however many checks follow:

```php
Warrant::can('view', $documentA);      // rules() runs
Warrant::can('update', $documentB);    // memoized
Warrant::abilities($documentC);        // memoized
Warrant::couldEverHave(Document::class, 'delete');   // memoized
```

The set is also validated once, not once per check. That matters most on list
endpoints and Blade loops, where a `@can` inside a `@foreach` would otherwise hit
your rule store once per row.

## Seeing what was resolved

```php
Warrant::forSchema(Document::class, $user)->resolvedRuleSet();
```

That is the merged, validated set actually in play: whatever your provider
returned, plus the schema's [own rules](/schemas/schema-policy/). It is the
first thing to look at when a rule is not behaving, and
[inspecting what is loaded](/diagnosis/inspecting/) covers using it.

## When the memo is dropped

Automatically, wherever a process moves on to unrelated work:

| Runtime | Dropped when |
| --- | --- |
| PHP-FPM | the process ends, so the memo never outlives one request |
| Octane | `RequestTerminated`, `TaskTerminated` |
| Queue workers | `JobProcessed`, `JobFailed` |

Without that, a long-lived worker would keep answering from rules resolved for an
earlier request, long after a role change should have taken effect.

## Flushing manually

The memo is keyed by user identity, not by rule content, so it cannot notice that
you changed someone's permissions mid-request. Flush after a write that has to take
effect immediately:

```php
$user->roles()->attach($editorRole);

Warrant::flush($user);   // this user
Warrant::flush();        // everyone
```

`Warrant::flush($user)` matches on the auth identifier, so any instance of that
user works. You do not need the object you ran the original check with.

:::caution[A bare `flush()` means everyone]
Unlike the check methods, `flush()`'s `$user` argument does **not** fall back to
the authenticated user. That is deliberate. The usual reason to flush is a rule
change affecting many users, and silently narrowing it to the current user would
leave every other memo stale.
:::

## Where it bites in tests

A test is one long-lived request, so the memo persists across everything in it. If
you swap the provider, or change a user's roles, after a check has already run, the
next check answers from the old rules:

```php
bindRules('if is_mine they can view');
expect(Warrant::can('view', $doc))->toBeTrue();

bindRules('they cannot view');
Warrant::flush();                                  // without this, still true
expect(Warrant::can('view', $doc))->toBeFalse();
```

See [testing rules](/testing/rules/).

## What this is not

In-request memoization, and nothing more. It does not survive a request, it is not
shared between processes, and it has no TTL.

If your provider reads from a store that changes rarely, this may be all the
caching you need. Anything longer lived belongs in your provider, where you control
invalidation:

```php
public function rules(RuleProviderContext $context): RuleSetNode
{
    $text = Cache::remember(
        "warrant.rules.{$context->user->role_id}.{$context->schemaKey}",
        now()->addMinutes(10),
        fn () => $this->fetchRuleText($context),
    );

    return Warrant::parse($text)->scopedTo($context->schemaKey);
}
```

Cache the text or the built set, and invalidate it on write. Warrant's own memo
then sits in front of yours, so a request that checks twenty documents still hits
neither.

More on the operational side in [memoization
limits](/production/memoization/) and [flushing after a
change](/production/flushing/).
