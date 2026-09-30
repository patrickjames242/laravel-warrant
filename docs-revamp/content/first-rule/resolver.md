---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 3. Supply the rule
description: Write the resolver that hands Warrant the current user's rules.
sidebar:
  order: 3
---

Warrant never invents a rule. It asks your resolver for them, once per user and
schema, at request time. Warrant ships no default resolver, so this class is
required.

```php
namespace App\Warrant;

use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return WarrantRuleSet::fromSyntax(
            'if is_mine they can view, update',
            $context->schemaKey,
        );
    }
}
```

Every user gets the same rule here, which is fine for a first pass. The context
tells you who is asking and what about:

```php
$context->user;       // the Authenticatable being checked
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

A real resolver usually reads from somewhere. Rules stored per role:

```php
public function resolve(RuleResolutionContext $context): WarrantRuleSet
{
    $lines = DB::table('role_rules')
        ->where('role_id', $context->user->role_id)
        ->where('schema_key', $context->schemaKey)
        ->pluck('rule');

    return WarrantRuleSet::fromSyntax($lines->implode("\n"), $context->schemaKey);
}
```

Rules concatenate freely, so gluing strings together with newlines is a legitimate
way to compose a policy.

## Wire it up

```php
// config/warrant.php
return [
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,

    'schemas' => [
        'documents' => App\Warrant\DocumentSchema::class,
    ],

    'register_gate' => true,
];
```

Warrant builds the resolver through the container, so constructor dependencies are
injected.

Leave `rule_resolver` unset and the first check tells you:

```text
No Warrant rule resolver configured. Set warrant.rule_resolver to a class
implementing Warrant\Rules\RuleResolver.
```

Next: [ask a question](/first-rule/check/).
