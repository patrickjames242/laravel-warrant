---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Testing schemas and conditions
description: Exercising a condition directly, and the traps worth a test of their own.
sidebar:
  order: 2
---

A condition's whole job is to emit SQL, so the reliable test is behavioural: seed
rows, run a scoped query, assert the result set.

## One condition at a time

Bind a rule set that uses exactly the condition under test:

```php
it('is_mine matches only rows owned by the user', function () {
    bindRules('if is_mine they can view');

    $mine   = Document::factory()->create(['user_id' => $this->user->id]);
    $theirs = Document::factory()->create();

    expect(Document::query()->userHasAbility('view', $this->user)->pluck('id'))
        ->toEqualCanonicalizing([$mine->id]);
});
```

That is the shape for every condition. One rule, two rows, one assertion.

## The negation, separately

Negation is pushed to the leaves, and a condition that behaves oddly under `not` is
usually one with a null in it:

```php
it('not is_locked keeps unlocked rows and drops null ones', function () {
    bindRules('if not is_locked they can view');

    $unlocked = Document::factory()->create(['locked' => false]);
    $locked   = Document::factory()->create(['locked' => true]);
    $unknown  = Document::factory()->create(['locked' => null]);

    expect(Document::query()->userHasAbility('view', $this->user)->pluck('id'))
        ->toEqualCanonicalizing([$unlocked->id]);
});
```

The null row is the point. If that surprises you, see
[where unknown goes in SQL](/sql/unknown/).

## Both branches of a condition that answers in PHP

A condition using `$c->model` has two implementations of one rule, and nothing
checks that they agree. Test that they do:

```php
it('answers the same whether or not the row was hydrated', function () {
    bindRules('if is_mine they can view');

    $mine = Document::factory()->create(['user_id' => $this->user->id]);

    // PHP branch: a hydrated model.
    expect(Warrant::can('view', $mine, user: $this->user))->toBeTrue();

    // SQL branch: by key, and through the filter.
    expect(Warrant::can('view', [Document::class, $mine->id], user: $this->user))->toBeTrue();
    expect(Document::query()->userHasAbility('view', $this->user)->pluck('id'))
        ->toContain($mine->id);
});
```

Run that for every condition with a `$c->model` branch. It is the cheapest test in
this section and it catches the most expensive bug.

## Aliasing

A condition that hard-codes its table works until someone aliases the query or
reaches the schema through a hop. Pin it:

```php
it('follows an aliased query', function () {
    bindRules('if is_mine they can view');

    $guard = Warrant::forSchema(Document::class, $this->user);

    $rows = $guard->filterQuery(DB::table('documents as d'), 'view')->get();

    expect($rows->pluck('id'))->toContain($mine->id);
});
```

If the condition wrote `documents.user_id`, that either errors or silently reads the
wrong thing. See [frames](/concepts/frames/).

## Context

```php
it('passes an absent optional key as null', function () {
    bindRules('if in_workspace(@context workspace_id) they can view');

    expect(Warrant::can('view', $doc, [], $this->user))->toBeFalse();
    expect(Warrant::can('view', $doc, ['workspace_id' => $doc->workspace_id], $this->user))->toBeTrue();
});
```

## Cross-schema hops

Both schemas have to be registered, and the interesting assertion is that the
target's rules actually decide:

```php
it('lets a document inherit its folder permission', function () {
    bindGroup(<<<'WARRANT'
        for folders   { if is_owner they can view }
        for documents { if can(view for folders(@column folder_id)) they can view }
    WARRANT);

    $mine   = Folder::factory()->create(['owner_id' => $this->user->id]);
    $theirs = Folder::factory()->create();

    $visible = Document::factory()->create(['folder_id' => $mine->id]);
    $hidden  = Document::factory()->create(['folder_id' => $theirs->id]);

    expect(Document::query()->userHasAbility('view', $this->user)->pluck('id'))
        ->toEqualCanonicalizing([$visible->id]);
});
```

## Schemas with no model

Start from the guard, since there is no model to scope from:

```php
it('gates settings on the admin flag', function () {
    bindRules('if is_admin they can manage', schemaKey: 'settings');

    expect(Warrant::can('manage', 'settings', user: $admin))->toBeTrue();
    expect(Warrant::can('manage', 'settings', user: $ordinary))->toBeFalse();
});
```

## Virtual tables

Also from the guard, and the filter is the thing to assert:

```php
it('filters a virtual table', function () {
    bindRules('if is_understaffed they can assign', schemaKey: 'shift_days');

    $guard = Warrant::forSchema(ShiftDaySchema::class, $this->user);

    $rows = $guard->filterQuery($guard->query(), 'assign')->get();

    expect($rows)->toHaveCount(2);
});
```

## Conditions that should throw

The contract is enforced at compile time, and a test proves your condition honours
it:

```php
it('rejects a condition that joins', function () {
    bindRules('if joins_something they can view');

    Document::query()->userHasAbility('view', $this->user)->get();
})->throws(InvalidArgumentException::class, 'may only add where clauses');
```
