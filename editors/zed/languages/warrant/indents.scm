; Lines inside a block's body, a parenthesised group, or the brackets of a
; call, a handle, `can(...)` or `check(...)` go one level past the line the
; bracket opens on, and the closing bracket goes back to that line, as the
; Warrant formatter writes them.
(block_body "}" @end) @indent
(parenthesized_expression ")" @end) @indent
(argument_list ")" @end) @indent
(handle ")" @end) @indent
(can_expression ")" @end) @indent
(check_expression ")" @end) @indent
