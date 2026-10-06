---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Editor tooling
description: What exists, where it lives, and what each editor supports today.
sidebar:
  order: 1
---

Rules are a language, so they deserve highlighting. The grammar lives in the same
repository as the parser that defines the language, in `editors/`, so the two stay
in sync.

## What each editor has

| Editor | `.warrant` files | `WARRANT` heredocs | Rule-text arguments | Returned rule text | SQL inside `@sql` |
|---|---|---|---|---|---|
| [VS Code](/editors/vscode/) | yes | yes | yes | no | no |
| [PhpStorm](/editors/phpstorm/) | yes | yes | yes | yes | no |
| [Zed](/editors/zed/) | yes | yes | no | no | yes, with a SQL extension |
| Sublime Text | yes | no | no | no | no |

Highlighting is step one. Diagnostics, hover, and completion are
[on the roadmap](/roadmap/planned/).

## Two grammars

`editors/vscode/syntaxes/warrant.tmLanguage.json` is the canonical TextMate
grammar. VS Code and Sublime read it directly. PhpStorm has a small native plugin,
in `editors/phpstorm/`, whose lexer mirrors the same token rules.

Zed is the exception. It has no TextMate support: every language it highlights is
defined by a tree-sitter grammar, a real generated parser, plus queries mapping the
syntax tree to theme colours. That grammar lives in `editors/tree-sitter-warrant/`
and the extension around it in `editors/zed/`. Its highlight scopes deliberately
mirror the TextMate ones, so a rule looks the same in every editor.

The language is therefore written down twice, and a change to it means updating
both.

## What triggers highlighting

**A `.warrant` file**, by extension, everywhere:

```warrant
# warrant/editor.warrant

for documents {
    if is_mine or in_my_team they can view, update
    if is_locked they cannot update because 'This document is locked.'
}
```

**A heredoc labelled `WARRANT`**, inside PHP:

```php
$rules = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine they can view
    }
WARRANT)->ruleSet();
```

That works in VS Code, PhpStorm and Zed.

**A string where rule text is expected**, in VS Code and PhpStorm: the first
argument of `warrant()`, `Warrant::parse()` and the other parsing calls, with no
label needed:

```php
warrant('is_owner or in_team(:team)', ['team' => $team]);
```

PhpStorm also highlights rule text a schema method returns. See
[highlighting rules in PHP](/editors/heredocs/) for exactly what counts.

## Not shipped with the package

`editors/` is excluded from the published Composer package, through
`/editors export-ignore` in `.gitattributes`. It is developer tooling rather than
part of the runtime library, so `composer require` does not pull it in. Install the
extension from your editor's marketplace, or from the repository.

## Why highlighting is worth having

Rules are dense. In an unhighlighted string, `cannot` and `can` differ by three
characters, `because` and a condition name look identical, and a missing quote is
invisible until the parse fails.

With highlighting, the shape of a rule set is readable at a glance: keywords one
colour, condition names another, string messages a third. The categories the
grammar distinguishes are the ones the parser does, which is the argument for
keeping them in the same repository.
