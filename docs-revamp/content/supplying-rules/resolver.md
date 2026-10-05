---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The resolver contract
description: The one method every Warrant application implements, and what it is handed.
sidebar:
  order: 1
---

Rules are data, and Warrant never invents them. It asks your resolver, at request
time, for the rules governing one user's access to one resource. This is the seam
where your access model meets Warrant, and it is the only one.

```php
namespace App\Warrant;

use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): RuleSetNode
    {
        $rules = DB::table('role_rules')
            ->where('role_id', $context->user->role_id)
            ->where('schema_key', $context->schemaKey)
            ->pluck('rule');

        return WarrantSyntax::parse($rules->implode("\n"))
            ->scopedTo($context->schemaKey);
    }
}
```

## What you are handed

```php
$context->user;       // the Authenticatable being checked, nullable
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

You are asked once per user and schema, so the resolver is called with one schema
key at a time rather than being asked for everything.

## What you must return

A `RuleSetNode` targeting the schema you were asked about. Returning one for a
different schema is caught:

```text
The rule resolver was asked for schema [documents] but returned a rule set
targeting [folders].
```

Returning an empty set is legitimate and means this user has no rules for this
resource, which denies everything. That is the right answer for an anonymous or
unprivileged user.

## Registering it

```php
// config/warrant.php
'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
```

Warrant builds it through the container, so constructor dependencies are injected:

```php
public function __construct(
    private RuleRepository $rules,
    private Tenancy $tenancy,
) {}
```

Warrant ships no default. Leave it unset and the first check says so:

```text
No Warrant rule resolver configured. Set warrant.rule_resolver to a class
implementing Warrant\Rules\RuleResolver.
```

## Shapes that work

**Rules stored as text, per role.** The example above. Strings concatenate freely,
so gluing them with newlines composes a policy.

**Rules built in code, per role name.** No storage at all, and the policy lives in
your repository where it can be reviewed:

```php
public function resolve(RuleResolutionContext $context): RuleSetNode
{
    return match ($context->user->role) {
        'admin'  => Warrant::parse("for {$context->schemaKey} { they can * }")->ruleSet(),
        'editor' => $this->editorRules($context),
        default  => RuleSetNode::fromRules($context->schemaKey),
    };
}
```

**Rules from a file on disk**, which is what [`.warrant`
files](/supplying-rules/where-rules-live/) are for:

```php
public function resolve(RuleResolutionContext $context): RuleSetNode
{
    $file = WarrantSyntax::parseFile(base_path("warrant/{$context->user->role}.warrant"));

    return $file->forSchema($context->schemaKey)
        ?? RuleSetNode::fromRules($context->schemaKey);
}
```

**Several sources merged**, which is the shape most real applications end up with.
That has [its own page](/supplying-rules/composing/).

## Where the user is null

`$context->user` is nullable, because a schema-bound guard can be constructed
before a check runs. In practice every check path requires a user, so a resolver
may safely treat null as "no rules":

```php
if ($context->user === null) {
    return RuleSetNode::fromRules($context->schemaKey);
}
```

## It is called once per request

Not once per check. Warrant memoizes a guard per user for the life of the request,
and each guard memoizes the rule set it resolved. See [memoization and
flushing](/supplying-rules/memoization/), which also covers what to do after a
permission change.
