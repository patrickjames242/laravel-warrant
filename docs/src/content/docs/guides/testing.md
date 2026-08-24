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
fake resolver that returns a fixed `WarrantRuleSet`, seed a table, and assert what
comes back.

## Swap in a fake resolver

Bind an anonymous `RuleResolver` for the test so you control exactly which rules
apply, independent of your production rule store:

```php
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

app()->instance(RuleResolver::class, new class implements RuleResolver {
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return WarrantRuleSet::fromSyntax(
            $context->schemaKey,
            'if is_self they can view',
        );
    }
});

$visible = Document::query()->userHasAbility('view', $user)->pluck('id');

expect($visible)
    ->toContain($ownDocument->id)
    ->not->toContain($othersDocument->id);
```

## What to assert

Test against real data, the way your app queries it:

- **Row filtering** — seed rows that should and shouldn't match, then assert
  `->userHasAbility(...)->pluck('id')` contains exactly the right ones.
- **Per-row abilities** — assert `->selectUserAbilities()->get()->first()->abilities`
  equals the expected list (remember it's in
  [declaration order](/guides/schemas/#abilities)).
- **Boolean checks** — assert `Model::userHasAbilities(...)` is `true` / `false`
  for specific targets and users.
- **A `cannot` wins** — add a `cannot` rule and assert it subtracts the right rows.
- **Context** — pass a `context:` array and assert the frame filters correctly;
  assert a missing *required* key throws.

## Validate stored rules in CI

If you store rule strings as data, catch typos before they reach production by
compiling them against the schema in a test. `WarrantRuleSet::validate()` (and
`validateAll()` for a batch) runs the same name-checking the compiler does:

```php
WarrantRuleSet::fromSyntax('documents', $storedRuleString)->validate();
// throws if the string names an unknown ability, condition, or context key
```

This turns "a typo in a stored rule silently grants/denies" into a failing test.

## Testing conditions directly

Because a condition's whole job is to emit SQL, the most reliable test is a
behavioural one — seed rows, run a scoped query, assert the result set. Avoid
asserting on generated SQL strings; they're an implementation detail and vary by
driver.
