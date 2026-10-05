---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Testing rules
description: Swap in a fake resolver, seed rows, and assert on what comes back.
sidebar:
  order: 1
---

Test against a real database and assert on rows and ability lists. Warrant's own
suite drives real SQLite and does exactly that.

## Swap in a resolver

Bind an anonymous one so the test controls which rules apply, independent of your
production rule store:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

function bindRules(string $syntax, string $schemaKey = 'documents'): void
{
    app()->instance(RuleResolver::class, new class($syntax, $schemaKey) implements RuleResolver {
        public function __construct(private string $syntax, private string $schemaKey) {}

        public function resolve(RuleResolutionContext $context): RuleSetNode
        {
            return WarrantSyntax::parse($this->syntax)->scopedTo($context->schemaKey);
        }
    });

    Warrant::flush();
}
```

Then a test is three lines of setup and an assertion:

```php
it('shows a user only their own documents', function () {
    bindRules('if is_mine they can view');

    $mine   = Document::factory()->create(['user_id' => $this->user->id]);
    $theirs = Document::factory()->create();

    $visible = Document::query()->userHasAbility('view', $this->user)->pluck('id');

    expect($visible)->toContain($mine->id)->not->toContain($theirs->id);
});
```

:::note[Flush when you rebind]
A test is one long-lived request, and Warrant memoizes the rule set per user for
its life. If you swap the resolver or change a user's roles after a check has run,
call `Warrant::flush()` or the next check answers from the old rules. The helper
above does it for you.
:::

## What to assert

**Row filtering.** Seed rows that should and should not match, then assert on ids:

```php
expect(Document::query()->userHasAbility('update', $user)->pluck('id'))
    ->toEqualCanonicalizing([$mine->id, $teamDoc->id]);
```

**Per-row abilities**, remembering the list is in declaration order:

```php
expect(Document::query()->selectUserAbilities()->find($mine->id)->abilities)
    ->toBe(['view', 'update']);
```

**Boolean checks**, for specific targets and users:

```php
expect(Warrant::can('update', $locked, user: $user))->toBeFalse();
```

**That a `cannot` subtracts the right rows**, which is the assertion most likely to
catch a real mistake:

```php
it('removes locked documents from update, and nothing else', function () {
    bindRules('if is_mine they can view, update
               if is_locked they cannot update');

    expect(Warrant::can('view', $lockedMine, user: $user))->toBeTrue();
    expect(Warrant::can('update', $lockedMine, user: $user))->toBeFalse();
});
```

**Context**, both that it filters and that a missing required key throws:

```php
it('scopes to the workspace passed at the check', function () {
    expect(Warrant::can('view', $doc, ['workspace_id' => 'ws-1'], $user))->toBeTrue();
    expect(Warrant::can('view', $doc, ['workspace_id' => 'ws-2'], $user))->toBeFalse();
});

it('throws when the required key is absent', function () {
    Warrant::can('view', $doc, [], $user);
})->throws(InvalidArgumentException::class, 'requires context key(s) [workspace_id]');
```

**Denial messages**, since they are user-facing text:

```php
it('explains a locked document', function () {
    Warrant::authorize('update', $locked, user: $user);
})->throws(WarrantAuthorizationException::class, 'This document is locked.');
```

**Reachability**, which is cheap and catches whole-screen mistakes:

```php
expect(Warrant::possibleAbilities(Document::class, $user))->toBe(['view', 'update']);
expect(Warrant::neverHas(Document::class, 'delete', $user))->toBeTrue();
```

## The parity test

The property Warrant offers that hand-written policies cannot is that the check and
the list agree. Assert it once and you have pinned the thing that used to drift:

```php
it('filters to exactly what the boolean check allows', function () {
    $filtered = Document::query()->userHasAbility('view', $user)->pluck('id');

    $expected = Document::all()
        ->filter(fn ($d) => Warrant::can('view', $d, user: $user))
        ->pluck('id');

    expect($filtered->sort()->values())->toEqual($expected->sort()->values());
});
```

## Do not assert on SQL

Generated SQL is an implementation detail and varies by driver, so a string
assertion breaks on a version bump with nothing wrong:

```php
// Fragile.
expect($query->toSql())->toContain('documents"."user_id" = ?');

// Durable.
expect($visible)->toContain($mine->id);
```

The exception is a test about compilation itself, such as proving a constant folds
away. Warrant's own suite normalizes SQL before comparing for exactly that reason.

## Testing a real resolver

Bind the real one and control its inputs instead:

```php
it('gives an approver approve on their team', function () {
    RoleRule::create([
        'role' => 'approver',
        'schema_key' => 'documents',
        'rules' => 'if in_my_team they can approve',
    ]);

    $user = User::factory()->hasAttached(Role::approver())->create();

    expect(Warrant::can('approve', $teamDoc, user: $user))->toBeTrue();
});
```

That tests your storage and your resolver together, which is where the real
mistakes are.
