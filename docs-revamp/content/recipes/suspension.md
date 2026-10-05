---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Suspension and revocation
description: The kill switch, and what an unappealable cannot guarantees that a missing grant does not.
sidebar:
  order: 7
---

There are two ways a user ends up without access. Nobody granted it, or something
took it away. They look the same from outside and are very different to rely on.

A missing grant depends on every rule source agreeing to stay silent. A `cannot`
depends on nothing: no `can` anywhere can appeal it.

## The kill switch

```php
#[GlobalCondition]
public function isSuspended(GlobalConditionContext $c): bool
{
    return $c->user->suspended_at !== null;
}
```

```php
public function implicitRules(): array|RuleSetNode
{
    return [
        WarrantSyntax::parse(
            "if is_suspended they cannot * because 'Your account is suspended.'"
        )->rule(),
    ];
}
```

Two decisions in there, and both matter.

**`*` rather than a list.** Every ability the schema declares, including ones added
later.

**`implicitRules()` rather than the resolver.** The lockout holds whatever your
resolver returns, including a resolver with a bug in it. That is the whole point of
a lockout.

On a base schema, every resource inherits it:

```php
abstract class AppSchema extends WarrantSchema
{
    #[GlobalCondition]
    public function isSuspended(GlobalConditionContext $c): bool
    {
        return $c->user->suspended_at !== null;
    }

    public function implicitRules(): array|RuleSetNode
    {
        return [WarrantSyntax::parse("if is_suspended they cannot * because 'Your account is suspended.'")->rule()];
    }
}
```

## What it looks like when it fires

```php
Warrant::can('view', $document);                 // false
Document::query()->userHasAbility('view')->get(); // empty
Warrant::abilities($document);                    // []
Warrant::possibleAbilities(Document::class);      // []
```

```sql
select * from "documents" where (1 = 0)
```

`is_suspended` is `true` in PHP, so the unconditional denial makes every predicate
`false` and nothing else is emitted.

Because the denial is unconditional for a suspended user, reachability reports
`NEVER` for everything, so a well-built UI hides the controls rather than showing
them and failing.

## Partial suspension

The same shape, narrower:

```php
public function implicitRules(): array|RuleSetNode
{
    return [
        WarrantSyntax::parse(
            "if is_read_only they cannot update, delete, submit, approve
             because 'Your account is limited to read-only access.'"
        )->rule(),
    ];
}
```

Writing out the abilities is deliberate here. `*` would remove `view` too.

## Revoking one record

Suspension is about the user. Revocation is about a row, so it is a row condition:

```php
#[RowCondition]
public function accessRevoked(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('access_revocations')
        ->whereColumn('access_revocations.document_id', $c->row('id'))
        ->where('access_revocations.user_id', $c->user->getAuthIdentifier()));
}
```

```warrant
if access_revoked they cannot * because 'Your access to this document was revoked.'
```

No grant from any role, team, or share can bring it back, which is exactly what a
revocation should mean.

## Expiring suspension

Put the clock in context so it is testable, and default it:

```php
#[GlobalCondition]
public function isSuspended(GlobalConditionContext $c): bool
{
    $until = $c->user->suspended_until;

    return $until !== null && $until->isAfter($c->context['now'] ?? now());
}

protected function defaultContext(): array
{
    return ['now' => now()];
}
```

## Making it take effect now

The rule set is memoized per request. Flush after suspending:

```php
$user->update(['suspended_at' => now()]);

Warrant::flush($user);
```

Under Octane or in a queue worker the memo is dropped between requests and jobs
already, so this matters within the request that did the suspending, and for
anything long-running. See [flushing](/production/flushing/).

Sessions are a separate question. A suspended user's existing session stays valid
until you invalidate it; Warrant stops them acting, not being logged in.

## Testing

```php
it('removes everything from a suspended user, whatever their roles', function () {
    $user = User::factory()->admin()->create(['suspended_at' => now()]);

    expect(Warrant::can('view', $document, user: $user))->toBeFalse();
    expect(Warrant::possibleAbilities(Document::class, $user))->toBe([]);
    expect(Document::query()->userHasAbility('view', $user)->count())->toBe(0);
});

it('says why', function () {
    Warrant::authorize('view', $document, user: $suspended);
})->throws(WarrantAuthorizationException::class, 'Your account is suspended.');
```

The first test giving the user admin is the point: it proves the lockout beats the
grant rather than merely coexisting with its absence.
