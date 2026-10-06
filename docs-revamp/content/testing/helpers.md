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

use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

function bindRules(string $syntax, string $schemaKey = 'documents'): void
{
    bindSyntax(new WarrantSyntax([WarrantSyntax::parse($syntax)->scopedTo($schemaKey)]));
}

function bindGroup(string $syntax): void
{
    bindSyntax(WarrantSyntax::parse($syntax));
}

function bindSyntax(WarrantSyntax $syntax): void
{
    app()->instance(RuleResolver::class, new class($syntax) implements RuleResolver {
        public function __construct(private WarrantSyntax $syntax) {}

        public function resolve(RuleResolutionContext $context): RuleSetNode
        {
            return $this->syntax->forSchema($context->schemaKey)
                ?? new RuleSetNode($context->schemaKey);
        }
    });

    Warrant::flush();
}
```

`bindRules` takes rule text with no `for` header and gives it the schema with
`scopedTo`. Text that already names `documents` in a header is accepted too, and
text naming any other schema throws, so a test cannot silently bind rules to the
wrong schema.

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
            Warrant::validate(WarrantSyntax::parse($row->rules)->scopedTo($row->schema_key));
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
        Warrant::validate(WarrantSyntax::parseFile($path)->ruleSets());
    }
})->throwsNoExceptions();
```

`Warrant::validate()` takes one rule set, several, or arrays of them, and checks
each against the schema its own key names:

```php
Warrant::validate($setA, $setB, [$setC, $setD]);
```

Validation expands the rule set first, so it covers a rule template's body and a
derived condition's expression too. What it does not cover is anything depending on
a value, which the compiler checks when a check runs. See
[validation](/supplying-rules/validation/).

## A snapshot of the effective policy

Useful when a permission change is meant to be a no-op:

```php
it('has not changed the editor policy', function () {
    $set = Warrant::forSchema(Document::class, $editor)->resolvedRuleSet();

    expect((new WarrantSyntax([$set]))->toSyntax())->toMatchSnapshot();
});
```

That includes the schema's implicit rules, so it catches a change to either source.
