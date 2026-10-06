---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Testing
description: Test your schemas against a real database by swapping in a fake resolver.
sidebar:
  order: 12
---

Warrant's own suite drives real SQLite and asserts on rows and ability lists
rather than SQL strings. The same approach works for your schemas: register a
fake resolver that returns a fixed `RuleSetNode`, seed a table, and assert what
comes back.

## Swap in a fake resolver

Bind an anonymous `RuleResolver` for the test so you control exactly which rules
apply, independent of your production rule store:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

app()->instance(RuleResolver::class, new class implements RuleResolver {
    public function resolve(RuleResolutionContext $context): RuleSetNode
    {
        return WarrantSyntax::parse('if is_self they can view')
            ->scopedTo($context->schemaKey);
    }
});

$visible = Document::query()->userHasAbility('view', $user)->pluck('id');

expect($visible)
    ->toContain($ownDocument->id)
    ->not->toContain($othersDocument->id);
```

:::note[Rebinding a resolver mid-test]
Warrant memoizes each user's rule set for the life of the request — and a test
*is* one long-lived request. If you swap the resolver, or change a user's roles,
after a check has already run, call `Warrant::flush()` so the next check picks up
the new rules. See [Resolution lifetime](/guides/resolvers/#resolution-lifetime).
:::

## What to assert

Test against real data, the way your app queries it:

- **Row filtering** — seed rows that should and shouldn't match, then assert
  `->userHasAbility(...)->pluck('id')` contains exactly the right ones.
- **Per-row abilities** — assert `->selectUserAbilities()->get()->first()->abilities`
  equals the expected list (remember it's in
  [declaration order](/guides/schemas/#abilities)).
- **Boolean checks** — assert `Warrant::can(...)` is `true` / `false`
  for specific targets and users.
- **A `cannot` wins** — add a `cannot` rule and assert it subtracts the right rows.
- **Context** — pass a `context:` array and assert the frame filters correctly;
  assert a missing *required* key throws.

## Validate stored rules in CI

If you store rule strings as data, catch typos before they reach production by
compiling them against the schema in a test. `Warrant::validate()` takes one rule
set, several, or an array of them, and runs the same name-checking the compiler
does:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;

Warrant::validate(WarrantSyntax::parse($storedRuleString)->scopedTo('documents'));
// throws if the string names an unknown ability or condition
```

This turns "a typo in a stored rule silently grants/denies" into a failing test.

## Testing conditions directly

Because a condition's whole job is to emit SQL, the most reliable test is a
behavioural one — seed rows, run a scoped query, assert the result set. Avoid
asserting on generated SQL strings; they're an implementation detail and vary by
driver.
