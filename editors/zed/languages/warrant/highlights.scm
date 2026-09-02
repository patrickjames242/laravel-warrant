; Highlight queries for the Warrant rule DSL.
;
; Capture names are Zed's theme vocabulary; the scope *intent* mirrors
; editors/vscode/syntaxes/warrant.tmLanguage.json so that a rule looks the same
; in Zed as it does in VS Code and PhpStorm. Two places deliberately do better
; than the TextMate grammar can, because a real parse tree knows more than a
; regex: a positional `?` is highlighted as the binding it is rather than as a
; generic operator, and a `@context` key is a property rather than falling
; through to the bare-identifier rule.

; -- keywords ----------------------------------------------------------------

[
  "if"
  "they"
  "can"
  "cannot"
  "because"
  "for"
  "with"
  "and"
  "or"
  "not"
  "check"
] @keyword

; -- names -------------------------------------------------------------------

; A schema, both as a block header and as a cross-schema handle.
(schema_header
  schema: (identifier) @type)

(handle
  schema: (identifier) @type)

; Condition names, and the ability named inside `can(...)`.
(condition
  name: (identifier) @function)

(can_expression
  ability: (identifier) @function)

; Abilities granted or denied by a clause.
(ability
  (identifier) @function)

(ability
  (wildcard) @operator)

; Keys: `with` map entries, `@context` keys, and `@column` column names.
(with_entry
  key: (identifier) @property)

(context_ref
  key: (identifier) @property)

(column_ref
  schema: (identifier) @type
  column: (identifier) @property)

; -- bindings and references -------------------------------------------------

; The `@`-prefixed markers are language built-ins, not user-chosen names.
[
  "@context"
  "@column"
  "@sql"
] @variable.special

; Parse-time bindings: `:name` and `?`. A `?` is a positional binding, not an
; operator -- see specs/language-server.md.
(named_binding) @variable.special

(positional) @variable.special

; -- literals ----------------------------------------------------------------

(string) @string

(escape_sequence) @string.escape

(integer) @number

(float) @number

(boolean) @boolean

(null) @constant

(comment) @comment

; -- punctuation -------------------------------------------------------------

[
  "!"
  "="
] @operator

[
  ","
  "."
] @punctuation.delimiter

[
  "("
  ")"
  "{"
  "}"
] @punctuation.bracket
