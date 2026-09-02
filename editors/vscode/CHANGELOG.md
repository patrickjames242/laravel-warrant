# Changelog

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
