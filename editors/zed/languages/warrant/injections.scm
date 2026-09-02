; The body of an `@sql "..."` reference is real SQL, so hand it to the SQL
; grammar. `string_content` spans the whole body between the quotes -- escape
; sequences are its children, not siblings -- so the injected region is always
; one contiguous range.
;
; Zed does not bundle SQL; it comes from a community extension. With none
; installed this injection simply finds no language and the body stays coloured
; as an ordinary string, which is the pre-injection behaviour.
((sql_ref
  sql: (string
    (string_content) @injection.content))
  (#set! injection.language "sql"))
