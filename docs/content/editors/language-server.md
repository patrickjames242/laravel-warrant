---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Language server
description: Diagnostics, hover, completion and go-to-definition for rule text, in any editor that speaks LSP.
sidebar:
  order: 2
---

The package ships a language server, so an editor can check rule text against
your schemas as you type. It runs from your project's `vendor/bin`, and the
[VS Code](/editors/vscode/) extension and [PhpStorm](/editors/phpstorm/) plugin
start it for you.

## What it does

- **Diagnostics.** Every syntax error in rule text, and every rule of the language
  it breaks: an ability, condition, template or schema that is not declared, a
  condition given too few arguments, a `@column` naming a table that is not in
  scope. It reports them all at once, not just the first, each with a code such as
  `unknown-ability`.
- **Hover** over a condition, ability, template, schema or context key, to see
  what it is, its arguments, the context it requires, and its docblock.
- **Completion** of whatever fits where the cursor is: abilities after
  `they can`, conditions after `if`, schemas after `for`, templates after
  `@include`, context keys after `@context`.
- **Go to definition** from a name to the constant or method that declares it,
  and from a frame name in `@column d2.id` to the `as d2` that names it.
- **Quick fixes** for a name that is not declared, offering the closest declared
  names.
- **An outline** of the `for` blocks, ability blocks, rules and `@include`s in a
  file.

## Where it looks for rule text

In a `.warrant` file, the whole file.

In a PHP file, the same strings the editors highlight:

- a heredoc or nowdoc labelled `WARRANT`, wherever it stands;
- the first argument of `warrant()`, `Warrant::parse()`, `WarrantSyntax::parse()`,
  `WarrantParser::parse()`, and a builder's `ifRaw()` and `orIfRaw()`;
- a string returned from `rules()`, or from a `#[DerivedCondition]` or
  `#[RuleTemplate]` method, alone or inside a returned array.

Positions are given in the PHP file itself, so an error in a heredoc is
underlined where it is written. A double-quoted string that interpolates is not
read, and neither is a string built up with `.`.

## Which schema rule text is about

Rule text with a `for` header names its own schema:

```php
Warrant::parse('for documents { if is_mine they can view }');
```

Rule text with no header is checked against:

1. the schema a `# warrant-schema: <key>` comment in it names;
2. otherwise, the schema whose class the string is written in.

```warrant
# warrant-schema: documents
if is_mine they can view
```

Rule text with neither is still checked for syntax, and for the rules of the
language that need no schema. Names are not looked up, so nothing is reported
as unknown when the server cannot know which schema to ask.

## How it learns your schemas

There are two readings, and the server uses both.

**From source.** The server reads `config/warrant.php` for the registered schemas,
finds each class through Composer's autoload maps, and reads its attributes from
the file: `#[Ability]` constants, condition and template methods,
`#[RequiredContext]` constants, and what it inherits from parent classes and
traits. It runs none of your code, so it keeps working while your application is
broken mid-edit.

**From the running application.** The server also starts a short-lived child
process that boots your application and asks each schema for its vocabulary by
reflection, exactly as Warrant does at run time. This covers what source cannot:
an ability named by a computed constant, or the required context of an ability
attribute of your own. Its answer replaces the reading from source when it
arrives. If the application cannot boot, the reading from source stands.

Both readings run again whenever a PHP file changes.

The editor setting `vocabulary` chooses between them:

| Value | What the server does |
|---|---|
| `auto` | Reads source, and boots the application in a child process. The default. |
| `static` | Reads source only. Never runs your code. |
| `off` | Learns no schemas. Checks syntax, and the rules that need no schema. |

`auto` runs your project's code. VS Code only starts the server in a trusted
workspace.

## Running it from another editor

Any LSP client can start the server. The command is:

```bash
php vendor/bin/warrant-language-server
```

It speaks LSP over standard input and output, takes no arguments, and should be
started in the project's root. Send the `vocabulary` setting as
`initializationOptions`:

```json
{ "vocabulary": "auto" }
```

Send it both `.warrant` and `.php` documents. It finds the rule text in a PHP file
itself.

## What it does not do

- It does not complete column names after `@column`, because a column is part of
  your database, not your schema's vocabulary.
- It does not format rule text.
- It does not expand templates or derived conditions, so a mistake inside the rule
  text a template answers with is reported where the template is written, not
  where it is included. [`Warrant::validate()`](/supplying-rules/validation/) in a
  test catches those.
