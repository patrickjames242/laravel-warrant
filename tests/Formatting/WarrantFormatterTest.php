<?php

use Warrant\DSL\Formatting\RuleTextFormattingException;
use Warrant\DSL\Formatting\WarrantFormatter;

/*
 * Each case is one rule of specs/formatter.md: the text as written, and the
 * text as the formatter writes it. Every expected text is also checked to come
 * out of the formatter unchanged.
 */

/**
 * $text as the formatter answers it: with a final line break, unless empty.
 */
function formatterCase(string $text): string
{
    return $text === '' ? '' : $text."\n";
}

dataset('formatting', [
    // E. Rules
    'a rule that fits stays on one line' => [
        "if   is_owner\n they can view,edit",
        'if is_owner they can view, edit',
    ],
    'two rules never share a line' => [
        'if is_self they can edit if is_owner they can view',
        "if is_self they can edit\nif is_owner they can view",
    ],
    'a rule with two clauses always breaks' => [
        'if is_manager they can approve they cannot delete',
        "if is_manager\nthey can approve\nthey cannot delete",
    ],
    'clauses keep the order they were written in' => [
        'if is_locked they cannot edit they can view',
        "if is_locked\nthey cannot edit\nthey can view",
    ],
    'a clause that does not fit goes to the next line' => [
        "if is_confidential and not has_clearance(@context clearance_level) they cannot view because 'You need clearance.'",
        "if is_confidential and not has_clearance(@context clearance_level)\nthey cannot view because 'You need clearance.'",
    ],

    'a rule with one plain condition stays on one line' => [
        "if not   is_locked they can view\nif is_thing('a') they can view\nif can edit they can comment",
        "if not is_locked they can view\nif is_thing('a') they can view\nif can edit they can comment",
    ],
    'a rule whose condition has and or or puts its clause on the next line' => [
        'if is_owner or is_editor they can view',
        "if is_owner or is_editor\nthey can view",
    ],
    'a rule whose condition crosses to another schema puts its clause on the next line' => [
        'if can(view for folders) they can view if check(is_open for pay_periods) they can submit',
        "if can(view for folders)\nthey can view\nif check(is_open for pay_periods)\nthey can submit",
    ],
    'a one-line block holding such a rule is expanded' => [
        'for some_schema { if a or b they can * }',
        "for some_schema {\n    if a or b\n    they can *\n}",
    ],

    // F. Conditions
    'a chain that does not fit breaks before each operator' => [
        'if is_author and check(is_open for pay_periods(@column pay_period_id)) and not is_locked_for_audit they can submit',
        <<<'WARRANT'
            if is_author
                and check(is_open for pay_periods(@column pay_period_id))
                and not is_locked_for_audit
            they can submit
            WARRANT,
    ],
    'an and inside an or is parenthesised' => [
        'if a or b and c they can view',
        "if a or (b and c)\nthey can view",
    ],
    'parentheses that change nothing are removed' => [
        'if ((is_self)) and (not is_locked) or (is_admin) they can view',
        "if (is_self and not is_locked) or is_admin\nthey can view",
    ],
    'a group of the same operator on the right keeps its parentheses' => [
        'if a or (b or c) they can view',
        "if a or (b or c)\nthey can view",
    ],
    'a broken group opens like a block' => [
        'if is_admin or (is_reviewer and check(is_open for pay_periods(@column pay_period_id)) and not is_conflicted(@context user_id)) they can edit',
        <<<'WARRANT'
            if is_admin
                or (
                    is_reviewer
                    and check(is_open for pay_periods(@column pay_period_id))
                    and not is_conflicted(@context user_id)
                )
            they can edit
            WARRANT,
    ],
    'the second of two operands follows a broken first' => [
        'if is_within_approval_window(@context submitted_at, @column pay_period_end, :grace_period) or is_admin they can approve',
        <<<'WARRANT'
            if is_within_approval_window(
                @context submitted_at,
                @column pay_period_end,
                :grace_period
            ) or is_admin
            they can approve
            WARRANT,
    ],
    'more operands after a broken first each take a line' => [
        'if is_within_approval_window(@context submitted_at, @column pay_period_end, :grace_period) or is_admin or is_owner they can approve',
        <<<'WARRANT'
            if is_within_approval_window(
                @context submitted_at,
                @column pay_period_end,
                :grace_period
            )
                or is_admin
                or is_owner
            they can approve
            WARRANT,
    ],

    // D. Written forms
    'can(x) is written can x' => [
        'if can(edit) and can(view for folders) they can comment',
        "if can edit and can(view for folders)\nthey can comment",
    ],
    'strings take single quotes unless double need fewer escapes' => [
        "if is_thing(\"a\", 'it\\'s') they can view",
        "if is_thing('a', \"it's\") they can view",
    ],
    'numbers and literals are written as in the source' => [
        'if is_thing(3.140, -1, true, null) they can view',
        'if is_thing(3.140, -1, true, null) they can view',
    ],
    'an empty argument list loses its parentheses' => [
        'if is_owner() they can view',
        'if is_owner they can view',
    ],
    'the inside of an @sql string is kept' => [
        'if is_after(@column submitted_at, @sql "NOW() -   INTERVAL \'14 days\'") they can approve',
        "if is_after(@column submitted_at, @sql \"NOW() -   INTERVAL '14 days'\")\nthey can approve",
    ],

    // G and H. Calls, can and check
    'arguments that do not fit go one to a line' => [
        "if is_within_approval_window(@context submitted_at, @column pay_period_end, @sql \"now() - interval '14 days'\", :grace_period, true) they can approve",
        <<<'WARRANT'
            if is_within_approval_window(
                @context submitted_at,
                @column pay_period_end,
                @sql "now() - interval '14 days'",
                :grace_period,
                true
            )
            they can approve
            WARRANT,
    ],
    'a can that does not fit puts its parts on lines of their own' => [
        'if can(create_timesheet for pay_periods(@context pay_period_id) with user_id = @context user_id) they can create',
        <<<'WARRANT'
            if can(
                create_timesheet
                for pay_periods(@context pay_period_id)
                with user_id = @context user_id
            )
            they can create
            WARRANT,
    ],
    'a with map that does not fit takes one entry per line' => [
        "if can(approve for pay_runs(@context pay_run_id) with as_of_date=@context pay_run_date, plan_id   = @context plan_id, tenant_id = @context tenant_id, cutoff = @sql \"now() - interval '3 days'\") they can approve",
        <<<'WARRANT'
            if can(
                approve
                for pay_runs(@context pay_run_id)
                with
                    as_of_date = @context pay_run_date,
                    plan_id = @context plan_id,
                    tenant_id = @context tenant_id,
                    cutoff = @sql "now() - interval '3 days'"
            )
            they can approve
            WARRANT,
    ],
    'a broken check predicate hangs past its first line' => [
        'if check(is_open or is_reopened_by(@context user_id) or is_extended_until(@context as_of_date) for pay_periods(@column pay_period_id)) they can submit',
        <<<'WARRANT'
            if check(
                is_open
                    or is_reopened_by(@context user_id)
                    or is_extended_until(@context as_of_date)
                for pay_periods(@column pay_period_id)
            )
            they can submit
            WARRANT,
    ],
    'handles and aliases are written tight' => [
        'if can( view for folders( @column parent_id )as parent ) they can view',
        "if can(view for folders(@column parent_id) as parent)\nthey can view",
    ],

    // I. Clauses and ability lists
    'a long ability list breaks with because on the last ability' => [
        "they cannot approve, reject, reopen, override_lock, edit_after_close because 'Closed pay periods are read-only.'",
        <<<'WARRANT'
            they cannot
                approve,
                reject,
                reopen,
                override_lock,
                edit_after_close because 'Closed pay periods are read-only.'
            WARRANT,
    ],
    'a long * except breaks what it leaves out, keeping * except on the clause line' => [
        "they cannot * except view_the_document, list_the_documents, export_to_accounting because 'Archived.'",
        <<<'WARRANT'
            they cannot * except
                view_the_document,
                list_the_documents,
                export_to_accounting because 'Archived.'
            WARRANT,
    ],
    'a * except that fits stays on one line' => [
        "they can *   except view,:more",
        'they can * except view, :more',
    ],
    'a long * except in a can they header breaks what it leaves out' => [
        "can they * except view_the_document, list_the_documents, export_to_accounting_system {
if is_admin they can
}",
        <<<'WARRANT'
            can they * except
                view_the_document,
                list_the_documents,
                export_to_accounting_system
            {
                if is_admin they can
            }
            WARRANT,
    ],
    'a long * except in an include breaks what it leaves out' => [
        '@include requires_approval(1, 2) for * except view_the_document, list_the_documents, export_to_accounting',
        <<<'WARRANT'
            @include requires_approval(1, 2) for * except
                view_the_document,
                list_the_documents,
                export_to_accounting
            WARRANT,
    ],
    'a comment inside * except breaks what it leaves out, and stays beside its ability' => [
        "they can * except view, # read-only
 update",
        <<<'WARRANT'
            they can * except
                view, # read-only
                update
            WARRANT,
    ],
    'a single ability never breaks, even past the width' => [
        "they cannot edit because 'Timesheets in a closed pay period are read-only, always.'",
        "they cannot edit because 'Timesheets in a closed pay period are read-only, always.'",
    ],

    // J. Blocks
    'a block written on one line that fits stays on one line' => [
        'when is_published { if is_member they can view }',
        'when is_published { if is_member they can view }',
    ],
    'a block written over lines is expanded' => [
        "when is_published {\nif is_member they can view }",
        "when is_published {\n    if is_member they can view\n}",
    ],
    'a block holding two rules is expanded' => [
        'can they view { if a they can if b they cannot }',
        "can they view {\n    if a they can\n    if b they cannot\n}",
    ],
    'a when with an otherwise holds two rules, so is expanded' => [
        'when is_published { if is_member they can view } otherwise { they cannot view }',
        <<<'WARRANT'
            when is_published {
                if is_member they can view
            } otherwise {
                they cannot view
            }
            WARRANT,
    ],
    'an empty body is {}' => [
        "when is_published {\n}",
        'when is_published {}',
    ],
    'a broken when header puts its brace on a line of its own' => [
        "when is_published and not is_archived and check(is_open for pay_periods(@column pay_period_id)) {\nif is_member they can view\n}",
        <<<'WARRANT'
            when is_published
                and not is_archived
                and check(is_open for pay_periods(@column pay_period_id))
            {
                if is_member they can view
            }
            WARRANT,
    ],
    'a long can they header lists its abilities one per line' => [
        "can they view, edit, delete, export_to_accounting, approve_on_behalf_of_department {\nif is_finance_admin they can\n}",
        <<<'WARRANT'
            can they
                view,
                edit,
                delete,
                export_to_accounting,
                approve_on_behalf_of_department
            {
                if is_finance_admin they can
            }
            WARRANT,
    ],

    // K. for headers
    'a schema condition goes on the line after its header' => [
        'for documents is_mine or is_public',
        "for documents\n    is_mine or is_public",
    ],
    'a schema condition alone loses its braces' => [
        'for documents { is_mine or is_public }',
        "for documents\n    is_mine or is_public",
    ],
    'a long schema condition hangs past its first line' => [
        'for documents is_mine or is_public or can(view for folders(@column folder_id)) or is_shared_with(@context user_id)',
        <<<'WARRANT'
            for documents
                is_mine
                    or is_public
                    or can(view for folders(@column folder_id))
                    or is_shared_with(@context user_id)
            WARRANT,
    ],
    'a schema condition beside other blocks is braced' => [
        'for a { if x they can view } for b { is_mine }',
        "for a { if x they can view }\n\nfor b {\n    is_mine\n}",
    ],
    'an unbraced rule set stays unbraced' => [
        "for documents\nif is_mine they can view",
        "for documents\nif is_mine they can view",
    ],
    'top-level blocks are one blank line apart' => [
        "for a {\nif x they can view\n}\n\n\n\nfor b {\nif y they can view\n}",
        "for a {\n    if x they can view\n}\n\nfor b {\n    if y they can view\n}",
    ],

    // L. Includes
    'an include that does not fit keeps for on its line, the abilities below' => [
        '@include requires_department_approval(@context department_id) for view, edit, approve',
        "@include requires_department_approval(@context department_id) for\n    view,\n    edit,\n    approve",
    ],
    'an include whose for list does not fit lists its abilities one per line' => [
        '@include require_payroll_module for something, something_else, something_more, blah, somasdflkj, asdlfkadf, asdflijk',
        <<<'WARRANT'
            @include require_payroll_module for
                something,
                something_else,
                something_more,
                blah,
                somasdflkj,
                asdlfkadf,
                asdflijk
            WARRANT,
    ],
    'an include whose arguments break has its for list follow the parenthesis' => [
        "@include requires_department_approval(@context department_id, @column submitted_by, @sql 'now()') for view, edit, approve",
        <<<'WARRANT'
            @include requires_department_approval(
                @context department_id,
                @column submitted_by,
                @sql 'now()'
            ) for view, edit, approve
            WARRANT,
    ],

    // M. Blank lines
    'runs of blank lines are squeezed to one' => [
        "if a they can view\n\n\n\nif b they can edit",
        "if a they can view\n\nif b they can edit",
    ],
    'no blank line just inside braces' => [
        "for documents {\n\n\nif a they can view\n\n}",
        "for documents {\n    if a they can view\n}",
    ],

    // N. Comments
    'a comment at the end of a line trails it' => [
        "if is_owner they can view      # owners always see it\nif b they can edit",
        "if is_owner they can view # owners always see it\nif b they can edit",
    ],
    'a comment after a comma trails the item before it' => [
        "they can view, export, # finance asked\narchive",
        "they can\n    view,\n    export, # finance asked\n    archive",
    ],
    'a comment inside a condition forces it to break' => [
        "if is_owner\n# editors only while the draft is open\nor (is_editor and is_draft) they can edit",
        <<<'WARRANT'
            if is_owner
                # editors only while the draft is open
                or (is_editor and is_draft)
            they can edit
            WARRANT,
    ],
    'a comment after a brace leads the body' => [
        "when is_published { # owners first\nif is_owner they can view\n}",
        "when is_published {\n    # owners first\n    if is_owner they can view\n}",
    ],
    'a comment before otherwise leads the otherwise body' => [
        "when is_published {\nif is_owner they can view\n}\n# unpublished\notherwise {\nif is_admin they can view\n}",
        <<<'WARRANT'
            when is_published {
                if is_owner they can view
            } otherwise {
                # unpublished
                if is_admin they can view
            }
            WARRANT,
    ],
    'comment text is kept and only re-indented' => [
        "for documents {\n\t\t#   ── banner ──\n\t#    if old_rule they can view\nif a they can view\n\t# the end\n}",
        "for documents {\n    #   ── banner ──\n    #    if old_rule they can view\n    if a they can view\n    # the end\n}",
    ],
    'a text of comments alone keeps them' => [
        "  # nothing here\n\n\n  # yet",
        "# nothing here\n\n# yet",
    ],
    'an empty text stays empty' => [
        "  \n\n ",
        '',
    ],
]);

it('formats rule text in the house style', function (string $written, string $expected) {
    $formatter = new WarrantFormatter;
    $expected = formatterCase($expected);

    expect($formatter->format($written))->toBe($expected)
        ->and($formatter->format($expected))->toBe($expected);
})->with('formatting');

it('formats text on one line for a quoted string', function () {
    $formatter = new WarrantFormatter;

    expect($formatter->formatOnOneLine('when is_published{if not is_draft they can view,edit}'))
        ->toBe('when is_published { if not is_draft they can view, edit }')
        ->and($formatter->formatOnOneLine('if is_self they can edit if is_manager they can approve they cannot delete'))
        ->toBe('if is_self they can edit if is_manager they can approve they cannot delete')
        ->and($formatter->formatOnOneLine('for documents is_mine or  is_public'))
        ->toBe('for documents is_mine or is_public');
});

it('refuses text with a syntax error, saying where it is', function () {
    expect(fn () => (new WarrantFormatter)->format("if is_owner\nthey can view,"))
        ->toThrow(RuleTextFormattingException::class, 'Line 2');
});

it('refuses comments on one line', function () {
    expect(fn () => (new WarrantFormatter)->formatOnOneLine('if a they can view # why'))
        ->toThrow(RuleTextFormattingException::class);
});

it('formats every sample in the docs and the syntax examples without changing what it says', function () {
    $root = dirname(__DIR__, 2);
    $samples = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/docs/content', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array($file->getExtension(), ['md', 'mdx'], true)) {
            preg_match_all('/```warrant\n(.*?)```/s', (string) file_get_contents($file->getPathname()), $blocks);
            array_push($samples, ...$blocks[1]);
        }
    }

    preg_match_all("/<<<'WARRANT'\n(.*?)\n\s*WARRANT/s", (string) file_get_contents($root.'/src/DSL/RuleSyntaxExamples.php'), $examples);
    array_push($samples, ...array_map(static fn (string $text): string => (string) preg_replace('/^ {12}/m', '', $text), $examples[1]));

    $formatter = new WarrantFormatter;
    $formatted = 0;

    foreach ($samples as $sample) {
        try {
            $once = $formatter->format($sample);
        } catch (RuleTextFormattingException $error) {
            // Samples that show a syntax error are left as they are, which is right.
            expect($error->diagnostics)->not->toBe([], $error->getMessage()."\n".$sample);

            continue;
        }

        expect($formatter->format($once))->toBe($once);
        $formatted++;
    }

    expect($formatted)->toBeGreaterThan(100);
});
