---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: PhpStorm
description: Installing the plugin, and where it highlights rule text.
sidebar:
  order: 4
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

File, then New, then Warrant File, also in the project view's New menu,
creates an empty `.warrant` file.

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
WARRANT)->validate()->ruleSet();
```

**Rule-text arguments**: the first argument of `warrant()`, `Warrant::parse()`,
`WarrantSyntax::parse()`, `WarrantParser::parse()`, and a builder's raw methods,
`ifRaw()` to `orIfNotRaw()`, as a quoted string or a heredoc of any label, passed first or by
name:

```php
warrant('is_owner or in_team(:team)', ['team' => $team]);
Warrant::parse(syntax: 'for documents { if is_mine they can view }');
Build::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
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
belongs to, which method a `return` is in, and which class that method is on.
That is why it highlights an array returned from `rules()` on a schema, which the
[VS Code](/editors/vscode/) grammar leaves as PHP.

## What you get

The same colouring as everywhere else: keywords, operators, symbolic references,
placeholders, string literals, comments, and names.

## Typing

`(`, `{`, `'` and `"` close as you type them, and typing the closing one steps
over it. A quote typed inside a string or a comment is left as it is. Comment
with Line Comment comments lines out with `#`, and the bracket matching the one
at the caret is highlighted.

Enter places the new line where the [formatter](/editors/formatting/) would:
one level past the line holding the innermost open `{` or `(`, and at a
condition's continuation indent after a line starting with `and` or `or`.
Enter between `{` and `}` puts the `}` on the line below, and a `}` or `)`
typed at the start of a line goes back to the line its bracket opened on. A
level is four spaces. All of this applies in `.warrant` files and in the
Warrant inside PHP.

## The language server

The plugin starts the [language server](/editors/language-server/) when it finds one
in the project: `vendor/bin/warrant-language-server` in a project with the package
installed. That gives diagnostics, hover, completion, go-to-definition and quick
fixes in `.warrant` files and in rule text inside PHP, checked against your
schemas.

It uses PhpStorm's own LSP support, which PhpStorm has from 2024.2. On an older
version the plugin still highlights, and the language server does not start.

The settings are under Settings, then Languages & Frameworks, then Warrant:

| Setting | Default | What it does |
|---|---|---|
| Enable language server | on | Whether to start the server. |
| PHP executable | `php` | The PHP that runs it. Left as `php`, the project's local PHP interpreter is used when `php` is not on the `PATH`. |
| Server path | empty | The server script, when it is not in one of the usual places. |
| Vocabulary | `auto` | How the server learns your schemas: `auto`, `static` or `off`. See [how it learns your schemas](/editors/language-server/#how-it-learns-your-schemas). |

Tools, then Restart Warrant Language Server, restarts it.

## Formatting

Code, then Reformat Code, formats Warrant in the [house style](/editors/formatting/):
all of a `.warrant` file, and the rule text in a PHP file, after PhpStorm has
formatted the PHP around it. Turn on Settings, then Tools, then Actions on Save,
then Reformat code, to have that happen on every save.

Code, then Reformat Warrant Rules, formats the Warrant in a file and nothing
else. It is also in the editor's right-click menu.

Formatting a PHP file runs the `warrant-format` script beside the language
server's, so it needs the language server to be found and enabled.
