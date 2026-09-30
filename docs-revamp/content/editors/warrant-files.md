---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: .warrant files
description: The standalone file format, what reads it, and how to use it at runtime.
sidebar:
  order: 6
---

A `.warrant` file is a group of `for <schema> { ... }` blocks. The header and braces
are mandatory on every block.

```warrant
# warrant/editor.warrant
#
# Rules for the editor role.

for documents {
    if is_mine or in_my_team they can view, update

    can they update {
        if is_locked they cannot because 'This document is locked.'
    }

    if is_mine they can delete
}

for folders {
    if is_member they can view
}

for comments {
    if can(view for documents(@column document_id)) they can view
    if is_author they can update, delete
}
```

Highlighted by extension in every editor that has the
[tooling](/editors/overview/) installed, with no per-file configuration.

## Reading one

```php
use Warrant\Rules\RuleSetGroup;

$group = RuleSetGroup::fromFile(base_path('warrant/editor.warrant'));

$group->forSchema('documents');   // the WarrantRuleSet, or null
$group->schemaKeys();             // ['documents', 'folders', 'comments']
count($group);                    // 3

foreach ($group as $ruleSet) {
    $ruleSet->validate();
}
```

Bindings work the same as anywhere:

```php
RuleSetGroup::fromFile(base_path('warrant/editor.warrant'), ['region' => 'west']);
```

An unreadable path throws:

```text
Cannot read Warrant rule file [/app/warrant/editor.warrant].
```

Blocks targeting the same schema are merged, rules concatenated in source order, so
a group holds at most one set per key. You can group by subject rather than by
schema if that reads better:

```warrant
# Ownership
for documents { if is_mine they can view, update }

# Team access
for documents { if in_my_team they can view }
```

## A resolver built on files

One file per role is the usual layout:

```text
warrant/
  roles/
    viewer.warrant
    editor.warrant
    approver.warrant
    admin.warrant
```

```php
class FileRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        $sets = [];

        foreach ($context->user->roleNames() as $role) {
            $path = base_path("warrant/roles/{$role}.warrant");

            if (! is_file($path)) {
                continue;
            }

            $set = $this->group($path)->forSchema($context->schemaKey);

            if ($set !== null) {
                $sets[] = $set;
            }
        }

        return $sets === []
            ? WarrantRuleSet::fromRules($context->schemaKey)
            : WarrantRuleSet::merge(...$sets);
    }

    private function group(string $path): RuleSetGroup
    {
        return Cache::rememberForever(
            'warrant.file.'.md5($path).'.'.filemtime($path),
            fn () => RuleSetGroup::fromFile($path),
        );
    }
}
```

Keying the cache on `filemtime` means an edit invalidates it without a deploy step.

## Validating them in CI

The reason to like files: your policy is in version control and reviewable, and a
mistake fails the build.

```php
it('every warrant file parses and validates', function () {
    foreach (glob(base_path('warrant/**/*.warrant')) as $path) {
        foreach (RuleSetGroup::fromFile($path) as $set) {
            $set->validate();
        }
    }
})->throwsNoExceptions();
```

A policy change then arrives as a diff a reviewer can read:

```diff
 for documents {
     if is_mine or in_my_team they can view, update
-    if is_mine they can delete
+    if is_mine and not is_published they can delete
 }
```

## Writing one back out

```php
file_put_contents(
    base_path('warrant/roles/editor.warrant'),
    $group->toSyntax(),
);
```

`toSyntax()` renders the canonical form with inline literals, and throws on
anything with no inline form, such as an array parameter or a closure denial
message. `toBoundSyntax()` handles those, at the cost of a separate bindings array:

```php
$bound = $group->toBoundSyntax();

$bound->syntax;     // parameterized with ?
$bound->bindings;   // one flat, left-to-right list across every block
```

That round-trip is how you would migrate rules from a database into files, or dump
a tenant's stored rules to review them.

## Files or the database?

Files when policy is part of the product, reviewed in pull requests, and the same
for every deployment.

The database when policy differs per tenant, or an administrator edits it. See
[an admin permissions UI](/recipes/admin-ui/).

Both, commonly: files for the baseline, the database for per-tenant overrides,
merged in the resolver. See
[composing from several sources](/supplying-rules/composing/).
