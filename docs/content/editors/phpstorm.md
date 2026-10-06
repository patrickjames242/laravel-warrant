---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: PhpStorm
description: Installing the plugin, and where it highlights rule text.
sidebar:
  order: 3
---

PhpStorm has a native plugin, in `editors/phpstorm/` in the repository. It
registers Warrant as a language, highlights `.warrant` files, and injects the
language into the PHP strings that hold rule text, with nothing to configure.

See `editors/phpstorm/README.md` in the repository for the current details.

## Install

Build the plugin from a checkout:

```bash
cd editors/phpstorm
./gradlew buildPlugin
```

That produces `build/distributions/warrant-phpstorm-<version>.zip`. In PhpStorm,
open Settings, then Plugins, then the gear menu, then Install Plugin from Disk,
pick the zip, and restart the IDE.

## What it covers

**`.warrant` files**, by extension:

```warrant
for documents {
    if is_mine they can view, update
    if is_locked they cannot update because 'This document is locked.'
}
```

**`WARRANT` heredocs in PHP**, wherever they stand:

```php
$rules = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine they can view
    }
WARRANT)->ruleSet();
```

**Rule-text arguments**: the first argument of `warrant()`, `Warrant::parse()`,
`WarrantSyntax::parse()`, `WarrantParser::parse()`, and a builder's `ifRaw()` and
`orIfRaw()`, as a quoted string or a heredoc of any label, passed first or by
name:

```php
warrant('is_owner or in_team(:team)', ['team' => $team]);
Warrant::parse(syntax: 'for documents { if is_mine they can view }');
Warrant::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
```

**Returned rule text**: a string returned, alone or inside a returned array, from
`rules()` on a schema or a [rule provider](/supplying-rules/provider/), or from a
`#[DerivedCondition]` or `#[RuleTemplate]` method:

```php
#[DerivedCondition]
public function isEditable(): string
{
    return 'is_mine and not is_locked';
}
```

The plugin reads PHP's own syntax tree, so it knows which call an argument
belongs to and which method a `return` is in. That is why it covers returned
rule text, which the [VS Code](/editors/vscode/) grammar cannot.

## What you get

The same colouring as everywhere else: keywords, operators, symbolic references,
placeholders, string literals, comments, and names.

## What you do not

No diagnostics, no completion, no go-to-definition on a condition name. The
plugin's parser is deliberately flat: it exists to carry the highlighting and
knows nothing about your schemas.

A language server is [planned](/roadmap/planned/), and PhpStorm is one of the two
editors it is designed for.

## Until then

Two habits close most of the gap.

Keep the schema in the string's own header, so anything reading your source knows
which vocabulary applies:

```php
Warrant::parse('for documents { if is_mine they can view }')->ruleSet();
```

And run [`Warrant::validate()`](/supplying-rules/validation/) in a test over stored rules, so
a misspelled condition fails CI rather than a request.
