<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\Declaration;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Workspace;

/**
 * Where a condition, template, ability or schema is used in the project's rule
 * text, for an editor finding its usages or renaming it.
 *
 * A declaration is named by its file and the member in it that declares it,
 * which is how the editor knows it from the PHP: the method or constant of a
 * condition, template or ability, and a schema's class. A name in rule text is a
 * use of it when it is looked up to that declaration as validation looks it up,
 * so the same name meaning another schema's declaration is not. A schema is
 * named in rule text by the key `config/warrant.php` registers it under.
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
     * for a condition or template the names a rename of it must not take: those
     * its kind already has in each schema that has it. An ability is not renamed,
     * so it has none, and neither has a schema. Null when $member of $path
     * declares no condition, template, ability or schema the server knows.
     *
     * @param Workspace $workspace What the server knows.
     * @param string $path Absolute path of the file the declaration is in.
     * @param string $member The method or constant that declares it, or the
     *   schema's class.
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
                    if (! self::declares($declaration, $kind, $path, $member)) {
                        continue;
                    }

                    $found ??= [$kind, $declaration->name];

                    if ($kind !== 'ability') {
                        array_push($taken, ...array_map('strval', array_keys(self::ofKind($schema, $kind))));
                    }
                }
            }
        }

        if ($found === null) {
            return self::ofSchema($workspace, $path, $member);
        }

        [$kind, $name] = $found;
        $usages = [];

        foreach ($workspace->projectDocumentsHolding($name) as $document) {
            foreach ($workspace->analyze($document) as $fragment) {
                foreach ($fragment->namesWriting($name) as $node) {
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
     * The uses of the schema whose class $member is declared in $path: every
     * place rule text names its key, as a schema or as the frame of its rows.
     * Null when no registered schema is that class.
     *
     * @return array{kind: string, name: string, usages: list<array{uri: string, range: array<string, mixed>}>, taken: list<string>}|null
     */
    private static function ofSchema(Workspace $workspace, string $path, string $member): ?array
    {
        $key = null;

        foreach ($workspace->vocabulary()->schemas as $schemaKey => $schema) {
            if ($schema->file !== null && self::samePath($schema->file, $path) && strcasecmp(ltrim($schema->className, '\\'), ltrim($member, '\\')) === 0) {
                $key = (string) $schemaKey;
                break;
            }
        }

        if ($key === null) {
            return null;
        }

        $usages = [];

        foreach ($workspace->projectDocumentsHolding($key) as $document) {
            foreach ($workspace->analyze($document) as $fragment) {
                foreach (Occurrences::located($workspace, $fragment, $key) as $located) {
                    $span = $located->span();
                    $slot = $located->context->slot;
                    $namesSchema = $slot === SlotKind::Schema || ($slot === SlotKind::Alias && $located->frameNamedBy() === null);

                    if ($span !== null && $namesSchema) {
                        $usages[] = ['uri' => $document->uri, 'range' => $located->rangeOf($document, $span)];
                    }
                }
            }
        }

        return ['kind' => 'schema', 'name' => $key, 'usages' => $usages, 'taken' => []];
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
}
