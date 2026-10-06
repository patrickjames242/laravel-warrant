---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Time-bounded access
description: Grants that expire, embargoes, and why the clock belongs in context.
sidebar:
  order: 9
---

Access that starts or stops at a time: an embargoed article, a trial, a contractor
whose engagement ends, a pay period that closes.

The whole recipe is one habit. Put the clock in context rather than calling `now()`
inside a condition.

## The clock

```php
class DocumentSchema extends WarrantSchema
{
    protected function defaultContext(): array
    {
        return ['now' => now()];
    }

    #[RowCondition]
    public function isPublished(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('published_at'), '<=', $c->context['now']);
    }

    #[RowCondition]
    public function isExpired(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('expires_at'), '<=', $c->context['now']);
    }
}
```

```warrant
for documents {
    if is_published they can view
    if is_expired they cannot view, update because 'This document has expired.'
}
```

## Why the clock is context and not `now()`

**Tests get an actual seam.** Travelling in time works either way, but an explicit
clock lets you check both sides of a boundary in one test without global state:

```php
it('hides an article until its publish time', function () {
    $article = Document::factory()->create(['published_at' => '2026-10-01 09:00:00']);

    expect(Warrant::can('view', $article, ['now' => '2026-10-01 08:59:59']))->toBeFalse();
    expect(Warrant::can('view', $article, ['now' => '2026-10-01 09:00:01']))->toBeTrue();
});
```

**One list uses one instant.** A query filtering ten thousand rows with `now()`
inside the condition still resolves it once, at compile time, so this is mostly
about intent. Where it stops being theoretical is a report generated as of a past
date:

```php
Document::query()
    ->userHasAbility('view', context: ['now' => $asOf])
    ->get();
```

**Audit questions become expressible.** "What could this user see on the first of
the month?" is the same check with a different context value, which means you can
answer it without a second code path.

## Expiring grants

The most common shape is an expiry on the grant row rather than on the record:

```php
#[RowCondition]
public function grantedAndCurrent(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('document_grants')
        ->whereColumn('document_grants.document_id', $c->row('id'))
        ->where('document_grants.user_id', $c->user->getAuthIdentifier())
        ->where('document_grants.starts_at', '<=', $c->context['now'])
        ->where(fn ($q) => $q
            ->whereNull('document_grants.ends_at')
            ->orWhere('document_grants.ends_at', '>', $c->context['now'])));
}
```

```warrant
if granted_and_current they can view, update
```

No job is needed to clean up. An expired grant stops matching, and the row can be
tidied at leisure.

## Windows on the user rather than the row

A contractor whose engagement has a period is a global condition:

```php
#[GlobalCondition]
public function engagementIsCurrent(GlobalConditionContext $c): bool
{
    $now = $c->context['now'] ?? now();

    return $c->user->engagement_starts_at <= $now
        && ($c->user->engagement_ends_at === null || $c->user->engagement_ends_at > $now);
}
```

```text
if not engagement_is_current they cannot *
    because 'Your engagement is not currently active.'
```

Because it is a global condition returning a `bool`, a lapsed contractor gets
`where (1 = 0)` with nothing else emitted. [Reachability](/concepts/reachability/)
does not evaluate it, though, so it still reports a granted ability as `MAYBE`. To
hide the UI for a lapsed contractor, check the engagement directly.

## Periods as another schema

When the window is a record of its own, a pay period or an academic term, it is
[a hop](/rules/references/) rather than a column:

```warrant
for timesheets {
    if is_author and check(is_open for pay_periods(@column pay_period_id))
    they can submit

    if not check(is_open for pay_periods(@column pay_period_id))
    they cannot submit because 'That pay period is closed.'
}
```

```php
class PayPeriodSchema extends WarrantSchema
{
    #[RowCondition]
    public function isOpen(RowConditionContext $c): Builder
    {
        return $c->query
            ->where($c->row('opens_at'), '<=', $c->context['now'] ?? now())
            ->where($c->row('closes_at'), '>', $c->context['now'] ?? now());
    }
}
```

Remember that a hop hands the target a fresh context bag, holding only the
target's own defaults, so pass the clock across:

```warrant
if check(is_open for pay_periods(@column pay_period_id) with now = @context now)
they can submit
```

That is a case where forgetting the `with` is easy and the failure is quiet, since
the target falls back to the real `now()` and behaves almost right. It goes wrong
only when the check was made with a different clock, such as a report run for last
month: `timesheets` is judged at that time and `pay_periods` at this one. A
`#[RequiredContext]` on the target does not catch it, because a boundary does not
enforce required context, so the `with` is the only guard.

## Watch the null

`expires_at IS NULL` meaning "never expires" needs saying explicitly. A comparison
against null is unknown, which grants nothing, so a grant with no expiry would
silently do nothing:

```php
// Wrong: a null ends_at grants nothing.
->where('document_grants.ends_at', '>', $now)

// Right.
->where(fn ($q) => $q->whereNull('document_grants.ends_at')
                     ->orWhere('document_grants.ends_at', '>', $now))
```

See [true, false, and unknown](/concepts/three-truth-values/).
