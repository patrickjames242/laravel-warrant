---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: VS Code
description: Installing the extension, and what it gives you.
sidebar:
  order: 2
---

The extension bundles the canonical TextMate grammar plus a PHP heredoc injection,
so both places you write rules are highlighted with nothing to configure.

## Install

From the repository, if you are working from a checkout:

```bash
cd editors/vscode
npm install -g @vscode/vsce
vsce package
code --install-extension warrant-*.vsix
```

See `editors/vscode/README.md` in the repository for the current instructions,
including publishing to the Marketplace.

## What it covers

**`.warrant` files**, by extension:

```warrant
for documents {
    if is_mine or in_my_team they can view, update

    can they update {
        if is_locked they cannot because 'This document is locked.'
    }
}
```

**`WARRANT` heredocs in PHP**, through an injection grammar that matches the
closing label:

```php
$rules = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine they can view
    }
WARRANT)->ruleSet();
```

The nowdoc form with quoted `'WARRANT'` is the one to prefer, since rule text
should not be interpolating PHP variables. Values belong in
[bindings](/rules/values/).

## What gets coloured

The grammar distinguishes the categories the parser does:

- keywords: `if`, `they`, `can`, `cannot`, `because`, `for`, `with`, `as`, `check`
- operators: `and`, `or`, `not`, `!`, `*`
- symbolic references: `@context`, `@column`, `@sql`, `@include`
- placeholders: `:name` and `?`
- string literals, including denial messages
- numbers, `true`, `false`, `null`
- `#` line comments
- condition and ability names

## What it does not do yet

No diagnostics, no hover, no completion. The grammar is a lexer, so it cannot know
whether `is_mne` is a real condition on this schema. That needs a language server
with access to your schemas, which is [planned](/roadmap/planned/).

Until then, [`Warrant::validate()`](/supplying-rules/validation/) in a test is the check
that catches a misspelled name.

## A snippet worth adding

```json
{
  "Warrant rule set": {
    "prefix": "warrant",
    "body": [
      "Warrant::parse(<<<'WARRANT'",
      "    for ${1:documents} {",
      "        $0",
      "    }",
      "WARRANT)->ruleSet();"
    ]
  }
}
```
