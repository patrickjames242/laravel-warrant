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

File, then New File, offers Warrant File, a new untitled file in the Warrant
language.

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
WARRANT)->validate()->ruleSet();
```

**Rule-text arguments in PHP**: the first argument of `warrant()`,
`Warrant::parse()`, `WarrantSyntax::parse()`, `WarrantParser::parse()`, and a
builder's raw methods, `ifRaw()` to `orIfNotRaw()`, as a quoted string or a heredoc of any label:

```php
warrant('is_owner or in_team(:team)', ['team' => $team]);
Build::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
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

- keywords: `if`, `when`, `otherwise`, `they`, `can`, `cannot`, `because`, `for`,
  `with`, `as`, `check`
- operators: `and`, `or`, `not`, `*`
- symbolic references: `@context`, `@column`, `@sql`, `@include`
- bindings: `:name`
- string literals, including denial messages
- numbers, `true`, `false`, `null`
- `#` line comments
- condition and ability names

## Typing

`(`, `{`, `'` and `"` close as you type them, and typing the closing one steps
over it. A quote typed inside a string or a comment is left as it is. Toggle
Line Comment comments lines out with `#`. All of this applies in `.warrant`
files and in the Warrant inside PHP.

In a `.warrant` file, Enter after a line ending in `{` or `(` indents the new
line one level, and a `}` or `)` typed at the start of a line moves back a
level, as the [formatter](/editors/formatting/) writes them. A level is four
spaces.

## The language server

The extension starts the [language server](/editors/language-server/) when it finds
one in the workspace: `vendor/bin/warrant-language-server` in a project with the
package installed. That gives diagnostics, hover, completion, go-to-definition,
quick fixes and the parameter names of the arguments of conditions and templates
in `.warrant` files and in rule text inside PHP, checked against your schemas,
and [renaming and finding usages](#renaming-and-finding-usages). It
needs PHP 8.2 or later on your `PATH`, which need not be the PHP your project
runs on.

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

## Renaming and finding usages

With the language server running, Find All References and Peek References find
where rule text uses a condition, template, ability or schema: `.warrant`
files, `WARRANT` heredocs, and the rule text in PHP strings. They start from any
of the places that name it:

- a condition or template method, `isOwner`, or a key given to its attribute,
  `#[RowCondition(key: 'in_folder')]`
- an ability constant, `EDIT`, or its value, `'edit'`
- a schema class, whose uses are every place rule text names it by its key:
  `for documents`, `check(... for documents(...))`, and `@column documents.id`
  where the frame is the schema's own rows
- a name in rule text

The server looks each name up as validation does, so an `is_owner` that belongs
to another schema is left alone. In PHP, the uses in rule text are listed with
the ones the PHP extension finds.

Above each condition or template method, ability constant and schema class, a
code lens counts its uses in rule text. Click it to list them.

**Renaming.** Rename Symbol (F2) on a name in rule text, or on a key given to an
attribute, renames the condition or template with every use. From rule text it
asks for the name rule text writes, `is_author`, and the method takes it in
camel case, `isAuthor`, as do the PHP calls to it that the PHP extension finds.
A method whose attribute gives a key keeps its name, and the key is renamed.

On a method's own name, F2 is the PHP extension's, and renames the PHP alone.
**Warrant: Rename Condition or Template**, in the command palette and the
editor's right-click menu, renames from there with the uses in rule text, and
from anywhere else F2 renames from.

A rename refuses a name rule text cannot write, such as `or` or one with a
space, a name that another condition or template of the same kind already has
on a schema that has this one, and, for a method, a name no method could have.

After a condition is renamed, the Refactor Preview offers the strings that may
name the old name but that nothing ties to a schema: the names given to
Warrant's builders, as in `->if('is_owner')` and `Build::check('is_owner')`.
Each is unticked; the ones you tick change with the rename.

An ability and a schema are not renamed with their uses, and nor is a context
key, which the PHP that gives a check its context names too.

**Frame names.** Rename and Find All References on a frame name, the `d` of
`check(... as d)` or of `@column d.id`, reach the `as` that gives it and each
`@column` that refers to that frame, and no other frame by the same name. A
frame named by its schema's key is the schema, and is not renamed. With the
cursor on a frame name or a context key, every place it is written in the file
is highlighted, and Find All References on a context key finds it across the
project's rule text.

Some uses are not found:

- Rule text the server cannot read as rule text: a variable passed to
  `warrant($rules)`, or a string built by joining or interpolating others.
- Names in rule text that does not parse, or that is about a schema
  `config/warrant.php` does not register.
- Rule text under `vendor/`, `node_modules/` or `storage/`.
- An ability whose value is not a plain string, such as
  `public const VIEW = StandardAbilities::VIEW`.
- Ability names in Blade, JavaScript and configuration.

## Formatting

With the language server running, Format Document formats a `.warrant` file in
the [house style](/editors/formatting/). In a PHP file, Source Action, then
Format Warrant rule text, formats the rule text and leaves the PHP as it is. To
do that on every save:

```json
"[php]": {
    "editor.codeActionsOnSave": { "source.format.warrant": "explicit" }
}
```

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
      "WARRANT)->validate()->ruleSet();"
    ]
  }
}
```
