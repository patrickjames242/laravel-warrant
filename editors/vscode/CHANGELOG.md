# Changelog

## 0.13.0

- Indent the line Enter opens one level past a line ending in `{` or `(`, and
  move a typed `}` or `)` back to the line it closes, as the Warrant formatter
  writes them. Indent `.warrant` files with four spaces, as the formatter does.
- Offer **Warrant File** under File, then New File, for a new untitled file in
  the Warrant language.
- Format with the language server, for a Warrant release whose server formats:
  Format Document in a `.warrant` file, and the **Format Warrant rule text**
  source action in a PHP file.
- Highlight `when` and `otherwise` as keywords, for when blocks.
- Stop highlighting `?` as a binding, now that every binding is named (`:name`).

## 0.12.2

- Rename the extension to **Warrant for Laravel**.

## 0.12.1

- Make the extension's icon an inked rubber stamp: WARRANT across a round
  stamp, with "for Laravel" around its foot. The `.warrant` file icon stays as
  it is.

## 0.12.0

- Treat the Warrant inside PHP as Warrant while editing it: `(`, `{`, `'` and `"`
  close as they are typed, and Toggle Line Comment comments lines out with `#`.
- Leave a quote typed in a `#` comment as it is.
- Give the extension and `.warrant` files an icon: a rubber stamp's ring with a W.

## 0.11.0

- Highlight Warrant inside PHP in a string returned from a `#[RuleTemplate]` or
  `#[DerivedCondition]` method, alone or inside a returned array, and in a string
  returned from `rules()`.

## 0.10.0

- Start the Warrant language server, which brings diagnostics, hover, completion,
  go to definition, document symbols and quick fixes to `.warrant` files and to
  the Warrant inside PHP files. The server script is found in
  `vendor/bin/warrant-language-server` (or set with
  `warrant.languageServer.path`) and run with PHP.
- Add the settings `warrant.languageServer.enabled`,
  `warrant.languageServer.path`, `warrant.languageServer.phpPath` and
  `warrant.vocabulary`, the **Warrant: Restart Language Server** command, and the
  **Warrant Language Server** output channel.
- In an untrusted workspace, offer highlighting only and do not start the server.
- Require VS Code 1.82 or later.

## 0.9.0

- Highlight Warrant inside PHP wherever rule text is expected: the first argument
  of `warrant()`, `Warrant::parse()`, `WarrantSyntax::parse()`,
  `WarrantParser::parse()`, and a builder's `->ifRaw()` / `->orIfRaw()`. The
  argument may be a single- or double-quoted string or a heredoc/nowdoc of any
  label, passed first or by its parameter name.

## 0.8.0

- Follow the rule language's breaking change to the ability block header, which
  now reads `can they <abilities> { ... }` where it read a bare ability list:
  `can they view, edit { if is_public they can }`. The leading `can they` scopes
  as a keyword and the ability list after it as a name, and a header no longer
  has to start a line to be recognised.

## 0.7.0

- Highlight the header of an ability block — the ability list a `{ ... }` body
  takes its clauses' abilities from, as in `view, edit { if is_public they can }`
  — as a name of its own rather than as a condition call.
- Highlight `@include`, the directive that expands a rule template into the rules
  it stands for: `@include requires_approval for view, edit`. It scopes as a
  keyword rather than as a reference, because it stands where a rule stands and
  not where an argument does.

## 0.6.0

- Highlight a positional `?` as the binding it is, scoped like a `:name`
  binding, rather than as a generic operator. The two binding forms now look
  alike, and `?` no longer reads as punctuation.

## 0.5.0

- Highlight `@sql "<sql>"` raw SQL references — an arbitrary SQL fragment usable
  anywhere `@context` / `@column` are, spliced into the query at compile time.

## 0.4.0

- Highlight the `because` keyword, which introduces a denial message on a
  `they cannot ...` clause: `they cannot edit because 'This row is locked.'`.

## 0.3.0

- Sync the grammar with the now-implemented parser. Cross-schema
  `can(...)` / `check(...)` references, the `for` / `with` keywords, and the `=`
  context-map operator are no longer forward-looking — the `FUTURE:` tags are
  removed. Drop highlighting for constructs that were never implemented: `[ ]`
  ability lists and the `any` / `of` keywords. Removes the
  `FUTURE-FEATURES-cross-schema-references.md` planning doc.

## 0.2.0

- Revise the **forward-looking** cross-schema highlighting to the final proposed
  syntax: `can(<predicate> for <handle> [with <map>])` and
  `check(<condition> for <handle> [with <map>])`. Adds the `can`/`check`
  expression-position builtins, the `for` keyword, and namespace highlighting for
  the schema handle after `for`; drops the earlier `.` accessor form. **Still not
  implemented in the parser** — see
  `syntaxes/FUTURE-FEATURES-cross-schema-references.md`.

## 0.1.0

- Add **forward-looking** highlighting for the proposed cross-schema reference
  syntax: the `.` schema accessor (`some_other_schema.can(...)`), `[ ]` ability
  lists, the `any` / `of` and `with` keywords, and the `=` operator. **These DSL
  features are not yet implemented in the parser** — the highlighter is ahead of
  the language on purpose. See
  `syntaxes/FUTURE-FEATURES-cross-schema-references.md` for the design/plan and
  grep the grammar for `FUTURE:` to find the affected patterns.

## 0.0.3

- Fix heredoc highlighting inside `.php` files (inject into the `text.html.php`
  root grammar, not just `source.php`).

## 0.0.2

- Rename the extension to **Laravel Warrant**.

## 0.0.1

- Initial release.
- Syntax highlighting for standalone `.warrant` files.
- Injected highlighting inside PHP heredocs/nowdocs labelled `WARRANT`
  (e.g. `<<<'WARRANT' ... WARRANT`).
