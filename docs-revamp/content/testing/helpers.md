---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Test helpers
description: A small set of helpers worth writing once, and validating stored rules in CI.
sidebar:
  order: 3
---

Warrant ships no test-specific API, because the ordinary one is small enough. What
pays for itself is three helpers of your own.

## Bind a rule set

```php
// tests/Support/Warrant.php

use Warrant\Facades\Warrant;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\RuleSetGroup;
use Warrant\Rules\WarrantRuleSet;

function bindRules(string $syntax, string $schemaKey = 'documents'): void
{
    bindGroup("for {$schemaKey} { {$syntax} }");
}

function bindGroup(string $syntax): void
{
    $group = RuleSetGroup::fromSyntax($syntax);

    app()->instance(RuleResolver::class, new class($group) implements RuleResolver {
        public function __construct(private RuleSetGroup $group) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->group->forSchema($context->schemaKey)
                ?? WarrantRuleSet::fromRules($context->schemaKey);
        }
    });

    Warrant::flush();
}
```

`bindGroup` covers several schemas at once, which cross-schema tests need:

```php
bindGroup(<<<'WARRANT'
    for folders   { if is_owner they can view }
    for documents { if can(view for folders(@column folder_id)) they can view }
WARRANT);
```

## Register schemas per test

Tests often need a schema that does not exist in your application:

```php
function useSchemas(array $schemas): void
{
    config()->set('warrant.schemas', $schemas);

    Warrant::flush();
}
```

```php
beforeEach(fn () => useSchemas([
    'documents' => DocumentSchema::class,
    'folders'   => FolderSchema::class,
]));
```

## Assert on what is visible

```php
function visibleIds(string $ability, $user, string $model = Document::class): Collection
{
    return $model::query()->userHasAbility($ability, $user)->pluck('id');
}
```

```php
expect(visibleIds('view', $user))->toEqualCanonicalizing([$a->id, $b->id]);
```

## Remember to flush

A test is one long-lived request, so the memo persists across everything in it. Any
change to rules, roles, or the resolver needs a flush:

```php
beforeEach(fn () => Warrant::flush());
```

Putting that in a global `beforeEach` removes a whole category of confusing test
failures.

## Validating stored rules in CI

If you store rules as data, this is the highest-value test in your suite. It turns
"a typo silently grants or denies" into a failing build:

```php
it('every stored rule still compiles', function () {
    $broken = [];

    foreach (RoleRule::all() as $row) {
        try {
            WarrantRuleSet::fromSyntax($row->rules, $row->schema_key)->validate();
        } catch (Throwable $e) {
            $broken[] = "{$row->role}/{$row->schema_key}: {$e->getMessage()}";
        }
    }

    expect($broken)->toBe([]);
});
```

For rules in `.warrant` files, walk the directory:

```php
it('every warrant file parses and validates', function () {
    foreach (glob(base_path('warrant/*.warrant')) as $path) {
        $group = RuleSetGroup::fromFile($path);

        foreach ($group as $set) {
            $set->validate();
        }
    }
})->throwsNoExceptions();
```

`WarrantRuleSet::validateAll()` does a batch in one call:

```php
WarrantRuleSet::validateAll($setA, $setB, [$setC, $setD]);
```

Note what validation does not cover: a rule template's body, a derived condition's
expression, and anything depending on a value. Those are checked by the compiler
when a check runs. See
[validation](/supplying-rules/validation/).

## A snapshot of the effective policy

Useful when a permission change is meant to be a no-op:

```php
it('has not changed the editor policy', function () {
    $set = Warrant::forSchema(Document::class, $editor)->resolvedRuleSet();

    expect($set->toSyntax())->toMatchSnapshot();
});
```

That includes the schema's implicit rules, so it catches a change to either source.
