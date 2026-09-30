---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Zed
description: The tree-sitter extension, and the one thing it does that the others cannot.
sidebar:
  order: 4
---

Zed has no TextMate support. Every language it highlights is defined by a
tree-sitter grammar, a real generated parser, plus queries mapping the syntax tree
to theme colours.

So Warrant ships a second grammar for it, in `editors/tree-sitter-warrant/`, with
the extension around it in `editors/zed/`. Its highlight scopes mirror the TextMate
ones, so a rule looks the same here as in VS Code.

## Install

See `editors/zed/README.md` in the repository. The extension is publishable to
Zed's registry; until it is listed there, install it as a dev extension pointing at
`editors/zed`.

## What it covers

**`.warrant` files**, by extension.

**`WARRANT` heredocs in PHP**, automatically. Zed's PHP extension injects whatever
language a heredoc's closing label names, so no configuration is needed:

```php
$rules = Warrant::ruleSet(<<<'WARRANT'
    for documents {
        if is_mine they can view
    }
WARRANT);
```

## SQL inside `@sql`

The one thing Zed does that the TextMate editors cannot. A tree-sitter grammar can
address a string's body as a node, so the SQL inside an `@sql` reference is
highlighted as SQL, given a SQL extension installed:

```warrant
if matches(@sql "select pay_period_id from settings where active = 1 limit 1")
they can view
```

Small, and genuinely useful, because `@sql` is the one place in the language where
you are writing something the parser does not check. Anything that makes a mistake
in that string visible is worth having.

## Why there are two grammars

The language is written down twice, and a change to it means updating both. That is
a real cost, and `specs/language-server.md` in the repository is the plan for
stopping the multiplication from growing further: one server, backed by this
repository's own PHP parser, serving every editor that speaks LSP.

Zed speaks LSP natively, so its extension would gain a Rust shim and a
`[language_servers.warrant]` entry rather than a third grammar. See
[the roadmap](/roadmap/planned/).
