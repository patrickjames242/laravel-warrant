---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Flushing after a permission change
description: Making a change take effect, promptly and no more widely than needed.
sidebar:
  order: 4
---

```php
Warrant::flush($user);   // one user
Warrant::flush();        // everyone
```

Flushing drops the memoized guard and rule set, so the next check re-runs your
provider.

:::caution[A bare `flush()` means everyone]
Unlike the check methods, `flush()`'s `$user` argument does **not** fall back to
the authenticated user. Omitting it flushes every memo in the process. That is
deliberate, because the usual reason to flush is a change affecting many users, and
silently narrowing it would leave every other memo stale.
:::

`Warrant::flush($user)` matches on the auth identifier, so any instance of that user
works. You do not need the object the original check ran with.

## When you need it

Only when a change has to take effect **within the request or job that made it**.
Across requests the memo never survives, and Octane and queue workers drop it
between requests and jobs.

So the list is short.

**Granting or revoking a role, then checking:**

```php
$user->roles()->attach($editorRole);
Warrant::flush($user);

return ['can' => Warrant::abilities($document, user: $user)];
```

**Editing rules in an admin UI**, where the response should reflect the new
policy:

```php
$roleRule->update(['rules' => $request->string('rules')]);
Warrant::flush();
```

**Suspending someone** who is acting in the same request.

**Switching tenant or impersonating** mid-request, since the memo is keyed by user
rather than by frame.

**In tests**, after rebinding the provider or changing roles.

## When you do not

**After changing a row.** Locking a document changes what the *conditions* match,
not what the rules are. The memo holds rules, and conditions are evaluated per
check:

```php
$document->update(['locked' => true]);

Warrant::can('update', $document);   // already false; no flush needed
```

This is the most common unnecessary flush, and it is worth understanding because it
explains what the memo actually holds.

**On every request.** The memo is per request already.

**After a write in a queued job**, unless the job itself checks afterwards.

## Clearing your own cache too

If your provider caches, Warrant's flush does not reach it:

```php
public function updateRules(RoleRule $rule, string $text): void
{
    $rule->update(['rules' => $text]);

    Cache::forget("warrant.rules.{$rule->role}.{$rule->schema_key}");
    Warrant::flush();
}
```

Putting both in one method is the pattern. Two invalidations with one caller is
much easier to keep right than two callers.

## Across processes

`Warrant::flush()` is in-process. Under Octane with several workers, or across
several servers, it clears the memo in the process that called it and no other.

That is usually fine, because the memo does not survive a request anyway. Where it
matters is your own longer-lived cache, and there the answer is the cache's own
invalidation, which is already cross-process:

```php
Cache::tags(['warrant', "role:{$role}"])->flush();
```

A cache TTL is the backstop. Ten minutes of staleness on a permission change is
usually acceptable; if it is not for you, prefer explicit invalidation over a short
TTL, since a short TTL costs you on every request and only helps on the rare one.

## An event listener

When permission changes come from several places, listen rather than remembering:

```php
class FlushWarrantMemo
{
    public function handle(RolesChanged|RulesUpdated|UserSuspended $event): void
    {
        Cache::forget("warrant.rules.{$event->roleKey()}");

        $event->user === null
            ? Warrant::flush()
            : Warrant::flush($event->user);
    }
}
```
