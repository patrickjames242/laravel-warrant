<?php

use Warrant\DSL\Formatting\FileFormatter;

it('formats heredocs in full, quoted strings on one line, and leaves the PHP around them alone', function () {
    $file = <<<'PHP'
        <?php

        use Warrant\Schema\Types\Classes\ModelSchema;

        class DocumentSchema extends ModelSchema
        {
            public function rules(): array
            {
                return [
                    <<<'WARRANT'
                        when is_published{if is_member they can view
                        if is_owner they can edit} otherwise { they cannot view because 'It\'s not published.' }
                        WARRANT,
                    'if is_self   they can edit,view',
                    "if is_x(\"a\") they can   view",
                ];
            }

            #[DerivedCondition]
            public function isPrivileged(): string
            {
                return 'is_admin   or is_owner';
            }
        }
        PHP;

    $expected = <<<'PHP'
        <?php

        use Warrant\Schema\Types\Classes\ModelSchema;

        class DocumentSchema extends ModelSchema
        {
            public function rules(): array
            {
                return [
                    <<<'WARRANT'
                            when is_published {
                                if is_member they can view
                                if is_owner they can edit
                            } otherwise {
                                they cannot view because "It's not published."
                            }
                        WARRANT,
                    'if is_self they can edit, view',
                    "if is_x('a') they can view",
                ];
            }

            #[DerivedCondition]
            public function isPrivileged(): string
            {
                return 'is_admin or is_owner';
            }
        }
        PHP;

    $formatter = new FileFormatter;

    expect($formatter->formatted('DocumentSchema.php', $file))->toBe($expected)
        ->and($formatter->formatted('DocumentSchema.php', $expected))->toBe($expected);
});

it('escapes a heredoc so PHP reads the formatted text back', function () {
    $file = "<?php\n\nreturn warrant(<<<WARRANT\n    if is_after(@sql \"a\\\\\\\\b \\\$x\") they can view\n    WARRANT);\n";
    $formatted = (new FileFormatter)->formatted('rules.php', $file);
    $heredoc = substr($formatted, strpos($formatted, '<<<'), strrpos($formatted, ')') - strpos($formatted, '<<<'));

    expect(ltrim(eval('return '.$heredoc.';')))->toBe("if is_after(@sql 'a\\\\b \$x') they can view");
});

it('leaves text that does not parse, a quoted string with a comment, and an interpolating heredoc as they are', function () {
    $file = <<<'PHP'
        <?php

        warrant('if is_self they can');
        warrant('if is_self they can view,');
        warrant("if is_self they can   view # why");
        warrant(<<<WARRANT
            if is_self they can   {$ability}
            WARRANT);
        PHP;

    $formatter = new FileFormatter;

    expect($formatter->formatted('rules.php', $file))->toBe($file)
        ->and($formatter->problems())->toHaveCount(1)
        ->and($formatter->problems()[0]['line'])->toBe(4);
});

it('formats a whole .warrant file', function () {
    expect((new FileFormatter)->formatted('rules.warrant', "for documents{\n  if is_mine they can   view }"))
        ->toBe("for documents {\n    if is_mine they can view\n}\n");
});

it('formats a quoted string written over several lines, keeping its own indentation', function () {
    $file = <<<'PHP'
        <?php

        use Warrant\Schema\Types\Classes\ModelSchema;

        class PayrollGroupSchema extends ModelSchema
        {
            public function rules(): string
            {
                return "
                    for payroll_groups

                    if can_view

                                    they can view_payroll
                    # the rest
                    if is_owner or is_admin they cannot   edit because 'It costs \$5.'
                ";
            }
        }
        PHP;

    $expected = <<<'PHP'
        <?php

        use Warrant\Schema\Types\Classes\ModelSchema;

        class PayrollGroupSchema extends ModelSchema
        {
            public function rules(): string
            {
                return "
                    for payroll_groups

                    if can_view they can view_payroll
                    # the rest
                    if is_owner or is_admin
                    they cannot edit because 'It costs \$5.'
                ";
            }
        }
        PHP;

    $formatter = new FileFormatter;

    expect($formatter->formatted('PayrollGroupSchema.php', $file))->toBe($expected)
        ->and($formatter->formatted('PayrollGroupSchema.php', $expected))->toBe($expected);
});

it('indents a quoted string that starts its rule text on the quote line one level past that line', function () {
    $file = "<?php\n\nclass S extends \\Warrant\\Schema\\Types\\Classes\\ModelSchema\n{\n    public function rules(): string\n    {\n        return 'for documents\n    if a or b they can view';\n    }\n}\n";

    expect((new FileFormatter)->formatted('S.php', $file))
        ->toBe("<?php\n\nclass S extends \\Warrant\\Schema\\Types\\Classes\\ModelSchema\n{\n    public function rules(): string\n    {\n        return 'for documents\n            if a or b\n            they can view';\n    }\n}\n");
});
