<?php

namespace Warrant\LanguageServer\Fragments;

use PhpToken;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * Finds the Warrant rule text in a file: all of a `.warrant` file, and each PHP
 * string that holds rule text in a `.php` file.
 *
 * A PHP string literal, heredoc or nowdoc is rule text when it is
 *
 *  - a heredoc or nowdoc labelled `WARRANT`, wherever it stands;
 *  - the first argument of `warrant()`, of `Warrant::parse()`,
 *    `WarrantSyntax::parse()` or `WarrantParser::parse()`, of
 *    `new BoundSyntax()`, or of a builder's raw methods, `->ifRaw()` to
 *    `->orIfNotRaw()`, passed by position or by its parameter name;
 *  - returned, alone or inside a returned array, from a method marked
 *    `#[DerivedCondition]` or `#[RuleTemplate]`, or from `rules()` on a class
 *    that extends or implements another,
 *
 * which are the places the editor plugins highlight as Warrant. A double-quoted
 * string that interpolates is not read, and neither is a string built up from
 * parts.
 *
 * Which schema the text's headerless rules are about is, in order: the one a
 * `# warrant-schema: <key>` comment in the text names, then the schema whose
 * class the string is written in, when the index knows it. A `for` header in the
 * text names its own.
 */
final class FragmentExtractor
{
    /** The heredoc and nowdoc label that marks rule text wherever it stands. */
    private const LABEL = 'WARRANT';

    /** Functions whose first argument is rule text. */
    private const RULE_TEXT_FUNCTIONS = ['warrant'];

    /** Classes, by short name, whose static `parse()` takes rule text first. */
    private const PARSE_CLASSES = ['warrant', 'warrantsyntax', 'warrantparser'];

    /** Classes, by short name, whose constructor takes rule text first. */
    private const RULE_TEXT_CLASSES = ['boundsyntax'];

    /** Builder methods whose first argument is a condition expression. */
    private const RAW_METHODS = ['ifraw', 'andifraw', 'orifraw', 'ifnotraw', 'andifnotraw', 'orifnotraw'];

    /** The names the rule-text parameter is passed by. */
    private const RULE_TEXT_PARAMETERS = ['syntax', 'source', 'expression'];

    /** Attributes, by short name, on a method whose returned string is rule text. */
    private const RULE_TEXT_ATTRIBUTES = ['derivedcondition', 'ruletemplate'];

    /** @var list<PhpToken> */
    private array $tokens = [];

    /** @var array<int, int> Each token's index, to the index of the bracket it is directly inside. */
    private array $enclosing = [];

    /** @var array<int, array{class: ?string, classExtends: bool, method: ?string, attributes: list<string>}> */
    private array $scopes = [];

    /**
     * @param VocabularyIndex $index The schemas known, to name the schema of a
     *   string written in a schema class.
     */
    public function __construct(private readonly VocabularyIndex $index)
    {
    }

    /**
     * Every fragment of rule text in a file, in the order they are written.
     *
     * @param string $path The file's path, whose extension says what it holds.
     * @param string $text The file's contents.
     * @return list<Fragment>
     */
    public function extract(string $path, string $text): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'warrant') {
            return [Fragment::whole($text, self::markedSchema($text))];
        }

        if ($extension !== 'php') {
            return [];
        }

        return $this->fromPhp($text);
    }

    /**
     * The schema a `# warrant-schema: <key>` comment in $text names, or null.
     *
     * @param string $text Rule text.
     */
    public static function markedSchema(string $text): ?string
    {
        return preg_match('/^[ \t]*#[ \t]*warrant-schema:[ \t]*([A-Za-z_][A-Za-z0-9_-]*)/m', $text, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * @param string $text A PHP file's contents.
     * @return list<Fragment>
     */
    private function fromPhp(string $text): array
    {
        $this->tokens = PhpToken::tokenize($text);
        $this->index();

        $fragments = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $end = $i;
            } elseif ($token->is(T_START_HEREDOC)) {
                $end = $i + 1;

                while ($end < $count && ! $this->tokens[$end]->is(T_END_HEREDOC)) {
                    $end++;
                }
            } else {
                continue;
            }

            if ($this->isRuleText($i, $end)) {
                $fragment = $token->is(T_START_HEREDOC) ? $this->heredoc($i, $end) : $this->literal($token);
                $class = $this->scopes[$i]['class'] ?? null;

                $fragments[] = $fragment->about(
                    self::markedSchema($fragment->text) ?? ($class === null ? null : $this->index->keyOfClass($class)),
                );
            }

            $i = $end;
        }

        return $fragments;
    }

    /**
     * Note, for every token, the bracket it is directly inside and the class and
     * method it is written in.
     */
    private function index(): void
    {
        $this->enclosing = [];
        $this->scopes = [];

        $namespace = '';
        $brackets = [];
        $scopeStack = [['class' => null, 'classExtends' => false, 'method' => null, 'attributes' => []]];
        $pending = null;
        $attributes = [];

        foreach ($this->tokens as $i => $token) {
            $this->enclosing[$i] = $brackets === [] ? -1 : $brackets[array_key_last($brackets)][0];
            $this->scopes[$i] = $scopeStack[array_key_last($scopeStack)];

            if ($token->is(T_NAMESPACE)) {
                $name = $this->nextSignificant($i);
                $namespace = $name !== null && $this->tokens[$name]->is([T_STRING, T_NAME_QUALIFIED]) ? $this->tokens[$name]->text : '';
            } elseif ($token->is(T_ATTRIBUTE)) {
                preg_match_all('/(?:^|[\[,(])\s*\\\\?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $this->attributeText($i), $names);
                foreach ($names[1] as $name) {
                    $attributes[] = strtolower((string) substr((string) strrchr('\\'.$name, '\\'), 1));
                }
            } elseif ($token->is([T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE])) {
                $previous = $this->previousSignificant($i);
                $name = $this->nextSignificant($i);

                if (($previous === null || ! $this->tokens[$previous]->is([T_DOUBLE_COLON, T_NEW])) && $name !== null && $this->tokens[$name]->is(T_STRING)) {
                    $pending = ['kind' => 'class', 'name' => ltrim($namespace.'\\'.$this->tokens[$name]->text, '\\'), 'extends' => false];
                }
            } elseif ($token->is([T_EXTENDS, T_IMPLEMENTS]) && $pending !== null && $pending['kind'] === 'class') {
                $pending['extends'] = true;
            } elseif ($token->is([T_FUNCTION, T_FN])) {
                $name = $this->nextSignificant($i);
                $pending = [
                    'kind' => 'function',
                    'name' => $name !== null && $this->tokens[$name]->text !== '(' && $this->tokens[$name]->text !== '&' ? $this->tokens[$name]->text : null,
                    'attributes' => $attributes,
                ];
            } elseif ($token->text === ';' || $token->text === '}') {
                $attributes = [];
            }

            if (in_array($token->text, ['(', '['], true) || $token->is([T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $brackets[] = [$i, false];
            } elseif ($token->text === '{') {
                $opensScope = $pending !== null;
                $brackets[] = [$i, $opensScope];

                if ($opensScope) {
                    $outer = $scopeStack[array_key_last($scopeStack)];
                    $scopeStack[] = $pending['kind'] === 'class'
                        ? ['class' => $pending['name'], 'classExtends' => $pending['extends'], 'method' => null, 'attributes' => []]
                        : [
                            'class' => $outer['class'],
                            'classExtends' => $outer['classExtends'],
                            /* Only a method written directly in a class body answers
                               for the class; a closure answers for itself. */
                            'method' => $outer['method'] === null && $outer['class'] !== null ? $pending['name'] : null,
                            'attributes' => $pending['attributes'],
                        ];
                    $pending = null;
                    $attributes = [];
                }
            } elseif (in_array($token->text, [')', ']', '}'], true) && $brackets !== []) {
                [, $openedScope] = array_pop($brackets);

                if ($openedScope && count($scopeStack) > 1) {
                    array_pop($scopeStack);
                }
            } elseif ($token->text === ';' && $pending !== null && $pending['kind'] === 'function') {
                // An abstract or interface method has no body.
                $pending = null;
            }
        }
    }

    /**
     * Whether the string from token $start to token $end is rule text.
     *
     * @param int $start The index of its first token.
     * @param int $end The index of its last token.
     */
    private function isRuleText(int $start, int $end): bool
    {
        if ($this->tokens[$start]->is(T_START_HEREDOC)
            && preg_match('/^<<<[ \t]*["\']?([A-Za-z_][A-Za-z0-9_]*)/', $this->tokens[$start]->text, $match) === 1
            && $match[1] === self::LABEL
        ) {
            return true;
        }

        $after = $this->nextSignificant($end);
        $standsAlone = $after === null || in_array($this->tokens[$after]->text, [',', ')', ']', ';'], true);

        return $standsAlone && ($this->isRuleTextArgument($start) || $this->isReturnedRuleText($start));
    }

    /**
     * Whether the string starting at $start is passed as the rule text of a call
     * that takes it.
     *
     * @param int $start The index of the string's first token.
     */
    private function isRuleTextArgument(int $start): bool
    {
        $open = $this->enclosing[$start] ?? -1;

        if ($open < 0 || $this->tokens[$open]->text !== '(') {
            return false;
        }

        $before = $this->previousSignificant($start);

        if ($before === $open) {
            $passed = true;
        } elseif ($before !== null && $this->tokens[$before]->text === ':') {
            $name = $this->previousSignificant($before);
            $passed = $name !== null && in_array(strtolower($this->tokens[$name]->text), self::RULE_TEXT_PARAMETERS, true);
        } else {
            $passed = false;
        }

        $callee = $this->previousSignificant($open);

        if (! $passed || $callee === null || ! $this->tokens[$callee]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        $name = strtolower((string) substr((string) strrchr('\\'.$this->tokens[$callee]->text, '\\'), 1));
        $operator = $this->previousSignificant($callee);
        $operatorToken = $operator === null ? null : $this->tokens[$operator];

        if ($operatorToken !== null && $operatorToken->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return in_array($name, self::RAW_METHODS, true);
        }

        if ($operatorToken !== null && $operatorToken->is(T_DOUBLE_COLON)) {
            $class = $this->previousSignificant($operator);
            $className = $class === null ? '' : strtolower((string) substr((string) strrchr('\\'.$this->tokens[$class]->text, '\\'), 1));

            return $name === 'parse' && in_array($className, self::PARSE_CLASSES, true);
        }

        if ($operatorToken !== null && $operatorToken->is(T_NEW)) {
            return in_array($name, self::RULE_TEXT_CLASSES, true);
        }

        $isDeclaration = $operatorToken !== null && $operatorToken->is([T_FUNCTION, T_NEW]);

        return ! $isDeclaration && in_array($name, self::RULE_TEXT_FUNCTIONS, true);
    }

    /**
     * Whether the string starting at $start is returned, alone or inside a
     * returned array, from a method whose answer is rule text.
     *
     * @param int $start The index of the string's first token.
     */
    private function isReturnedRuleText(int $start): bool
    {
        $at = $start;

        while (($open = $this->enclosing[$at] ?? -1) >= 0) {
            $isArray = $this->tokens[$open]->text === '['
                || ($this->tokens[$open]->text === '(' && ($before = $this->previousSignificant($open)) !== null && $this->tokens[$before]->is(T_ARRAY));

            if (! $isArray) {
                break;
            }

            $at = $this->tokens[$open]->text === '(' ? (int) $this->previousSignificant($open) : $open;
        }

        $before = $this->previousSignificant($at);

        if ($before === null || ! $this->tokens[$before]->is(T_RETURN)) {
            return false;
        }

        $scope = $this->scopes[$start];

        if ($scope['method'] === null) {
            return false;
        }

        if (array_intersect($scope['attributes'], self::RULE_TEXT_ATTRIBUTES) !== []) {
            return true;
        }

        return strtolower($scope['method']) === 'rules' && $scope['classExtends'];
    }

    /**
     * The fragment a quoted string literal holds.
     *
     * @param PhpToken $token The literal.
     */
    private function literal(PhpToken $token): Fragment
    {
        $quote = $token->text[0];
        $escapes = $quote === "'" ? ['\\', "'"] : ['\\', '"', '$'];

        return $this->build(
            [[substr($token->text, 1, -1), $token->pos + 1, false]],
            $escapes,
            0,
            $token->pos + 1,
        );
    }

    /**
     * The fragment the heredoc or nowdoc from token $start to token $end holds:
     * its body, with the closing marker's indentation taken off each line and
     * the line break before the marker left out, as PHP reads it.
     *
     * @param int $start The index of its opening token.
     * @param int $end The index of its closing marker.
     */
    private function heredoc(int $start, int $end): Fragment
    {
        $opener = $this->tokens[$start];
        $isNowdoc = str_contains($opener->text, "'");
        $closing = $this->tokens[$end] ?? null;
        $indent = $closing === null ? 0 : strlen($closing->text) - strlen(ltrim($closing->text, " \t"));

        $pieces = [];

        for ($i = $start + 1; $i < $end; $i++) {
            $token = $this->tokens[$i];
            // Interpolated PHP is no part of the rule text; spaces keep the offsets after it.
            $pieces[] = [$token->text, $token->pos, ! $token->is(T_ENCAPSED_AND_WHITESPACE)];
        }

        $fragment = $this->build($pieces, $isNowdoc ? [] : ['\\', '$'], $indent, $opener->pos + strlen($opener->text));

        if (str_ends_with($fragment->text, "\n")) {
            $trim = str_ends_with($fragment->text, "\r\n") ? 2 : 1;
            $fragment = self::truncate($fragment, strlen($fragment->text) - $trim);
        }

        return $fragment;
    }

    /**
     * Build a fragment from $pieces of a file, reading the escapes $escapes
     * names and taking up to $indent spaces or tabs off the start of each line.
     *
     * @param list<array{0: string, 1: int, 2: bool}> $pieces Each piece of the
     *   string as written: its text, its offset in the file, and whether it is
     *   interpolated PHP to blank out.
     * @param list<string> $escapes The characters a backslash before them stands
     *   in for.
     * @param int $indent How much indentation each line loses.
     * @param int $fileStart The offset in the file where the contents start.
     */
    private function build(array $pieces, array $escapes, int $indent, int $fileStart): Fragment
    {
        $text = '';
        $runs = [];
        $lineStart = true;
        $stripped = 0;

        $append = static function (string $bytes, int $fileOffset) use (&$text, &$runs): void {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] + $runs[$last][2] === strlen($text) && $runs[$last][1] + $runs[$last][2] === $fileOffset) {
                $runs[$last][2] += strlen($bytes);
            } else {
                $runs[] = [strlen($text), $fileOffset, strlen($bytes)];
            }

            $text .= $bytes;
        };

        foreach ($pieces as [$piece, $offset, $blank]) {
            $length = strlen($piece);

            for ($i = 0; $i < $length; $i++) {
                $char = $piece[$i];

                if ($lineStart && $stripped < $indent && ($char === ' ' || $char === "\t")) {
                    $stripped++;

                    continue;
                }

                $lineStart = false;

                if ($blank) {
                    $append($char === "\n" ? "\n" : ' ', $offset + $i);
                } elseif ($char === '\\' && $i + 1 < $length && in_array($piece[$i + 1], $escapes, true)) {
                    $append($piece[$i + 1], $offset + $i + 1);
                    $i++;
                } else {
                    $append($char, $offset + $i);
                }

                if ($char === "\n") {
                    $lineStart = true;
                    $stripped = 0;
                    // A line with nothing on it still begins a run, so an offset on it maps back.
                    $runs[] = [strlen($text), $offset + $i + 1, 0];
                }
            }
        }

        if ($runs === []) {
            $runs[] = [0, $fileStart, 0];
        }

        $last = $runs[array_key_last($runs)];

        return new Fragment($text, $runs, null, $runs[0][1], $last[1] + $last[2]);
    }

    /**
     * $fragment with its text cut to $length bytes.
     *
     * @param Fragment $fragment The fragment.
     * @param int $length How many bytes of its text to keep.
     */
    private static function truncate(Fragment $fragment, int $length): Fragment
    {
        $runs = [];

        foreach ($fragment->runs as [$textStart, $fileStart, $runLength]) {
            if ($textStart > $length) {
                break;
            }

            $runs[] = [$textStart, $fileStart, min($runLength, $length - $textStart)];
        }

        $last = $runs[array_key_last($runs)];

        return new Fragment(substr($fragment->text, 0, $length), $runs, $fragment->schemaKey, $fragment->fileStart, $last[1] + $last[2]);
    }

    /**
     * The text of the attribute group opened at $i, up to its closing bracket.
     *
     * @param int $i The index of a `#[` token.
     */
    private function attributeText(int $i): string
    {
        $text = '';
        $depth = 0;

        for ($j = $i, $count = count($this->tokens); $j < $count; $j++) {
            $token = $this->tokens[$j];
            $text .= $token->text;
            $depth += match (true) {
                $token->is(T_ATTRIBUTE), $token->text === '[' => 1,
                $token->text === ']' => -1,
                default => 0,
            };

            if ($depth === 0) {
                break;
            }
        }

        return $text;
    }

    /**
     * The index of the nearest token before $i that is not whitespace or a
     * comment, or null.
     *
     * @param int $i A token's index.
     */
    private function previousSignificant(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * The index of the nearest token after $i that is not whitespace or a
     * comment, or null.
     *
     * @param int $i A token's index.
     */
    private function nextSignificant(int $i): ?int
    {
        for ($j = $i + 1, $count = count($this->tokens); $j < $count; $j++) {
            if (! $this->tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }
}
