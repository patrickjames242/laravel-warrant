---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: VS Code
description: Installing the extension, and what it gives you.
sidebar:
  order: 3
---

The extension bundles the canonical TextMate grammar plus a PHP injection grammar,
so rule text is highlighted in `.warrant` files and inside PHP with nothing to
configure.

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

**Rule-text arguments in PHP**: the first argument of `warrant()`,
`Warrant::parse()`, `WarrantSyntax::parse()`, `WarrantParser::parse()`, and a
builder's `ifRaw()` and `orIfRaw()`, as a quoted string or a heredoc of any label:

```php
warrant('is_owner or in_team(:team)', ['team' => $team]);
Warrant::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
```

**Returned rule text**: a string returned from a `#[DerivedCondition]` or
`#[RuleTemplate]` method, alone or inside a returned array, and a string returned
from `rules()`:

```php
#[RuleTemplate]
public function lockedOnceApproved(): string
{
    return "
        if is_approved
        they cannot
        because 'This pay run has been approved and can no longer be changed.'
    ";
}
```

A TextMate grammar reads the method's name and attributes but not the class it is
in, so an array returned from `rules()` stays PHP: that is also where a Laravel form
request returns its validation rules. [PhpStorm](/editors/phpstorm/) knows the
class, and highlights both.

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

## The language server

The extension starts the [language server](/editors/language-server/) when it finds
one in the workspace: `vendor/bin/warrant-language-server` in a project with the
package installed. That gives diagnostics, hover, completion, go-to-definition and
quick fixes in `.warrant` files and in rule text inside PHP, checked against your
schemas. It needs PHP 8.2 or later on your `PATH`, which need not be the PHP your
project runs on.

The server starts only in a trusted workspace, because it can boot your
application to read your schemas. Its settings:

| Setting | Default | What it does |
|---|---|---|
| `warrant.languageServer.enabled` | `true` | Whether to start the server. |
| `warrant.languageServer.phpPath` | `php` | The PHP executable that runs it. |
| `warrant.languageServer.path` | empty | The server script, when it is not in one of the usual places. |
| `warrant.vocabulary` | `auto` | How the server learns your schemas: `auto`, `static` or `off`. See [how it learns your schemas](/editors/language-server/#how-it-learns-your-schemas). |

**Warrant: Restart Language Server**, in the command palette, restarts it. Its
output is under **Warrant Language Server** in the Output panel.

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
