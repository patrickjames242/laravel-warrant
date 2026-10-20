---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 3. Supply the rule
description: Write the provider that hands Warrant the current user's rules.
sidebar:
  order: 3
---

Warrant never invents a rule. It asks your rule provider for them, once per user
and schema, at request time. A provider is optional — a schema can return its own
rules — but it is the usual home for rules that live outside your code.

This one reads the file from step two:

```php
namespace App\Warrant;

use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

class FileRuleProvider implements RuleProvider
{
    public function rules(RuleProviderContext $context): BoundSyntax
    {
        return warrant_file(base_path('warrant/documents'));
    }
}
```

The provider is asked once for each schema a check touches. `warrant_file()` reads
the file, and Warrant keeps the `for documents` block when the schema is
`documents` and leaves out every block for another schema. Every user gets the
same rules here,
which is fine for a first pass. The context tells you who is asking and what
about:

```php
$context->user;       // the Authenticatable being checked
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

A real provider usually picks rules per user. One file per role:

```php
public function rules(RuleProviderContext $context): BoundSyntax
{
    return warrant_file(base_path("warrant/{$context->user->role}"));
}
```

Or rules stored per role in the database:

```php
public function rules(RuleProviderContext $context): iterable
{
    return DB::table('role_rules')
        ->where('role_id', $context->user->role_id)
        ->where('schema_key', $context->schemaKey)
        ->pluck('rule');
}
```

A collection of rule text is a valid return. Each row is read as rule text for the
schema you were asked about, and the rows merge into one set.

## Wire it up

```php
// config/warrant.php
return [
    'rule_provider' => App\Warrant\FileRuleProvider::class,

    'schemas' => [
        'documents' => App\Warrant\DocumentSchema::class,
    ],

    'register_gate' => true,
];
```

Warrant builds the provider through the container, so constructor dependencies are
injected.

Leave `rule_provider` unset and only the schema's own `rules()` apply, which for
this schema is none, so every check denies.

Next: [ask a question](/first-rule/check/).
