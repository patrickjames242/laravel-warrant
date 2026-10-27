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

The package ships `vendor/bin/warrant-format`. It boots no application, so it
runs anywhere, CI included:

```bash
vendor/bin/warrant-format app resources/rules
```

```bash
vendor/bin/warrant-format --check .
```

Each path is a file or a directory, searched for `.warrant` and `.php` files
outside `vendor`, `node_modules` and hidden directories. With no path, it
formats the current directory.

`--check` writes nothing. It lists the files that would change and exits with
1 when there are any, which is what a CI step wants. Rule text that does not
parse is reported as `file:line: message`, and also makes the exit code 1.

`--stdin-filepath=<path>` formats the text on standard input as the file at
`<path>` would be, and writes it to standard output. That is the form editors
run as an external formatter.

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
closing marker. A quoted string is formatted on one line, and only when it holds
no line break and no comment. A heredoc that interpolates is left as it is.
