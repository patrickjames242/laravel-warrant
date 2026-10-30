<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\Declaration;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Workspace;

/**
 * Where a condition, template or ability is used in the project's rule text, for
 * an editor finding its usages or renaming it.
 *
 * A declaration is named by its file and the method or constant in it that
 * declares it, which is how the editor knows it from the PHP. A name in rule
 * text is a use of it when it is looked up to that declaration as validation
 * looks it up, so the same name meaning another schema's declaration is not.
 */
final class Usages
{
    /**
     * The condition, template or ability named at $position, and what declares
     * it: null when the position is on none, or its declaration is not known.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     * @return array{kind: string, name: string, uri: string, member: string}|null
     */
    public static function declarationAt(Workspace $workspace, Document $document, array $position): ?array
    {
        $located = Located::at($workspace, $document, $position);
        $kind = $located === null ? null : self::kindOf($located->context->slot);
        $declaration = $kind === null ? null : $located?->declaration($workspace->vocabulary());

        if ($kind === null || $declaration === null || $declaration->file === null || $declaration->member === null) {
            return null;
        }

        return ['kind' => $kind, 'name' => $declaration->name, 'uri' => Uri::fromPath($declaration->file), 'member' => $declaration->member];
    }

    /**
     * The uses in the project's rule text of what $member of $path declares, and
     * the names a rename of it must not take: those its kind already has in each
     * schema that has it. Null when $member of $path declares no condition,
     * template or ability the server knows.
     *
     * @param Workspace $workspace What the server knows.
     * @param string $path Absolute path of the file the declaration is in.
     * @param string $member The method or constant that declares it.
     * @return array{kind: string, name: string, usages: list<array{uri: string, range: array<string, mixed>}>, taken: list<string>}|null
     */
    public static function of(Workspace $workspace, string $path, string $member): ?array
    {
        return $workspace->remember("usages:{$path}:{$member}", static fn (): ?array => self::search($workspace, $path, $member));
    }

    /**
     * The answer {@see of()} gives, worked out afresh.
     *
     * @return array{kind: string, name: string, usages: list<array{uri: string, range: array<string, mixed>}>, taken: list<string>}|null
     */
    private static function search(Workspace $workspace, string $path, string $member): ?array
    {
        $vocabulary = $workspace->vocabulary();
        $found = null;
        $taken = [];

        foreach ([...array_values($vocabulary->schemas), ...$vocabulary->globals] as $schema) {
            foreach (['ability', 'condition', 'template'] as $kind) {
                foreach (self::ofKind($schema, $kind) as $declaration) {
                    if (self::declares($declaration, $kind, $path, $member)) {
                        $found ??= [$kind, $declaration->name];
                        array_push($taken, ...array_map('strval', array_keys(self::ofKind($schema, $kind))));
                    }
                }
            }
        }

        if ($found === null) {
            return null;
        }

        [$kind, $name] = $found;
        $usages = [];

        foreach ($workspace->projectDocumentsHolding($name) as $document) {
            foreach ($workspace->analyze($document) as $fragment) {
                foreach (self::namesIn($fragment, $name) as $node) {
                    $span = $node->location->span;

                    if ($span === null) {
                        continue;
                    }

                    $located = new Located($fragment, $span->offset, CursorContext::at(
                        $fragment->analysis->syntax,
                        $span->offset,
                        $workspace->catalog(),
                        $fragment->fragment->schemaKey,
                    ));
                    $declaration = self::kindOf($located->context->slot) === $kind ? $located->declaration($vocabulary) : null;

                    if ($declaration !== null && self::declares($declaration, $kind, $path, $member)) {
                        $usages[] = ['uri' => $document->uri, 'range' => $located->rangeOf($document, $span)];
                    }
                }
            }
        }

        $taken = array_values(array_unique(array_diff($taken, [$name])));
        sort($taken);

        return ['kind' => $kind, 'name' => $name, 'usages' => $usages, 'taken' => $taken];
    }

    /**
     * What a name in a slot of this kind names, as the editor calls it, or null
     * for a slot that names none of a condition, template or ability.
     */
    private static function kindOf(?SlotKind $slot): ?string
    {
        return match ($slot) {
            SlotKind::Condition => 'condition',
            SlotKind::Template => 'template',
            SlotKind::Ability => 'ability',
            default => null,
        };
    }

    /**
     * Whether $declaration, of $kind, is declared by $member of $path. A method's
     * name is matched as PHP matches it, without regard to case.
     */
    private static function declares(Declaration $declaration, string $kind, string $path, string $member): bool
    {
        if ($declaration->file === null || $declaration->member === null) {
            return false;
        }

        $sameMember = $kind === 'ability' ? $declaration->member === $member : strcasecmp($declaration->member, $member) === 0;

        return $sameMember && self::samePath($declaration->file, $path);
    }

    private static function samePath(string $a, string $b): bool
    {
        return $a === $b || (realpath($a) ?: $a) === (realpath($b) ?: $b);
    }

    /**
     * $schema's declarations of $kind, by name.
     *
     * @return array<string, Declaration>
     */
    private static function ofKind(IndexedSchema $schema, string $kind): array
    {
        return match ($kind) {
            'ability' => $schema->abilities,
            'condition' => $schema->conditions,
            default => $schema->templates,
        };
    }

    /**
     * Every name node in $fragment's text that writes $name, in the order they
     * are written.
     *
     * @return list<NameNode>
     */
    private static function namesIn(AnalyzedFragment $fragment, string $name): array
    {
        $found = [];
        $pending = $fragment->analysis->syntax->children;

        while ($pending !== []) {
            $node = array_pop($pending);

            if ($node instanceof NameNode && $node->name === $name) {
                $found[] = $node;
            }

            array_push($pending, ...$node->children());
        }

        usort($found, static fn (NameNode $a, NameNode $b): int => ($a->location->span->offset ?? 0) <=> ($b->location->span->offset ?? 0));

        return $found;
    }
}
