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

Implement one method. Given a context, return the `RuleSetNode` that governs
this user's access to that resource:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): RuleSetNode
    {
        // $context->user       — the Authenticatable being checked (nullable)
        // $context->schemaKey  — e.g. 'documents'
        // $context->schema     — the schema class string
        // $context->model      — the model class string, or null (schema with no model)

        $grants = DB::table('role_permissions')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)
            ->pluck('rule');                    // ['if is_self they can view', ...]

        return WarrantSyntax::parse($grants->implode("\n")) // rules concatenate freely
            ->scopedTo($context->schemaKey);
    }
}
```

Store rule strings in a table, compose them from role flags, read them from JWT
claims — whatever fits. Warrant only cares that you return a `RuleSetNode`.

:::note[The resolver is container-resolved]
Warrant builds your resolver via `app()->make()`, so you can type-hint
dependencies in its constructor and they'll be injected.
:::

:::caution[No default resolver ships]
If `warrant.rule_resolver` is unset, the first check throws a `RuntimeException`:
*"No Warrant rule resolver configured."* You must configure one.
:::

## Building a rule set

Three ways to construct a `RuleSetNode`. Each takes the schema as a plain schema-key
string, or reads it from the text's own `for` header.

### From syntax

There is one parse for every form of rule text. `WarrantSyntax::parse()` (or
`Warrant::parse()` from the facade) reads the text, resolving bindings inline, and
returns a tree whose children say what the text held. Ask it for the shape you
expect:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

WarrantSyntax::parse('if is_self they can view', $bindings)->scopedTo('documents');
WarrantSyntax::parse('for documents { if is_self they can view }', $bindings)->ruleSet();
```

| The text holds | Ask for | You get |
| --- | --- | --- |
| headless rules, ability blocks, `@include`s | `scopedTo('documents')` | a `RuleSetNode` for that schema |
| one `for documents { … }` block, or `for documents` and a bare body | `ruleSet()` | that `RuleSetNode` |
| several `for <schema> { … }` blocks | `forSchema('documents')`, `ruleSets()` | the blocks for one schema folded together, or every block |
| exactly one rule | `rule()` | a `WarrantRuleNode` |
| a bare condition | `expression()` | an `IBooleanExpressionNode` |

`scopedTo()` is the call for a resolver: it scopes headless text to the schema
being resolved, and accepts text that already names that schema in a `for`
header, throwing if the header names a different one. A file of rules parses the
same way with `WarrantSyntax::parseFile($path)`. Several rule sets in one source
must each be braced — `for documents { … } for timesheets { … }` — because a bare
`for` body runs to the end of the input. The full table of shapes is in the
[Rule-building API](/reference/rule-building-api/#what-a-parse-returns).

Prefer writing the `for` header into text you author. A header travels with the
string, so editor tooling reading your source knows which schema to check the
condition and ability names against. Text with no header is still valid, just
unverifiable from the outside.

### From already-parsed rules

Build individual `WarrantRuleNode`s and compose them. `fromRules` takes a variadic
list *or* a single array (it flattens a mix of both), accepts builders directly,
and takes no bindings (the rules are already resolved):

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

$own      = WarrantSyntax::parse('if is_self they can view, update')->rule();
$noDelete = WarrantSyntax::parse('they cannot delete')->rule();

RuleSetNode::fromRules('documents', $own, $noDelete);
RuleSetNode::fromRules('documents', [$own, $noDelete]); // equivalent
```

### With a build callback

`RuleSetNode::build` hands you a factory; each `$rule()` call appends a builder:

```php
RuleSetNode::build('documents', function ($rule) {
    $rule()->if('is_self')->theyCan('view', 'update');
    $rule()->theyCannot('delete');
});
```

## Building rules programmatically

When a rule's shape depends on runtime data — a list of team ids, a feature
flag, values that don't belong in a string — the fluent builder is often clearer
than assembling DSL text. `WarrantRuleNode::build()` produces the **same AST** the
parser does, and nothing is serialized to a string, so arbitrary PHP values in
condition parameters survive untouched:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;

$rule = WarrantRuleNode::build()
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
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

class DocumentSchema extends WarrantSchema
{
    public function implicitRules(): array|RuleSetNode
    {
        return WarrantSyntax::parse('
            if is_admin they can *
            if is_suspended they cannot *
        ')->ruleEntries();
    }
}
```

You may return either a plain list of rule entries (above) or a fully-formed
`RuleSetNode` for this schema — whichever your baseline logic produces most
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
        'documents' => App\Warrant\DocumentSchema::class,
        'projects' => App\Warrant\ProjectSchema::class,
    ],
];
```

## Resolution lifetime

Your resolver is **not** called once per check. Warrant memoizes a guard per user
for the life of the request, and each guard memoizes the rule set it resolved, so
`resolve()` runs at most once per (user, schema) — no matter how many checks
follow:

```php
Warrant::can('view', $documentA);      // resolve() runs
Warrant::can('update', $documentB);    // memoized — no resolver call
Warrant::abilities($documentC);        // memoized
```

The rule set is also **validated** once, not once per check. This matters most on
list endpoints and Blade loops, where a `@can` inside a `@foreach` would otherwise
hit your rule store once per row.

### When the memo is dropped

Automatically, wherever a process moves on to unrelated work:

| Runtime | Dropped when |
| --- | --- |
| PHP-FPM | The process ends — the memo never outlives one request. |
| Octane | `RequestTerminated`, `TaskTerminated` |
| Queue workers | `JobProcessed`, `JobFailed` |

Without this, a long-lived worker would keep answering from rules resolved for an
earlier request, long after a role change should have taken effect.

### Flushing manually

The memo is keyed by user identity, not by rule content, so it cannot notice that
you changed someone's permissions mid-request. Flush after a write that must take
effect immediately:

```php
$user->roles()->attach($editorRole);

Warrant::flush($user);   // just this user
Warrant::flush();        // every user
```

`Warrant::flush($user)` matches on the auth identifier, so any instance of that
user works — you don't need the object you ran the original check with.

:::caution[A bare `flush()` means *everyone*]
Unlike the check methods, `flush()`'s `$user` argument does **not** fall back to
the authenticated user. That's deliberate: the usual reason to flush is a rule
change affecting many users, and silently narrowing `flush()` to the current user
would leave every other memo stale.
:::

If your resolver reads from a store that changes rarely, this in-request
memoization may be all the caching you need. Anything longer-lived — surviving
across requests — belongs in your resolver, where you control invalidation.
