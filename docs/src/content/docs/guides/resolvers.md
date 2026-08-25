---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Providing rules
description: The RuleResolver interface, building rule sets, the fluent builder, and implicit rules.
sidebar:
  order: 5
---

Rules are data. Warrant never invents them — it asks *your* resolver for them at
request time. This is the seam where your access-control model meets Warrant.

## The `RuleResolver` interface

Implement one method. Given a context, return the `WarrantRuleSet` that governs
this user's access to that resource:

```php
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // $context->user       — the Authenticatable being checked (nullable)
        // $context->schemaKey  — e.g. 'documents'
        // $context->schema     — the schema class string
        // $context->model      — the model class string, or null (schema with no model)

        $grants = DB::table('role_permissions')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)
            ->pluck('rule');                    // ['if is_self they can view', ...]

        return WarrantRuleSet::fromSyntax(
            $grants->implode("\n"),             // rules concatenate freely
            $context->schemaKey,
        );
    }
}
```

Store rule strings in a table, compose them from role flags, read them from JWT
claims — whatever fits. Warrant only cares that you return a `WarrantRuleSet`.

:::note[The resolver is container-resolved]
Warrant builds your resolver via `app()->make()`, so you can type-hint
dependencies in its constructor and they'll be injected.
:::

:::caution[No default resolver ships]
If `warrant.rule_resolver` is unset, the first check throws a `RuntimeException`:
*"No Warrant rule resolver configured."* You must configure one.
:::

## Building a rule set

Three ways to construct a `WarrantRuleSet`. The first argument is always the
schema (a model instance, a schema instance, or a schema/model class string, or a
plain schema-key string):

### From syntax

Parse a string, resolving bindings inline:

```php
WarrantRuleSet::fromSyntax('if is_self they can view', 'documents', $bindings = []);
```

### From already-parsed rules

Build individual `WarrantRule`s and compose them. `fromRules` takes a variadic
list *or* a single array (it flattens a mix of both), accepts builders directly,
and takes no bindings (the rules are already resolved):

```php
use Warrant\RuleSyntaxTree\WarrantRule;

$own      = WarrantRule::fromSyntax('if is_self they can view, update');
$noDelete = WarrantRule::fromSyntax('they cannot delete');

WarrantRuleSet::fromRules('documents', $own, $noDelete);
WarrantRuleSet::fromRules('documents', [$own, $noDelete]); // equivalent
```

### With a build callback

`WarrantRuleSet::build` hands you a factory; each `$rule()` call appends a builder:

```php
WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_self')->theyCan('view', 'update');
    $rule()->theyCannot('delete');
});
```

### Directly with the parser

If you want the parsed rules without a rule set:

```php
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;

$rules = WarrantParser::parse('if is_self they can view', $bindings = []); // WarrantRule[]
$one   = WarrantParser::parseSingleRule('they cannot delete');            // WarrantRule
```

## Building rules programmatically

When a rule's shape depends on runtime data — a list of team ids, a feature
flag, values that don't belong in a string — the fluent builder is often clearer
than assembling DSL text. `WarrantRule::build()` produces the **same AST** the
parser does, and nothing is serialized to a string, so arbitrary PHP values in
condition parameters survive untouched:

```php
use Warrant\RuleSyntaxTree\WarrantRule;

$rule = WarrantRule::build()
    ->if('is_self')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->toRule();
```

The builder is its own topic — connectives, parenthesized groups, dynamic
composition, and splicing in DSL text are all covered in
[The rule builder](/guides/rule-builder/).

## Implicit rules

A schema can declare rules **always merged into the rule set**, regardless of
what the resolver returns, by overriding `implicitRules()`. They're added to
every resolved rule set before compilation, so they're validated and combine
exactly like resolver rules — and, like every rule, they're still
evaluated against the *current* user via their conditions:

```php
use Warrant\RuleSyntaxTree\WarrantRule;

class DocumentSchema extends WarrantSchema
{
    protected function implicitRules(): array|WarrantRuleSet
    {
        return [
            WarrantRule::fromSyntax('if is_admin they can *'),
            WarrantRule::fromSyntax('if is_suspended they cannot *'),
        ];
    }
}
```

You may return either a plain list of rules (above) or a fully-formed
`WarrantRuleSet` for this schema — whichever your baseline logic produces most
naturally. A returned rule set must target this schema.

Because rule order never matters, an implicit `cannot` beats any
resolver-supplied `can` — ideal for baseline guarantees like an admin escape
hatch or a suspension lockout.

## Registering the resolver

Warrant ships **no** default resolver. Configure one in `config/warrant.php`,
plus the list of schemas:

```php
return [
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,

    'schemas' => [
        App\Warrant\DocumentSchema::class,
        App\Warrant\ProjectSchema::class,
    ],
];
```
