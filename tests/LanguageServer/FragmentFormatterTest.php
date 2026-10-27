<?php

use Warrant\LanguageServer\Fragments\FragmentFormatter;

it('formats heredocs in full, quoted strings on one line, and leaves the PHP around them alone', function () {
    $file = <<<'PHP'
        <?php

        class DocumentSchema extends Schema
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

        class DocumentSchema extends Schema
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

    $formatter = new FragmentFormatter;

    expect($formatter->formatted('DocumentSchema.php', $file))->toBe($expected)
        ->and($formatter->formatted('DocumentSchema.php', $expected))->toBe($expected);
});

it('escapes a heredoc so PHP reads the formatted text back', function () {
    $file = "<?php\n\nreturn warrant(<<<WARRANT\n    if is_after(@sql \"a\\\\\\\\b \\\$x\") they can view\n    WARRANT);\n";
    $formatted = (new FragmentFormatter)->formatted('rules.php', $file);
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

    $formatter = new FragmentFormatter;

    expect($formatter->formatted('rules.php', $file))->toBe($file)
        ->and($formatter->problems())->toHaveCount(1)
        ->and($formatter->problems()[0]['line'])->toBe(4);
});

it('formats a whole .warrant file', function () {
    expect((new FragmentFormatter)->formatted('rules.warrant', "for documents{\n  if is_mine they can   view }"))
        ->toBe("for documents {\n    if is_mine they can view\n}\n");
});
