<?php

namespace Warrant\LanguageServer\Php;

use PhpToken;

/**
 * Reads the classes a PHP file declares from its tokens, without running it: each
 * class's parent, interfaces and traits, and its constants and methods with their
 * attributes.
 *
 * Only what is written as a literal is read. A constant whose value is computed,
 * or an attribute argument that is an expression, reads as null; a reference to
 * another class's constant reads as that reference, for the caller to follow.
 */
final class PhpSourceReader
{
    /** @var list<PhpToken> */
    private array $tokens;

    private int $count;

    private string $namespace = '';

    /** @var array<string, string> Alias, lower-cased, to the class it imports. */
    private array $imports = [];

    /**
     * @param string $file Absolute path of the file, recorded on what is read.
     * @param string $code The file's contents.
     */
    private function __construct(private readonly string $file, string $code)
    {
        $this->tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT]),
        ));
        $this->count = count($this->tokens);
    }

    /**
     * Every class, trait and interface $code declares.
     *
     * @param string $file Absolute path of the file.
     * @param string $code The file's contents.
     * @return list<PhpClass>
     */
    public static function read(string $file, string $code): array
    {
        return (new self($file, $code))->classes();
    }

    /**
     * The value a run of tokens is written as, when it is a literal: a string, a
     * list of strings, `true`, `false` or `null`. `Foo::class` reads as the class
     * name; `Foo::BAR` reads as `['class' => 'Foo', 'constant' => 'BAR']`, the
     * class resolved. Anything else reads as null.
     *
     * @param list<PhpToken> $tokens The value's tokens.
     */
    private function literal(array $tokens): mixed
    {
        if (count($tokens) === 1) {
            $token = $tokens[0];

            return match (true) {
                $token->is(T_CONSTANT_ENCAPSED_STRING) => self::unquote($token->text),
                $token->is(T_LNUMBER) => (int) $token->text,
                $token->is(T_STRING) && strtolower($token->text) === 'true' => true,
                $token->is(T_STRING) && strtolower($token->text) === 'false' => false,
                default => null,
            };
        }

        if (count($tokens) === 3 && $tokens[1]->is(T_DOUBLE_COLON) && self::isName($tokens[0])) {
            $class = $this->resolve($tokens[0]->text);

            return strtolower($tokens[2]->text) === 'class'
                ? $class
                : ['class' => $class, 'constant' => $tokens[2]->text];
        }

        $first = $tokens[0] ?? null;
        $isArray = $first !== null && ($first->text === '[' || $first->is(T_ARRAY));

        if (! $isArray) {
            return null;
        }

        $inner = $first->text === '[' ? array_slice($tokens, 1, -1) : array_slice($tokens, 2, -1);
        $items = [];

        foreach (self::splitOnCommas($inner) as $item) {
            $value = $this->literal($item);

            if ($value !== null) {
                $items[] = $value;
            }
        }

        return $items;
    }

    /**
     * The contents of a quoted PHP string, with the escapes a literal of its kind
     * reads.
     *
     * @param string $quoted The literal as written, quotes included.
     */
    public static function unquote(string $quoted): string
    {
        $body = substr($quoted, 1, -1);

        return $quoted[0] === "'"
            ? strtr($body, ['\\\\' => '\\', "\\'" => "'"])
            : stripcslashes($body);
    }

    /**
     * @return list<PhpClass>
     */
    private function classes(): array
    {
        $classes = [];

        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if ($token->is(T_NAMESPACE) && isset($this->tokens[$i + 1]) && self::isName($this->tokens[$i + 1])) {
                $this->namespace = ltrim($this->tokens[$i + 1]->text, '\\');
                $this->imports = [];
            } elseif ($token->is(T_USE) && $this->depthAt($i) === $this->namespaceDepth()) {
                $i = $this->readImports($i);
            } elseif ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && $this->declaresNamedClass($i)) {
                [$class, $i] = $this->readClass($i);
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Whether the class keyword at $i starts a named declaration, rather than
     * `Foo::class`, `new class`, or an enum used as a name.
     *
     * @param int $i The keyword's index.
     */
    private function declaresNamedClass(int $i): bool
    {
        $before = $this->tokens[$i - 1] ?? null;
        $after = $this->tokens[$i + 1] ?? null;

        return ! ($before !== null && ($before->is(T_DOUBLE_COLON) || $before->is(T_NEW)))
            && $after !== null
            && $after->is(T_STRING);
    }

    /**
     * Read a top-level `use` statement's imports, and return the index of its `;`.
     *
     * @param int $i The `use` keyword's index.
     */
    private function readImports(int $i): int
    {
        $i++;

        if (isset($this->tokens[$i]) && $this->tokens[$i]->is([T_FUNCTION, T_CONST])) {
            return $this->skipTo($i, ';');
        }

        $prefix = '';
        $name = null;
        $alias = null;

        for (; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if (self::isName($token) && $name === null) {
                $name = $prefix.ltrim($token->text, '\\');
            } elseif ($token->is(T_AS)) {
                $alias = $this->tokens[++$i]->text ?? null;
            } elseif ($token->text === '{') {
                $prefix = rtrim((string) $name, '\\').'\\';
                $name = null;
            } elseif ($token->text === ',' || $token->text === ';' || $token->text === '}') {
                if ($name !== null) {
                    $this->imports[strtolower($alias ?? self::shortName($name))] = $name;
                }

                $name = null;
                $alias = null;

                if ($token->text === ';') {
                    return $i;
                }
            }
        }

        return $i;
    }

    /**
     * Read the class declared at $i.
     *
     * @param int $i The class keyword's index.
     * @return array{0: PhpClass, 1: int} The class, and the index of its closing brace.
     */
    private function readClass(int $i): array
    {
        $nameToken = $this->tokens[$i + 1];
        $name = ltrim(($this->namespace === '' ? '' : $this->namespace.'\\').$nameToken->text, '\\');
        $docComment = $this->docCommentBefore($i);

        $parent = null;
        $interfaces = [];
        $i += 2;

        for (; $i < $this->count && $this->tokens[$i]->text !== '{'; $i++) {
            if ($this->tokens[$i]->is(T_EXTENDS) && isset($this->tokens[$i + 1])) {
                $parent = $this->resolve($this->tokens[++$i]->text);
            } elseif ($this->tokens[$i]->is(T_IMPLEMENTS)) {
                while (isset($this->tokens[$i + 1]) && self::isName($this->tokens[$i + 1])) {
                    $interfaces[] = $this->resolve($this->tokens[++$i]->text);

                    if (($this->tokens[$i + 1]->text ?? null) === ',') {
                        $i++;
                    }
                }
            }
        }

        $close = $this->matching($i);
        $traits = [];
        $constants = [];
        $methods = [];

        $attributes = [];
        $doc = null;
        $public = true;
        $static = false;

        for ($j = $i + 1; $j < $close; $j++) {
            $token = $this->tokens[$j];

            if ($token->is(T_DOC_COMMENT)) {
                $doc = $token->text;
            } elseif ($token->is(T_ATTRIBUTE)) {
                $end = $this->matching($j);
                array_push($attributes, ...$this->readAttributes(array_slice($this->tokens, $j + 1, $end - $j - 1)));
                $j = $end;
            } elseif ($token->is([T_PRIVATE, T_PROTECTED])) {
                $public = false;
            } elseif ($token->is(T_STATIC)) {
                $static = true;
            } elseif ($token->is(T_USE)) {
                for ($j++; $j < $close && ! in_array($this->tokens[$j]->text, [';', '{'], true); $j++) {
                    if (self::isName($this->tokens[$j])) {
                        $traits[] = $this->resolve($this->tokens[$j]->text);
                    }
                }

                if ($this->tokens[$j]->text === '{') {
                    $j = $this->matching($j);
                }
            } elseif ($token->is(T_CONST)) {
                $j = $this->readConstants($j, $close, $doc, $attributes, $public, $constants);
            } elseif ($token->is(T_FUNCTION)) {
                [$method, $j] = $this->readMethod($j, $doc, $attributes, $public, $static);

                if ($method !== null) {
                    $methods[$method->name] = $method;
                }
            } elseif ($token->is([T_VARIABLE, T_CASE])) {
                $j = $this->skipTo($j, ';', $close);
            } else {
                continue;
            }

            if (! $token->is([T_DOC_COMMENT, T_ATTRIBUTE, T_PRIVATE, T_PROTECTED, T_STATIC])) {
                $attributes = [];
                $doc = null;
                $public = true;
                $static = false;
            }
        }

        return [
            new PhpClass($name, $this->file, $nameToken->line, $docComment, $parent, $interfaces, array_values(array_unique($traits)), $constants, $methods),
            $close,
        ];
    }

    /**
     * Read the constants declared by the `const` statement at $i into $constants,
     * and return the index of its `;`.
     *
     * @param int $i The `const` keyword's index.
     * @param int $close The index of the class body's closing brace.
     * @param string|null $doc The docblock written before the statement.
     * @param list<PhpAttribute> $attributes The attributes written before it.
     * @param bool $public Whether the statement declares public constants.
     * @param array<string, PhpMember> $constants Where each constant is put, by name.
     */
    private function readConstants(int $i, int $close, ?string $doc, array $attributes, bool $public, array &$constants): int
    {
        $end = $this->skipTo($i, ';', $close);

        foreach (self::splitOnCommas(array_slice($this->tokens, $i + 1, $end - $i - 1)) as $declaration) {
            $equals = null;

            foreach ($declaration as $index => $token) {
                if ($token->text === '=') {
                    $equals = $index;
                    break;
                }
            }

            if ($equals === null || $equals === 0) {
                continue;
            }

            $nameToken = $declaration[$equals - 1];
            $constants[$nameToken->text] = new PhpMember(
                $nameToken->text,
                $nameToken->line,
                $doc,
                $attributes,
                $this->literal(array_slice($declaration, $equals + 1)),
                $public,
            );
        }

        return $end;
    }

    /**
     * Read the method declared at $i.
     *
     * @param int $i The `function` keyword's index.
     * @param string|null $doc The docblock written before it.
     * @param list<PhpAttribute> $attributes The attributes written before it.
     * @param bool $public Whether it is public.
     * @param bool $static Whether it is static.
     * @return array{0: ?PhpMember, 1: int} The method, and the index its
     *   declaration ends at.
     */
    private function readMethod(int $i, ?string $doc, array $attributes, bool $public, bool $static): array
    {
        $i++;

        if (($this->tokens[$i]->text ?? null) === '&') {
            $i++;
        }

        $nameToken = $this->tokens[$i] ?? null;

        if ($nameToken === null || $nameToken->text === '(') {
            return [null, $i];
        }

        $open = $i + 1;
        $closeParameters = $this->matching($open);
        $parameters = [];

        foreach (self::splitOnCommas(array_slice($this->tokens, $open + 1, $closeParameters - $open - 1)) as $parameter) {
            $read = $this->readParameter($parameter);

            if ($read !== null) {
                $parameters[] = $read;
            }
        }

        $end = $closeParameters + 1;

        while ($end < $this->count && ! in_array($this->tokens[$end]->text, ['{', ';'], true)) {
            $end++;
        }

        if (($this->tokens[$end]->text ?? null) === '{') {
            $end = $this->matching($end);
        }

        return [new PhpMember($nameToken->text, $nameToken->line, $doc, $attributes, null, $public, $static, $parameters), $end];
    }

    /**
     * @param list<PhpToken> $tokens One parameter's tokens.
     */
    private function readParameter(array $tokens): ?PhpParameter
    {
        $type = null;
        $name = null;
        $optional = false;
        $variadic = false;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_ATTRIBUTE)) {
                $depth = 0;

                for (; $i < $n; $i++) {
                    $depth += match ($tokens[$i]->text) {
                        '[', '#[' => 1,
                        ']' => -1,
                        default => 0,
                    };

                    if ($depth === 0) {
                        break;
                    }
                }
            } elseif ($token->is(T_VARIABLE)) {
                $name = substr($token->text, 1);
            } elseif ($token->is(T_ELLIPSIS) || $token->text === '=') {
                $optional = true;
                $variadic = $variadic || $token->is(T_ELLIPSIS);

                if ($token->text === '=') {
                    break;
                }
            } elseif ($name === null && self::isName($token) && ! $token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
                $type = $this->resolve($token->text);
            }
        }

        return $name === null ? null : new PhpParameter($name, $type, $optional, $variadic);
    }

    /**
     * The attributes in one `#[...]` group.
     *
     * @param list<PhpToken> $tokens The group's tokens, between `#[` and `]`.
     * @return list<PhpAttribute>
     */
    private function readAttributes(array $tokens): array
    {
        $attributes = [];

        foreach (self::splitOnCommas($tokens) as $attribute) {
            if ($attribute === [] || ! self::isName($attribute[0])) {
                continue;
            }

            $positional = [];
            $named = [];

            if (($attribute[1]->text ?? null) === '(') {
                foreach (self::splitOnCommas(array_slice($attribute, 2, -1)) as $argument) {
                    if (count($argument) > 2 && $argument[1]->text === ':' && $argument[0]->is(T_STRING)) {
                        $named[$argument[0]->text] = $this->literal(array_slice($argument, 2));
                    } else {
                        $positional[] = $this->literal($argument);
                    }
                }
            }

            $attributes[] = new PhpAttribute($this->resolve($attribute[0]->text), $positional, $named);
        }

        return $attributes;
    }

    /**
     * $name as a fully qualified class name, read against the file's namespace
     * and imports, without a leading backslash. `self`, `static` and `parent`
     * are returned as they are.
     *
     * @param string $name A class name as written.
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return strtolower($name);
        }

        $segments = explode('\\', $name, 2);
        $imported = $this->imports[strtolower($segments[0])] ?? null;

        if ($imported !== null) {
            return $imported.(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        return ltrim($this->namespace.'\\'.$name, '\\');
    }

    /**
     * The docblock directly before the declaration whose keyword is at $i,
     * stepping back over its modifiers and attributes.
     *
     * @param int $i The declaration keyword's index.
     */
    private function docCommentBefore(int $i): ?string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $this->tokens[$j];

            if ($token->is(T_DOC_COMMENT)) {
                return $token->text;
            }

            if ($token->text === ']') {
                $depth = 0;

                for (; $j >= 0; $j--) {
                    $depth += match (true) {
                        $this->tokens[$j]->text === ']' => 1,
                        $this->tokens[$j]->text === '[' || $this->tokens[$j]->is(T_ATTRIBUTE) => -1,
                        default => 0,
                    };

                    if ($depth === 0) {
                        break;
                    }
                }
            } elseif (! $token->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the bracket closing the one opened at $i, or the last index
     * when it is never closed.
     *
     * @param int $i The index of an opening `(`, `[`, `{` or `#[`, or of a `{`
     *   PHP reads as part of a longer token.
     */
    private function matching(int $i): int
    {
        $depth = 0;

        for ($j = $i; $j < $this->count; $j++) {
            $depth += self::depthChange($this->tokens[$j]);

            if ($depth === 0) {
                return $j;
            }
        }

        return $this->count - 1;
    }

    /**
     * The index of the first $text token at or after $i and before $limit, or
     * the index before $limit when there is none.
     *
     * @param int $i Where to start.
     * @param string $text The token to find, at the depth the search starts at.
     * @param int|null $limit The index the search stops at.
     */
    private function skipTo(int $i, string $text, ?int $limit = null): int
    {
        $limit ??= $this->count;
        $depth = 0;

        for ($j = $i; $j < $limit; $j++) {
            if ($depth === 0 && $this->tokens[$j]->text === $text) {
                return $j;
            }

            $depth += self::depthChange($this->tokens[$j]);

            if ($depth < 0) {
                return $j - 1;
            }
        }

        return $limit - 1;
    }

    /**
     * How deeply nested in brackets the token at $i is.
     *
     * @param int $i A token's index.
     */
    private function depthAt(int $i): int
    {
        $depth = 0;

        for ($j = 0; $j < $i; $j++) {
            $depth += self::depthChange($this->tokens[$j]);
        }

        return $depth;
    }

    /**
     * The depth a namespace's own statements are at: 1 inside a braced
     * `namespace Foo { … }`, 0 otherwise.
     */
    private function namespaceDepth(): int
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                return ($this->tokens[$index + 2]->text ?? null) === '{' ? 1 : 0;
            }
        }

        return 0;
    }

    /**
     * How $token changes how deeply nested in brackets what follows it is.
     *
     * @param PhpToken $token A token.
     */
    private static function depthChange(PhpToken $token): int
    {
        return match (true) {
            in_array($token->text, ['(', '[', '{'], true),
            $token->is([T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
            in_array($token->text, [')', ']', '}'], true) => -1,
            default => 0,
        };
    }

    /**
     * $tokens split at each comma not inside brackets.
     *
     * @param list<PhpToken> $tokens
     * @return list<list<PhpToken>>
     */
    private static function splitOnCommas(array $tokens): array
    {
        $parts = [[]];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($depth === 0 && $token->text === ',') {
                $parts[] = [];

                continue;
            }

            $depth += self::depthChange($token);
            $parts[array_key_last($parts)][] = $token;
        }

        return array_values(array_filter($parts, static fn (array $part): bool => $part !== []));
    }

    /**
     * @param PhpToken $token A token.
     */
    private static function isName(PhpToken $token): bool
    {
        return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE]);
    }

    /**
     * @param string $name A class name, qualified or not.
     */
    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
