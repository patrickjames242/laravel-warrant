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
use Warrant\Rules\RuleProviderContext;
use Warrant\Rules\IRuleProvider;

function bindRules(string $syntax, string $schemaKey = 'documents'): void
{
    bindProvidedRules(WarrantSyntax::parse($syntax)->validate()->scopedTo($schemaKey));
}

function bindGroup(string $syntax): void
{
    bindProvidedRules($syntax);
}

function bindProvidedRules(RuleSetNode|string $rules): void
{
    app()->instance(IRuleProvider::class, new class($rules) implements IRuleProvider {
        public function __construct(private RuleSetNode|string $rules) {}

        public function rules(RuleProviderContext $context): RuleSetNode|string
        {
            return $this->rules;
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
change to rules, roles, or the provider needs a flush:

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
            WarrantSyntax::parse("for {$row->schema_key} {\n{$row->rules}\n}")->validate();
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
        WarrantSyntax::parseFile($path)->validate();
    }
})->throwsNoExceptions();
```

`validate()` checks a parsed text: every `for` body against the schema its header
names, and rules with no header against the schema you pass:

```php
WarrantSyntax::parseFile($path)->validate();
Warrant::parse("for documents {\n{$text}\n}")->validate();
```

Validation checks the rule set as written. A rule template's body and a derived
condition's expression are checked when a guard expands the rules, so a test that
runs a check covers them too. What neither covers is anything depending on a value,
which the compiler checks when a check runs. See
[validation](/supplying-rules/validation/).

## A snapshot of the effective policy

Useful when a permission change is meant to be a no-op:

```php
it('has not changed the editor policy', function () {
    $rules = Warrant::forSchema(Document::class, $editor)->expandedRuleSet()->rules;

    expect((new WarrantSyntax($rules))->validateWithoutSchemas()->toSyntax())->toMatchSnapshot();
});
```

That includes the schema's own rules, so it catches a change to either source.
