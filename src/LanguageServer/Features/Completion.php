<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Workspace;

/**
 * What the editor offers to type at the cursor in rule text.
 *
 * The text around the cursor is almost never whole while it is being typed, so
 * what belongs there is read from the tokens just before it: after `they can`
 * an ability, after `if` a condition, after `@include` a template. The tree
 * says which schema is in scope, and so whose names to offer.
 */
final class Completion
{
    private const KIND_FUNCTION = 3;
    private const KIND_VARIABLE = 6;
    private const KIND_CLASS = 7;
    private const KIND_MODULE = 9;
    private const KIND_PROPERTY = 10;
    private const KIND_KEYWORD = 14;
    private const KIND_CONSTANT = 21;

    /** @var list<Token> The tokens before the cursor, or before the name it is in. */
    private array $before = [];

    /** @var list<Token> The tokens after the cursor. */
    private array $after = [];

    /**
     * @param Workspace $workspace What the server knows.
     * @param string|null $scopeSchemaKey The schema the text at the cursor is
     *   about, when known.
     * @param list<string> $frames The frame names a `@column` at the cursor may give.
     * @param ISchemaId|null $writtenIn The schema the text at the cursor was
     *   written in, which its conditions and templates are looked up from; null
     *   when it is not known.
     */
    private function __construct(
        private readonly Workspace $workspace,
        private readonly ?string $scopeSchemaKey,
        private readonly array $frames,
        private readonly ?ISchemaId $writtenIn,
    ) {
    }

    /**
     * The completion items for $position of $document; empty outside rule text,
     * and inside a comment or a string.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     * @return list<array<string, mixed>> LSP `CompletionItem` objects.
     */
    public static function at(Workspace $workspace, Document $document, array $position): array
    {
        $located = Located::at($workspace, $document, $position);

        if ($located === null) {
            return [];
        }

        $offset = $located->offset;
        $tokens = $located->fragment->analysis->tokens;

        foreach ($tokens as $token) {
            $inside = $token->offset < $offset && ($offset < $token->endOffset() || $token->type === TokenType::COMMENT && $offset === $token->endOffset());

            if ($inside && in_array($token->type, [TokenType::COMMENT, TokenType::STRING], true)) {
                return [];
            }
        }

        $significant = array_values(array_filter(
            $tokens,
            static fn (Token $token): bool => ! in_array($token->type, [TokenType::COMMENT, TokenType::ERROR, TokenType::EOF], true),
        ));

        $prefixStart = $offset;

        // A partly typed `@include` lexes as an error token, so the name being
        // typed is looked for among every token, and may start with its `@`.
        foreach ($tokens as $token) {
            if ($token->offset < $offset && $offset <= $token->endOffset() && preg_match('/^(@|@?[A-Za-z_][A-Za-z0-9_-]*)$/', $token->lexeme) === 1) {
                $prefixStart = $token->offset;
            }
        }

        $scope = $located->context->scope;
        $scopeSchemaKey = $scope->schemaKey ?? $located->fragment->fragment->schemaKey;
        $completion = new self(
            $workspace,
            $scopeSchemaKey,
            array_keys($scope->frames),
            $located->writtenIn($workspace->vocabulary(), $scopeSchemaKey),
        );
        $completion->before = array_values(array_filter($significant, static fn (Token $token): bool => $token->endOffset() <= $prefixStart));
        $completion->after = array_values(array_filter($significant, static fn (Token $token): bool => $token->offset >= $offset));

        $range = $document->rangeOf(
            $located->fragment->fragment->fileOffsetOf($prefixStart),
            $located->fragment->fragment->fileOffsetOf($offset, isEnd: true),
        );

        return array_map(
            static fn (array $item): array => [
                'label' => $item[0],
                'kind' => $item[1],
                'detail' => $item[2],
                'textEdit' => ['range' => $range, 'newText' => $item[3] ?? $item[0]],
            ],
            $completion->items(),
        );
    }

    /**
     * What belongs at the cursor, each as its label, kind, detail and the text
     * it inserts when that is not the label.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function items(): array
    {
        $p1 = $this->back(1);
        $p2 = $this->back(2);

        return match (true) {
            $p1 === TokenType::CONTEXT_REF => $this->contextKeys($this->scopeSchemaKey),
            $p1 === TokenType::INCLUDE_REF => $this->templates(),
            $p1 === TokenType::COLUMN_REF => $this->frames(),
            $p1 === TokenType::AS, $p1 === TokenType::DOT, $p1 === TokenType::BECAUSE, $p1 === TokenType::OTHERWISE => [],
            $p1 === TokenType::FOR => $this->afterFor(),
            $p1 === TokenType::WITH => $this->contextKeys($this->handleSchema()),
            $p1 === TokenType::LPAREN && $p2 === TokenType::CAN,
            $p1 === TokenType::CAN && in_array($p2, [TokenType::IF, TokenType::WHEN, TokenType::AND, TokenType::OR, TokenType::NOT, TokenType::LPAREN], true) => $this->abilities($this->namedSchema() ?? $this->scopeSchemaKey),
            $p1 === TokenType::LPAREN && $p2 === TokenType::CHECK => $this->expressionStart(),
            $p1 === TokenType::LPAREN && $p2 === TokenType::IDENTIFIER,
            $p1 === TokenType::EQUALS => $this->argumentStarts(),
            in_array($p1, [TokenType::CAN, TokenType::CANNOT], true) && $p2 === TokenType::THEY,
            $p1 === TokenType::THEY && $p2 === TokenType::CAN => $this->abilities($this->scopeSchemaKey, withWildcard: true),
            $p1 === TokenType::THEY => [['can', self::KIND_KEYWORD, 'grant abilities'], ['cannot', self::KIND_KEYWORD, 'deny abilities']],
            $p1 === TokenType::COMMA => $this->afterComma(),
            in_array($p1, [TokenType::IF, TokenType::WHEN, TokenType::AND, TokenType::OR, TokenType::NOT, TokenType::LPAREN], true) => $this->expressionStart(),
            $p1 === TokenType::RBRACE => [
                ...($this->closesWhenBody() ? [['otherwise', self::KIND_KEYWORD, 'the rules for when the condition is false', 'otherwise ']] : []),
                ...$this->entryStart(topLevel: $this->depth(TokenType::LBRACE, TokenType::RBRACE) === 0),
            ],
            $p1 === null, $p1 === TokenType::LBRACE => $this->entryStart(topLevel: $p1 === null),
            default => $this->afterOperand(),
        };
    }

    /**
     * The type of the token $distance places before the cursor, or null.
     *
     * @param int $distance 1 for the nearest.
     */
    private function back(int $distance): ?TokenType
    {
        return $this->before[count($this->before) - $distance]->type ?? null;
    }

    /**
     * How many $open tokens before the cursor have no $close after them.
     *
     * @param TokenType $open The opening bracket.
     * @param TokenType $close The closing bracket.
     */
    private function depth(TokenType $open, TokenType $close): int
    {
        $depth = 0;

        foreach ($this->before as $token) {
            $depth += match ($token->type) {
                $open => 1,
                $close => -1,
                default => 0,
            };
            $depth = max(0, $depth);
        }

        return $depth;
    }

    /**
     * Whether the `}` just before the cursor closes the body of a when block,
     * which an `otherwise` may follow: whether what heads the `{` it closes,
     * outside any parentheses, starts with `when`.
     */
    private function closesWhenBody(): bool
    {
        $depth = 0;
        $i = count($this->before) - 1;

        // The `{` the `}` closes.
        for (; $i >= 0; $i--) {
            $type = $this->before[$i]->type;

            if ($type === TokenType::RBRACE) {
                $depth++;
            } elseif ($type === TokenType::LBRACE && --$depth === 0) {
                break;
            }
        }

        $parentheses = 0;

        for ($i--; $i >= 0; $i--) {
            $type = $this->before[$i]->type;
            $parentheses += match ($type) {
                TokenType::RPAREN => 1,
                TokenType::LPAREN => -1,
                default => 0,
            };

            if ($parentheses === 0 && in_array($type, [TokenType::WHEN, TokenType::OTHERWISE, TokenType::IF, TokenType::THEY, TokenType::FOR, TokenType::LBRACE, TokenType::RBRACE, TokenType::INCLUDE_REF], true)) {
                return $type === TokenType::WHEN;
            }
        }

        return false;
    }

    /**
     * The index in {@see $before} of the innermost `(` the cursor is inside, or null.
     */
    private function openParenthesis(): ?int
    {
        $depth = 0;

        for ($i = count($this->before) - 1; $i >= 0; $i--) {
            $type = $this->before[$i]->type;

            if ($type === TokenType::RPAREN) {
                $depth++;
            } elseif ($type === TokenType::LPAREN && $depth-- === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * After `for`: a schema in a `for` header or a handle, an ability in an
     * `@include`'s list.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function afterFor(): array
    {
        if ($this->openParenthesis() === null && $this->entryHas(TokenType::INCLUDE_REF)) {
            return $this->abilities($this->scopeSchemaKey, withWildcard: true);
        }

        return $this->schemas();
    }

    /**
     * After a comma: another ability when it is in an ability list, another
     * argument when it is in an argument list.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function afterComma(): array
    {
        $i = count($this->before) - 1;

        while ($i >= 0 && in_array($this->before[$i]->type, [TokenType::COMMA, TokenType::IDENTIFIER, TokenType::STAR], true)) {
            $i--;
        }

        $head = $this->before[$i]->type ?? null;
        $beforeHead = $this->before[$i - 1]->type ?? null;

        $inAbilityList = (in_array($head, [TokenType::CAN, TokenType::CANNOT], true) && $beforeHead === TokenType::THEY)
            || ($head === TokenType::THEY && $beforeHead === TokenType::CAN)
            || ($head === TokenType::FOR && $this->openParenthesis() === null);

        if ($inAbilityList) {
            return $this->abilities($this->scopeSchemaKey, withWildcard: true);
        }

        return $this->openParenthesis() === null ? [] : $this->argumentStarts();
    }

    /**
     * After a whole operand, or a whole can/cannot clause.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function afterOperand(): array
    {
        $open = $this->openParenthesis();

        if ($open !== null) {
            $inCheck = ($this->before[$open - 1]->type ?? null) === TokenType::CHECK;

            return [
                ['and', self::KIND_KEYWORD, 'both'],
                ['or', self::KIND_KEYWORD, 'either'],
                ...($inCheck ? [['for', self::KIND_KEYWORD, 'the schema the predicate asks']] : []),
            ];
        }

        $last = null;

        foreach ($this->before as $token) {
            if (in_array($token->type, [TokenType::IF, TokenType::WHEN, TokenType::THEY, TokenType::LBRACE, TokenType::RBRACE, TokenType::INCLUDE_REF], true)) {
                $last = $token->type;
            }
        }

        if ($last === TokenType::WHEN) {
            return [
                ['and', self::KIND_KEYWORD, 'both'],
                ['or', self::KIND_KEYWORD, 'either'],
            ];
        }

        if ($last === TokenType::IF) {
            return [
                ['and', self::KIND_KEYWORD, 'both'],
                ['or', self::KIND_KEYWORD, 'either'],
                ['they', self::KIND_KEYWORD, 'the can/cannot clauses this condition grants or denies'],
            ];
        }

        return [
            ...($last === TokenType::THEY && $this->entryHas(TokenType::CANNOT) && ! $this->entryHas(TokenType::BECAUSE)
                ? [['because', self::KIND_KEYWORD, 'a denial message']]
                : []),
            ...$this->entryStart(topLevel: $this->depth(TokenType::LBRACE, TokenType::RBRACE) === 0),
        ];
    }

    /**
     * Whether a $type token is in the entry the cursor is in, since its start.
     *
     * @param TokenType $type The token to look for.
     */
    private function entryHas(TokenType $type): bool
    {
        for ($i = count($this->before) - 1; $i >= 0; $i--) {
            $found = $this->before[$i]->type;

            if ($found === $type) {
                return true;
            }

            if (in_array($found, [TokenType::IF, TokenType::WHEN, TokenType::LBRACE, TokenType::RBRACE], true)
                || ($found === TokenType::THEY && $type !== TokenType::CANNOT && $type !== TokenType::BECAUSE)
            ) {
                return false;
            }
        }

        return false;
    }

    /**
     * What may start a rule entry.
     *
     * @param bool $topLevel Whether the cursor is outside every block, where a
     *   `for` header may start one.
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function entryStart(bool $topLevel): array
    {
        return [
            ['if', self::KIND_KEYWORD, 'a conditional rule', 'if '],
            ['they', self::KIND_KEYWORD, 'an unconditional rule', 'they '],
            ['can they', self::KIND_KEYWORD, 'an ability block', 'can they '],
            ['when', self::KIND_KEYWORD, 'a when block', 'when '],
            ['@include', self::KIND_KEYWORD, 'a rule template', '@include '],
            ...($topLevel ? [['for', self::KIND_KEYWORD, 'a block of rules for a schema', 'for ']] : []),
        ];
    }

    /**
     * What may start a condition: a condition of the schema in scope, `can(`,
     * `check(` and `not`. Inside a `check(...)` predicate the schema is the one
     * the check names.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function expressionStart(): array
    {
        $open = $this->openParenthesis();
        $checked = $open !== null && ($this->before[$open - 1]->type ?? null) === TokenType::CHECK ? $this->namedSchema() : null;

        return [
            ...$this->conditions($checked === null ? $this->writtenIn : new KeyedSchemaId($checked)),
            ['can(', self::KIND_KEYWORD, 'whether the user holds an ability'],
            ['check(', self::KIND_KEYWORD, 'a condition of another schema'],
            ['not', self::KIND_KEYWORD, 'the opposite', 'not '],
        ];
    }

    /**
     * What may start an argument, beyond a literal or a binding.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function argumentStarts(): array
    {
        return [
            ['@context', self::KIND_KEYWORD, 'a value of the check-time context', '@context '],
            ['@column', self::KIND_KEYWORD, 'a column of a row in scope', '@column '],
            ['@sql', self::KIND_KEYWORD, 'raw SQL', '@sql '],
        ];
    }

    /**
     * The schema the `can(...)` or `check(...)` around the cursor names after its
     * `for`, written after the cursor; null when it names none.
     */
    private function namedSchema(): ?string
    {
        $depth = 0;

        foreach ($this->after as $index => $token) {
            if ($token->type === TokenType::LPAREN) {
                $depth++;
            } elseif ($token->type === TokenType::RPAREN && $depth-- === 0) {
                return null;
            } elseif ($depth === 0 && $token->type === TokenType::FOR) {
                $schema = $this->after[$index + 1] ?? null;

                return $schema?->type === TokenType::IDENTIFIER ? $schema->lexeme : null;
            }
        }

        return null;
    }

    /**
     * The schema the handle before a `with` names: the identifier after the
     * nearest `for` before the cursor.
     */
    private function handleSchema(): ?string
    {
        for ($i = count($this->before) - 1; $i >= 0; $i--) {
            if ($this->before[$i]->type === TokenType::FOR) {
                $schema = $this->before[$i + 1] ?? null;

                return $schema?->type === TokenType::IDENTIFIER ? $schema->lexeme : null;
            }
        }

        return null;
    }

    /**
     * @param string|null $key A schema key.
     */
    private function schema(?string $key): ?IndexedSchema
    {
        return $key === null ? null : $this->workspace->catalog()?->schema($key);
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function schemas(): array
    {
        $catalog = $this->workspace->catalog();

        return $catalog === null ? [] : array_values(array_map(
            static fn (IndexedSchema $schema): array => [$schema->key, self::KIND_CLASS, $schema->className],
            $catalog->schemas,
        ));
    }

    /**
     * @param string|null $schemaKey The schema whose abilities to offer.
     * @param bool $withWildcard Whether `*` may stand for every ability here.
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function abilities(?string $schemaKey, bool $withWildcard = false): array
    {
        $schema = $this->schema($schemaKey);
        $items = [];

        foreach ($schema?->abilities ?? [] as $name => $declaration) {
            $items[] = [(string) $name, self::KIND_CONSTANT, "ability of {$schemaKey}"];
        }

        return $withWildcard ? [...$items, ['*', self::KIND_CONSTANT, 'every ability']] : $items;
    }

    /**
     * The conditions a name written in $writtenIn may mean: those of every schema
     * it is looked up in, each name once, as the first schema declaring it has
     * it.
     *
     * @param ISchemaId|null $writtenIn Where the name is written.
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function conditions(?ISchemaId $writtenIn): array
    {
        $items = [];

        foreach ($this->searched($writtenIn) as $schema) {
            foreach ($schema->conditions as $name => $declaration) {
                $items[(string) $name] ??= [(string) $name, self::KIND_FUNCTION, self::detail(sprintf('%s(%s)', $name, implode(', ', $declaration->parameters)), $schema)];
            }
        }

        return array_values($items);
    }

    /**
     * The templates an `@include` at the cursor may name, as {@see conditions()}
     * finds conditions.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function templates(): array
    {
        $items = [];

        foreach ($this->searched($this->writtenIn) as $schema) {
            foreach ($schema->templates as $name => $declaration) {
                $items[(string) $name] ??= [(string) $name, self::KIND_MODULE, self::detail(sprintf('@include %s(%s)', $name, implode(', ', $declaration->parameters)), $schema)];
            }
        }

        return array_values($items);
    }

    /**
     * The schemas a name written in $writtenIn is looked up in, in order.
     *
     * @param ISchemaId|null $writtenIn Where the name is written.
     * @return list<IndexedSchema>
     */
    private function searched(?ISchemaId $writtenIn): array
    {
        $catalog = $this->workspace->catalog();

        return $catalog === null || $writtenIn === null ? [] : $catalog->searchedFrom($writtenIn);
    }

    /**
     * $signature, followed by the global schema that declares it, when one does.
     *
     * @param string $signature How the name is used.
     * @param IndexedSchema $schema The schema that declares it.
     */
    private static function detail(string $signature, IndexedSchema $schema): string
    {
        return $schema->key === null ? "{$signature} from global {$schema->className}" : $signature;
    }

    /**
     * @param string|null $schemaKey The schema whose context keys to offer.
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function contextKeys(?string $schemaKey): array
    {
        return array_map(
            static fn (string $key): array => [$key, self::KIND_PROPERTY, "context key of {$schemaKey}"],
            $this->schema($schemaKey)?->contextKeys() ?? [],
        );
    }

    /**
     * The frame names a `@column` may give at the cursor, each inserted with the
     * `.` that follows it.
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string}>
     */
    private function frames(): array
    {
        return array_map(
            static fn (string $name): array => [$name, self::KIND_VARIABLE, 'frame', $name.'.'],
            $this->frames,
        );
    }
}
