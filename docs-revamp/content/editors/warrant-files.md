---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: .warrant files
description: The standalone file format, what reads it, and how to use it at runtime.
sidebar:
  order: 6
---

A `.warrant` file is a run of `for <schema> { ... }` blocks. With more than one
block, the header and braces are mandatory on every one.

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
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;

$file = WarrantSyntax::parseFile(base_path('warrant/editor.warrant'));

$file->forSchema('documents');   // the RuleSetNode, or null
$file->schemaKeys();             // ['documents', 'folders', 'comments']
$file->ruleSets();               // every block, in source order

Warrant::validate($file->ruleSets());
```

Bindings work the same as anywhere:

```php
WarrantSyntax::parseFile(base_path('warrant/editor.warrant'), ['region' => 'west']);
```

An unreadable path throws:

```text
Unable to read Warrant rule file [/app/warrant/editor.warrant].
```

`forSchema()` folds every block targeting the same schema into one set, entries
concatenated in source order. You can group by subject rather than by schema if
that reads better:

```warrant
# Ownership
for documents { if is_mine they can view, update }

# Team access
for documents { if in_my_team they can view }
```

## A provider built on files

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
class FileRuleProvider implements RuleProvider
{
    public function rules(RuleProviderContext $context): RuleSetNode
    {
        $sets = [];

        foreach ($context->user->roleNames() as $role) {
            $path = base_path("warrant/roles/{$role}.warrant");

            if (! is_file($path)) {
                continue;
            }

            $set = $this->parsed($path)->forSchema($context->schemaKey);

            if ($set !== null) {
                $sets[] = $set;
            }
        }

        return $sets === []
            ? RuleSetNode::fromRules($context->schemaKey)
            : RuleSetNode::merge(...$sets);
    }

    private function parsed(string $path): WarrantSyntax
    {
        return Cache::rememberForever(
            'warrant.file.'.md5($path).'.'.filemtime($path),
            fn () => WarrantSyntax::parseFile($path),
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
        Warrant::validate(WarrantSyntax::parseFile($path)->ruleSets());
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
    $file->toSyntax(),
);
```

`WarrantSyntax::toSyntax()` renders the canonical form with inline literals, and throws on
anything with no inline form, such as an array parameter or a closure denial
message. `toBoundSyntax()` handles those, at the cost of a separate bindings array:

```php
$bound = $file->toBoundSyntax();

$bound->syntax;     // parameterized with ?
$bound->bindings;   // one flat, left-to-right list across every block
```

Rule sets from anywhere else, such as rows in a database, write out the same way
once they are in a tree: `(new WarrantSyntax($ruleSets))->toSyntax()`.

That round-trip is how you would migrate rules from a database into files, or dump
a tenant's stored rules to review them.

## Files or the database?

Files when policy is part of the product, reviewed in pull requests, and the same
for every deployment.

The database when policy differs per tenant, or an administrator edits it. See
[an admin permissions UI](/recipes/admin-ui/).

Both, commonly: files for the baseline, the database for per-tenant overrides,
merged in the provider. See
[composing from several sources](/supplying-rules/composing/).
