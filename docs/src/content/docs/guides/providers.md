---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Providing rules
description: The RuleProvider interface, building rule sets, the fluent builder, and schema rules.
sidebar:
  order: 5
---

Rules are data. Warrant never invents them — it asks *your* code for them at
request time: an optional global rule provider, and each schema's own `rules()`.
This is the seam where your access-control model meets Warrant.

## The `RuleProvider` interface

Implement one method. Given a context, return the rules that govern this user's
access to that resource:

```php
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

class DatabaseRuleProvider implements RuleProvider
{
    public function rules(RuleProviderContext $context): iterable
    {
        // $context->user       — the Authenticatable being checked (nullable)
        // $context->schemaKey  — e.g. 'documents'
        // $context->schema     — the schema class string
        // $context->model      — the model class string, or null (schema with no model)

        $grants = DB::table('role_permissions')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)
            ->pluck('rule');                    // ['if is_self they can view', ...]

        return $grants;                         // a collection of rule text
    }
}
```

Store rule strings in a table, compose them from role flags, read them from JWT
claims — whatever fits. Return them in whichever form you have them:

| Return | Read as |
| --- | --- |
| `string` | rule text — rules with no `for` header, or `for <schema>` rule sets |
| `WarrantSyntax` | already-parsed rule text, read the same way |
| `RuleSetNode` | its rules |
| a rule entry (`WarrantRuleNode`, ability block, include) | that one entry |
| `array` / `Collection` / any iterable | each element read by these same rules, in order |

Every rule set among them — a `RuleSetNode`, or a `for <schema>` header in rule
text — must target `$context->schemaKey`; anything else throws. An empty string,
array or collection means no rules.

:::note[The provider is container-resolved]
Warrant builds your provider via `app()->make()`, so you can type-hint
dependencies in its constructor and they'll be injected.
:::

:::note[The global provider is optional]
If `warrant.rule_provider` is unset, each schema's [own rules](#schema-rules)
govern access to it alone. With neither, a schema's rule set is empty and every
check denies.
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
| unscoped rules, ability blocks, `@include`s | `scopedTo('documents')` | a `RuleSetNode` for that schema |
| one `for documents { … }` block, or `for documents` and a bare body | `ruleSet()` | that `RuleSetNode` |
| several `for <schema> { … }` blocks | `forSchema('documents')`, `ruleSets()` | the blocks for one schema folded together, or every block |
| exactly one rule | `rule()` | a `WarrantRuleNode` |
| a bare condition | `expression()` | an `IBooleanExpressionNode` |

`scopedTo()` is the call for a provider: it gives unscoped text its schema
being resolved, and accepts text that already names that schema in a `for`
header, throwing if the header names a different one. A file of rules parses the
same way with `WarrantSyntax::parseFile($path)`, where the `.warrant` extension
may be left off. Several rule sets in one source must each be braced —
`for documents { … } for timesheets { … }` — because a bare `for` body runs to
the end of the input. The full table of shapes is in the
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

## Schema rules

A schema can supply its own rules by overriding `rules()`. They're **merged ahead
of whatever the global provider returns** (or stand alone when no provider is
configured), so they're validated and combine exactly like provider rules — and,
like every rule, they're still evaluated against the *current* user via their
conditions:

```php
use Warrant\Rules\RuleProviderContext;

class DocumentSchema extends WarrantSchema
{
    public function rules(RuleProviderContext $context): string
    {
        return '
            if is_admin they can *
            if is_suspended they cannot *
        ';
    }
}
```

It may return any form the global provider may (see the table above). A
returned rule set must target this schema.

`rules()` receives the same `RuleProviderContext` the global provider does, so a
schema can return rules for this user alone:

```php
public function rules(RuleProviderContext $context): iterable
{
    return DB::table('document_grants')
        ->where('user_id', $context->user?->getAuthIdentifier())
        ->pluck('rule');
}
```

Because rule order never matters, a schema's `cannot` beats any
provider-supplied `can` — ideal for baseline guarantees like an admin escape
hatch or a suspension lockout.

## Registering the provider

A global provider is optional. To use one, set it in `config/warrant.php`,
alongside the list of schemas:

```php
return [
    'rule_provider' => App\Warrant\DatabaseRuleProvider::class,

    'schemas' => [
        'documents' => App\Warrant\DocumentSchema::class,
        'projects' => App\Warrant\ProjectSchema::class,
    ],
];
```

## Resolution lifetime

Neither your provider nor a schema's `rules()` is called once per check. Warrant memoizes a guard per user
for the life of the request, and each guard memoizes the rule set it resolved, so
`rules()` runs at most once per (user, schema) — no matter how many checks
follow:

```php
Warrant::can('view', $documentA);      // rules() runs
Warrant::can('update', $documentB);    // memoized — no provider call
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

If your provider reads from a store that changes rarely, this in-request
memoization may be all the caching you need. Anything longer-lived — surviving
across requests — belongs in your provider, where you control invalidation.
