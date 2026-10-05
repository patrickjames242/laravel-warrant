---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reading context in rules
description: The @context reference, and how it differs from a binding.
sidebar:
  order: 2
---

Write `@context <key>` wherever a rule needs a value that arrives with the check.
It stays symbolic in the parsed rule and is filled per check.

```warrant
if in_workspace(@context workspace_id) they can view, edit
```

The value is passed to the condition positionally, like any other argument:

```php
#[RowCondition]
public function inWorkspace(RowConditionContext $c, mixed $workspace): Builder
{
    return $c->query->where($c->row('workspace_id'), $workspace);
}
```

Type that parameter `mixed` or a nullable type. An absent optional key arrives as
`null`, and a `string` parameter would throw instead of producing the fail-closed
behaviour you want.

## It mixes freely with everything else

A `@context` reference carries no value at parse time, so it sits alongside
literals and bindings and never consumes a positional `?`:

```warrant
if scoped_to('projects', @context project_id, :region) they can view
```

```php
WarrantSyntax::parse($syntax, ['region' => 'west'])->scopedTo('documents');
```

That matters because bindings are strict. Every `:name` must have a value, every
value must be used, and named and positional bindings cannot be mixed in one
parse. A `@context` reference is exempt from all three, since it is a name, not a
value.

## It works everywhere an argument does

Condition parameters, row selectors, and `with` map values all accept one:

```warrant
if can(view for folders(@context folder_id)) they can view

if can(view for folders(@context folder_id) with tenant_id = @context tenant_id)
they can view
```

A row selector may even be a whole model rather than a key, which is often the
most direct thing to write since you usually have it already:

```warrant
if can(view for folders(@context folder)) they can view
```

```php
$user->warrant()->can('view', $document, ['folder' => $folder]);
```

Handing over a hydrated model lets the hop settle without a subquery at all. See
[cross-schema references with the row in hand](/sql/joins/).

## Building one without a string

When you assemble rules with the fluent builder, `Ref::context()` is the same
reference:

```php
use Warrant\Builders\Ref;

WarrantRuleNode::build()
    ->if('scoped_to', ['projects', Ref::context('project_id'), $region])
    ->theyCan('view')
    ->toRule();
```

It stays symbolic in the AST and is filled per check, exactly as the `@context`
form is.

## What a message cannot do

`because '...'` takes a literal, not a `@context` reference. A message is fixed
when the rule is parsed. For a message that varies per check, use a closure carried
through a binding: [denial messages](/rules/denial-messages/).
