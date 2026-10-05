---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Record states
description: Permissions that depend on where a row is in its lifecycle.
sidebar:
  order: 5
---

Drafts, submitted, approved, published, locked, archived. A state machine is the
other half of most real policies, and it is where the `cannot` clause earns its
keep.

## One condition per state

```php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW    = 'view';
    #[Ability] public const UPDATE  = 'update';
    #[Ability] public const SUBMIT  = 'submit';
    #[Ability] public const APPROVE = 'approve';
    #[Ability] public const DELETE  = 'delete';

    #[RowCondition]
    public function isDraft(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('state'), 'draft');
    }

    #[RowCondition]
    public function isSubmitted(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('state'), 'submitted');
    }

    #[RowCondition]
    public function isPublished(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('state'), 'published');
    }
}
```

A parameterized one reads better when you have many:

```php
#[RowCondition]
public function inState(RowConditionContext $c, string ...$states): Builder
{
    return $c->query->whereIn($c->row('state'), $states);
}
```

```warrant
if in_state('draft', 'submitted') they can update
```

## The rules

```warrant
for documents {
    if is_mine or in_my_team they can view

    can they update {
        if is_mine and is_draft they can
        if is_editor and in_state('draft', 'submitted') they can
        if is_published they cannot because 'Published documents cannot be edited.'
    }

    if is_mine and is_draft they can submit
    if is_reviewer and is_submitted they can approve
    if is_mine and is_draft they can delete
}
```

Read it top to bottom and the state machine is visible, which is the point.

## Enum-backed states

```php
enum DocumentState: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Published = 'published';
}
```

```php
#[RowCondition]
public function isDraft(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('state'), DocumentState::Draft);
}
```

Laravel unwraps a backed enum to its scalar value when binding, so the enum reaches
the query correctly. Rules still write the string, since the language has no enum
literal:

```warrant
if in_state('draft') they can update
```

## Watch for nullable state columns

A `NULL` state makes every comparison unknown, which grants nothing and, under a
`cannot`, drops the row. So a legacy row with a null `state` silently disappears
from every list.

Either make the column non-null with a default, or handle it:

```php
#[RowCondition]
public function isDraft(RowConditionContext $c): Builder
{
    return $c->query->where(fn ($q) => $q
        ->where($c->row('state'), 'draft')
        ->orWhereNull($c->row('state')));
}
```

See [true, false, and unknown](/concepts/three-truth-values/).

## Soft deletes

A soft-deleted row is a state like any other, and it is a good use for an
[implicit rule](/schemas/schema-policy/), since nobody should be able to grant
around it:

```php
#[RowCondition]
public function isTrashed(RowConditionContext $c): Builder
{
    return $c->query->whereNotNull($c->row('deleted_at'));
}

public function implicitRules(): array|RuleSetNode
{
    return [
        WarrantSyntax::parse(
            "if is_trashed they cannot update, submit, approve because 'This document is in the trash.'"
        )->rule(),
    ];
}
```

Note that `view` and `restore` are deliberately left out, since a trash screen has
to show them.

Remember Eloquent's own `SoftDeletes` scope is a global scope, and a Warrant
condition receives a builder carrying none. So the condition is doing real work
even on a model that soft-deletes.

## Time as a state

```php
#[RowCondition]
public function isEmbargoed(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('publish_at'), '>', $c->context['now'] ?? now());
}
```

```text
if is_embargoed and not is_editor they cannot view because 'This is not published yet.'
```

Passing the clock through context rather than calling `now()` makes it testable.
See [time-bounded access](/recipes/time-bounded/).

## Testing a transition

```php
it('stops edits once published', function () {
    $draft     = Document::factory()->create(['user_id' => $user->id, 'state' => 'draft']);
    $published = Document::factory()->create(['user_id' => $user->id, 'state' => 'published']);

    expect(Warrant::can('update', $draft, user: $user))->toBeTrue();
    expect(Warrant::can('update', $published, user: $user))->toBeFalse();
});

it('explains why', function () {
    Warrant::authorize('update', $published, user: $user);
})->throws(WarrantAuthorizationException::class, 'Published documents cannot be edited.');
```
