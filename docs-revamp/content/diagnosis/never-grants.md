---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: My rule never grants
description: A checklist in the order the causes actually occur.
sidebar:
  order: 1
---

A check returns `false`, a list comes back empty, and nothing threw. Work down this
list. The causes are ordered by how often they turn out to be the answer.

## 0. Look at what is in effect

Before anything else:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

(new WarrantSyntax([Warrant::forSchema(Document::class, $user)->resolvedRuleSet()]))->toSyntax();
```

That is the merged, validated set actually in play, including the schema's implicit
rules. Most investigations end here, because either the rule you expected is not in
the output or something you forgot about is.

## 1. Nothing granted the ability

Silence denies. An ability nobody mentioned with a `can` is denied, full stop:

```warrant
if is_mine they can view, update
```

```php
Warrant::can('delete', $mine);   // false
```

Check:

```php
Warrant::possibleAbilities(Document::class, $user);
```

If the ability is missing from that list, no rule grants it and no row could have
helped.

## 2. An unconditional `cannot` somewhere

```php
Warrant::impossibleAbilities(Document::class, $user);
```

If the ability is in there, a `cannot` is winning and nothing can appeal it. Look
for it in the `toSyntax()` output, and check the schema's
[`implicitRules()`](/schemas/schema-policy/), which is the source people forget.

The usual culprits: a suspension lockout whose condition is true when you did not
expect, or a `they cannot *` inherited from a base schema.

## 3. A missing context key

The most common silent failure.

```warrant
if in_workspace(@context workspace_id) they can view
```

```php
Warrant::can('view', $document);   // false, always
```

No key was passed, and no `defaultContext()` supplied one, so the condition compared
a column against `null`. That is unknown, which grants nothing.

Check:

```php
DocumentSchema::requiredContextKeys();   // what is mandatory
Warrant::can('view', $document, ['workspace_id' => $id]);   // does it work with it?
```

If it works when you pass the key, the fix is a
[`defaultContext()`](/concepts/context/supplying/), a
[`#[RequiredContext]`](/concepts/context/requiring/), or both. Route middleware and
bare query scopes pass no context at all, so a default is usually the answer.

## 4. A null column

```warrant
if not is_locked they can view
```

A row whose `locked` is `NULL` makes the comparison unknown, `not unknown` is
unknown, and the row is dropped. So legacy rows with a null column silently vanish
from every list.

Check:

```sql
select count(*) from documents where locked is null;
```

Fix by making the column non-null with a default, or by handling it in the
condition:

```php
return $c->query->where(fn ($q) => $q
    ->whereNull($c->row('locked'))
    ->orWhere($c->row('locked'), false));
```

See [true, false, and unknown](/concepts/three-truth-values/).

## 5. A row condition in a no-row check

```php
Warrant::can('create', Document::class);
Warrant::abilities(Document::class);
```

Only global and unconditional rules can grant there. A row condition has no row, so
it is unanswerable.

Check which is which:

```php
DocumentSchema::rowConditionKeys();
DocumentSchema::globalConditionKeys();
```

If the granting rule's condition is a row condition, it can never grant a no-row
check. Either make the condition global, or grant unconditionally.

## 6. A `cannot` that cannot be evaluated

Subtle, and it looks exactly like the ability not being granted:

```warrant
they can view
if is_owner they cannot view
```

```php
Warrant::abilities(Document::class);   // []
```

`is_owner` cannot be evaluated with no row, so the denial cannot be evaluated, so
the ability cannot be claimed. An unknown deny is not a deny that failed to fire.

## 7. A stale memo

Changed a role or the rules in this request, and the check still answers the old
way:

```php
$user->roles()->attach($editorRole);

Warrant::flush($user);            // without this, the memo answers
Warrant::can('update', $document);
```

Very common in tests, since a test is one long-lived request. See
[memoization and flushing](/supplying-rules/memoization/).

Note that changing a *row* needs no flush. Locking a document changes what the
conditions match, not what the rules are.

## 8. The condition is not matching what you think

Look at the query:

```php
Document::query()->userHasAbility('view', $user)->dd();
```

Then run the predicate by hand against a row you expect to match. This is where you
find the wrong column name, the wrong comparison, or the team id list that came back
empty because a relation was not loaded.

## 9. The frame is wrong

If the SQL names a table the query does not have, or names the model's table inside
a subquery where the rows are aliased, the condition hard-coded its table. See
[the query looks wrong](/diagnosis/wrong-query/).

## 10. A hop is handing over nothing

```warrant
if can(view for folders(@column folder_id)) they can view
```

Two things to check. Is `folder_id` null on these rows, in which case the selector
matches no row. And does the folder schema require context that the hop did not
pass, since a boundary hands over an empty bag:

```warrant
if can(view for folders(@column folder_id) with tenant_id = @context tenant_id)
they can view
```

## A quick triage

| Symptom | Look at |
|---|---|
| every ability gone, for one user | an unconditional `cannot`, step 2 |
| one ability gone, for everyone | nothing grants it, step 1 |
| everything gone through middleware, fine elsewhere | missing context, step 3 |
| some rows missing, others fine | a null column, step 4 |
| `abilities()` empty but `can()` true | a row condition in a no-row check, step 5 |
| works in one test, fails in the next | a stale memo, step 7 |
