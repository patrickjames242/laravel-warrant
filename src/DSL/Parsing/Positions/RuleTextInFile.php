<?php

namespace Warrant\DSL\Parsing\Positions;

use ReflectionException;
use ReflectionMethod;
use Warrant\DSL\Fragments\Fragment;
use Warrant\DSL\Fragments\FragmentExtractor;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;

/**
 * Finds where in a file a stretch of rule text is written, so an error can point
 * there: the `.warrant` file it was read from, the PHP string a `warrant()` call
 * was given, or the string the method that answered with it returns.
 *
 * A PHP string does not remember where it was written, so a place is given only
 * when the file holds the text exactly: the `.warrant` file as it is now, or one
 * string there whose contents are the text. Text built from parts, or returned
 * through other functions, is in no file this can read, and has no place.
 *
 * Reading a PHP file means tokenizing it, so this is for an error being thrown,
 * never for the editor's tolerant reads.
 */
final class RuleTextInFile
{
    /**
     * Where bytes $offset to $endOffset of $bound's text are written, or null
     * when no file can be shown to hold the text.
     *
     * @param SyntaxProducer|null $producer What answered with the text.
     */
    public static function find(BoundSyntax $bound, ?SyntaxProducer $producer, int $offset, int $endOffset): ?PlaceInFile
    {
        if ($bound->path !== null) {
            $text = @file_get_contents($bound->path);

            return $text === $bound->syntax ? new PlaceInFile((string) realpath($bound->path), $text, $offset, $endOffset) : null;
        }

        if ($bound->calledInFile !== null && $bound->calledOnLine !== null) {
            return self::inPhpFile($bound, $bound->calledInFile, $offset, $endOffset, $bound->calledOnLine, null);
        }

        $method = self::answeringMethod($producer);

        if ($method === null || $method->getFileName() === false) {
            return null;
        }

        return self::inPhpFile($bound, $method->getFileName(), $offset, $endOffset, (int) $method->getStartLine(), (int) $method->getEndLine());
    }

    /**
     * The string in the PHP file at $path whose contents are $bound's text,
     * mapped to bytes $offset to $endOffset of it. With $lastLine, it is the one
     * string written from $firstLine to $lastLine, the lines of a method; without
     * it, the string written nearest after $firstLine, the line of a call.
     */
    private static function inPhpFile(BoundSyntax $bound, string $path, int $offset, int $endOffset, int $firstLine, ?int $lastLine): ?PlaceInFile
    {
        $text = @file_get_contents($path);

        if ($text === false) {
            return null;
        }

        $lineOf = static fn (Fragment $fragment): int => substr_count(substr($text, 0, $fragment->fileStart), "\n") + 1;
        $fragments = array_filter(
            (new FragmentExtractor(providesRules: static fn (): bool => true))->extract($path, $text),
            static fn (Fragment $fragment): bool => $fragment->text === $bound->syntax && $lineOf($fragment) >= $firstLine,
        );

        if ($lastLine !== null) {
            $fragments = array_values(array_filter($fragments, static fn (Fragment $fragment): bool => $lineOf($fragment) <= $lastLine));

            if (count($fragments) !== 1) {
                return null;
            }
        }

        usort($fragments, static fn (Fragment $a, Fragment $b): int => $lineOf($a) <=> $lineOf($b));
        $fragment = $fragments[0] ?? null;

        return $fragment === null ? null : new PlaceInFile(
            (string) realpath($path),
            $text,
            $fragment->fileOffsetOf($offset),
            $fragment->fileOffsetOf($endOffset, isEnd: true),
        );
    }

    /**
     * The method whose answer the text is: a schema's or rule provider's
     * `rules()`, or the method of a rule template or derived condition. Null for
     * text nothing answered with.
     */
    private static function answeringMethod(?SyntaxProducer $producer): ?ReflectionMethod
    {
        [$class, $method] = match ($producer?->kind) {
            SyntaxProducerKind::SchemaRules => [$producer->schemaClass, 'rules'],
            SyntaxProducerKind::RuleProvider => [$producer->providerClass, 'rules'],
            SyntaxProducerKind::RuleTemplate, SyntaxProducerKind::DerivedCondition => [$producer->schemaClass, $producer->methodName],
            default => [null, null],
        };

        if ($class === null || $method === null) {
            return null;
        }

        try {
            return new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return null;
        }
    }
}
