---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Formatting
description: One way to write every rule, from the command line or your editor.
sidebar:
  order: 8
---

Warrant has a formatter, as strict as Prettier: there is one way to write each
thing, and nothing to configure. It formats `.warrant` files, and the rule text
inside PHP files without touching the PHP around it.

It only lays text out. It never reorders rules, clauses or abilities, never
folds rules into blocks, and checks every result before using it: the formatted
text must say exactly what the original said, keep every comment, and come out
of a second run unchanged. Text with a syntax error is reported and left as it
is.

## From the command line

Two commands take the same arguments and options:

```bash
php artisan warrant:format app/Schemas 'resources/rules/**/*.warrant'
```

```bash
vendor/bin/warrant-format --check
```

`php artisan warrant:format` reads paths from the application's root.
`vendor/bin/warrant-format` reads them from the current directory and boots no
application, so it runs anywhere, CI and commit hooks included, and starts
faster.

Each path is one of these, read in this order:

| You pass | It formats |
|---|---|
| a file, such as `resources/rules/documents.warrant` | that file, `.warrant` or `.php` |
| a directory, such as `resources/rules` | its `.warrant` and `.php` files, at any depth |
| a glob pattern, such as `'app/**/*Schema.php'` | the files it matches |
| nothing | the whole project |

A path that exists is taken as written, so a quoted path with spaces, or a file
named `rules[1].warrant`, needs no escaping. Otherwise a path holding `*`, `?`,
`[...]` or `{a,b}` is a pattern: `*` and `?` match within a name, `**` any
number of directories, and `{a,b}` either alternative. Quote a pattern so the
command expands it, the same in every shell. Directories and patterns are never
searched in `vendor`, `node_modules`, `storage` or hidden directories.

| Option | What it does |
|---|---|
| `--check` | Writes nothing, and lists the files that would change. |
| `--diff` | Writes nothing, and shows what would change in each file. |
| `--ignore-unparsable` | Reports rule text that does not parse, but does not fail. |
| `--stdin-filepath=<path>` | Formats standard input as the file at `<path>` would be, and prints it. |

Rule text that does not parse is reported as `file:line: message` and left as
it is. The exit code is 0 when everything is formatted, 1 when `--check` or
`--diff` found a file to change, and 2 when rule text did not parse, unless
`--ignore-unparsable` is given, or a path matched nothing.

`--stdin-filepath` is the form editors run as an external formatter. The path
need not exist: its extension says whether the input is a `.warrant` file or a
PHP file.

### In a commit hook

With [lint-staged](https://github.com/lint-staged/lint-staged), the staged files
are formatted on every commit, and a commit holding rule text that does not
parse is stopped:

```json
"lint-staged": {
    "*.{warrant,php}": ["php vendor/bin/warrant-format"]
}
```

## In your editor

The [language server](/editors/language-server/) formats too.

- **VS Code**: Format Document formats a `.warrant` file. In a PHP file, run
  Source Action, then Format Warrant rule text, or format it on every save:

  ```json
  "[php]": {
      "editor.codeActionsOnSave": { "source.format.warrant": "explicit" }
  }
  ```

- **PhpStorm**: Reformat Code formats a `.warrant` file, and the rule text in a
  PHP file along with the PHP, so Actions on Save formats both. Code, then
  Reformat Warrant Rules, formats only the Warrant.
- **Zed**: has no language server for Warrant yet, so run the command line
  formatter as an external one, in `settings.json`:

  ```json
  "languages": {
      "Warrant": {
          "formatter": {
              "external": {
                  "command": "vendor/bin/warrant-format",
                  "arguments": ["--stdin-filepath={buffer_path}"]
              }
          }
      }
  }
  ```

The server registers formatting for `.warrant` files only, so it is never
offered as a formatter for PHP itself. In a PHP file it formats only the
strings it [reads as rule text](/editors/language-server/#where-it-looks-for-rule-text).

## The style

Lines are at most 80 characters wide, counted from where the rule text starts,
so a rule lays out the same in a `.warrant` file and in a heredoc at any depth.
Indentation is four spaces.

A rule that fits stays on one line when its condition is one plain condition.
Once the condition has an `and` or an `or`, or crosses to another schema with
`can(... for ...)` or `check(...)`, the clause goes on the next line however
short the rule is. Two rules never share a line, and a rule with more than one
clause puts each on a line of its own:

```warrant
if is_owner they can view, edit
if is_owner or is_editor
they can comment
if is_manager
they can approve
they cannot delete
```

A condition that does not fit breaks before each `and` or `or`, one level in. An
`and` inside an `or` is always parenthesised, so nobody has to remember which
binds tighter:

```warrant
if is_admin
    or (is_editor and not is_locked)
    or can(edit for projects(@column project_id))
they can edit
```

A call, `can(...)` or `check(...)` that does not fit puts each part on a line of
its own, and its `)` back under the line it opened on:

```warrant
if can(
    create_timesheet
    for pay_periods(@context pay_period_id)
    with user_id = @context user_id
)
they can create
```

A block you wrote on one line stays on one line while it fits and holds a single
rule. Any other block opens over lines, with `} otherwise {` kept together:

```warrant
when is_published { if is_member they can view }

when is_archived {
    they cannot edit because 'Archived documents are read-only.'
} otherwise {
    if is_owner they can edit
}
```

A schema condition goes on the line after its `for` header:

```warrant
for documents
    is_mine or is_public
```

`can(edit)` is written `can edit`, strings take single quotes unless double
quotes need fewer escapes, and the inside of a string, `@sql` included, is never
touched. Blank lines between rules are kept, a run of them squeezed to one.
Comments are kept where they are, re-indented.

In PHP, a `WARRANT` heredoc is formatted in full and indented one level past its
closing marker. A quoted string written over several lines is formatted in full
too, every line indented as its first line of rule text is. A quoted string on
one line is formatted on one line, and only when it holds no comment. A heredoc
that interpolates is left as it is.
