<?php

namespace Warrant\DSL\Parsing\Positions;

/**
 * Where a stretch of rule text is written in a file: a `.warrant` file, or the
 * PHP string that holds the text.
 */
final readonly class PlaceInFile
{
    /**
     * @param string $path The file's absolute path.
     * @param string $text The file's contents.
     * @param int $offset Byte offset in $text where the stretch starts.
     * @param int $endOffset Byte offset in $text just past where it ends.
     */
    public function __construct(
        public string $path,
        public string $text,
        public int $offset,
        public int $endOffset,
    ) {
    }
}
