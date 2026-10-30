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
schemas, and [renaming and finding usages](#renaming-and-finding-usages).

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

## Renaming and finding usages

With the language server running, a condition, template or ability renames
together with every place rule text uses it: `.warrant` files, `WARRANT`
heredocs, and the rule text in PHP strings. Find Usages finds those places too.
The server looks each name up as validation does, so an `is_owner` that belongs
to another schema is left alone.

Start from whichever end is to hand:

| Start on | Rename does | Cmd/Ctrl-click and Find Usages |
|---|---|---|
| A condition or template method, `isOwner` | Renames the method, and each use to the new name in snake case, `is_author` | Lists the uses in rule text with the PHP ones |
| A key given to the attribute, `#[RowCondition(key: 'in_folder')]` | Renames the key and each use; the method keeps its name | Lists the uses |
| An ability constant's value, `public const EDIT = 'edit'` | Renames the value and each use; the constant keeps its name | Lists the uses |
| A name in rule text | Renames whichever of those three the name comes from | Goes to it |

A condition or template method, or an ability constant, shows as unused only
when no rule text names it. Warrant calls these by reflection, so no PHP calls
them; rule text naming one is what uses it. When the language server cannot
tell, because it is not running, does not know the schema, or the ability's
value is not a plain string, the member is shown as used.

Renaming a constant, `EDIT`, is PhpStorm's own rename: rule text never writes a
constant's name, so it is left as it is. Neither is a method whose attribute
gives a key, since the key, not the method, is the name.

The rename dialog refuses a name rule text cannot write, such as `or` or one
with a space, and a name that another condition, template or ability of the
same kind already has on a schema that has this one. A method's new name must
also be a PHP name.

After the rename, PhpStorm offers the strings that may name the old name but
that nothing ties to a schema: the names given to Warrant's builders, as in
`->if('is_owner')`, `->ifCan('edit')`, `Build::check('is_owner')` and
`Build::can('edit')`, and to Laravel's ability checks, as in
`$user->can('edit', $post)`, `$this->authorize('edit', $post)` and
`Gate::allows('edit', $post)`. Each is unticked; the ones you tick change, in a
step that undoes apart from the rename.

Some uses are not found:

- Rule text the server cannot read as rule text: a variable passed to
  `warrant($rules)`, or a string built by joining or interpolating others.
- Names in rule text that does not parse, or that is about a schema
  `config/warrant.php` does not register.
- Rule text under `vendor/`, `node_modules/` or `storage/`.
- An ability whose value is not a plain string, such as
  `public const VIEW = StandardAbilities::VIEW`. Cmd/Ctrl-click on its name in
  rule text goes to the constant, which renames as a constant.
- Ability names in Blade, JavaScript and configuration.

When the language server is not running, PhpStorm says so once, and renames the
PHP alone.

## Formatting

Code, then Reformat Code, formats Warrant in the [house style](/editors/formatting/):
all of a `.warrant` file, and the rule text in a PHP file, after PhpStorm has
formatted the PHP around it. Turn on Settings, then Tools, then Actions on Save,
then Reformat code, to have that happen on every save.

Code, then Reformat Warrant Rules, formats the Warrant in a file and nothing
else. It is also in the editor's right-click menu.

Formatting a PHP file runs the `warrant-format` script beside the language
server's, so it needs the language server to be found and enabled.
