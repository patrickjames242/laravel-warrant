---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Context
description: What context is for, what belongs in it, and the five places it shows up.
sidebar:
  order: 1
  label: What context is for
---

Some values a rule needs are not in the database and are not known when the
provider runs. They are known at the moment of the check: the current tenant, the
academic year being viewed, an as-of date, the IP the request came from, the user
being impersonated.

Those are **context**. Context is the channel for facts that arrive with the
question rather than with the data.

```warrant
if in_workspace(@context workspace_id) they can view, edit
```

```php
Warrant::can('view', $document, ['workspace_id' => 'ws-1']);
```

## What belongs in it

A value belongs in context when it varies per check and is not a column.

Good: the current tenant or workspace, an as-of date for a historical view, a
period or year selected in the UI, a request attribute such as an IP or device
trust level.

Not context: anything stored on the row, which a condition reads directly;
anything derived from the user, which a condition reads from `$c->user`; anything
fixed for the deployment, which belongs in config.

## The five surfaces

Context shows up in five places, and people usually meet them one at a time and do
not realise they are the same thing.

1. [`@context` in a rule](/concepts/context/in-rules/) threads a value into a
   condition positionally.
2. [`$c->context` in a condition](/concepts/context/in-conditions/) hands every
   condition the whole bag, whether or not the rule mentioned a key.
3. [Supplying it](/concepts/context/supplying/) at the check, or from
   `defaultContext()` on the schema.
4. [Requiring it](/concepts/context/requiring/) with `#[RequiredContext]` or per
   ability, so an absent key fails loudly.
5. [Crossing a boundary](/concepts/context/across-boundaries/) with `with`, since a
   hop into another schema inherits nothing.

## No declaration is needed to use one

A rule may reference any `@context <key>`, and a condition may read
`$c->context['<key>']`, with nothing declared anywhere. Declaration is only ever
about making a key **required**.
