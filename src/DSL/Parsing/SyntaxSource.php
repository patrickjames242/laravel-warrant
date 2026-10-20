<?php

namespace Warrant\DSL\Parsing;

/**
 * Where syntax came from: the {@see SyntaxProducer} that handed it over, and,
 * when it was written as rule text, that text as it was handed over. Every node
 * parsed from one block of text is placed in the same source.
 *
 * The producer is what the backend received the syntax from, so the backend
 * sets it, on the source of the syntax it is handed, and sets it again each
 * time it is handed it. Until then there is none: application code makes
 * syntax, by a builder or a parse, before anything knows who will hand it over.
 *
 * Only the source of what is handed over is set. Parsed text shares that
 * source, so all of it says what handed it over. A node with a source of its
 * own and no producer, as a builder makes, belongs to the syntax holding it, and
 * that syntax's producer is its. A node whose source has a producer of its own
 * keeps it: the text of a raw condition inside built rules, or the arguments a
 * caller passed into what answered it.
 *
 * Whether syntax was written as text or built in code is its source's text,
 * not its producer.
 */
final class SyntaxSource
{
    /**
     * @param BoundSyntax|null $bound The syntax as it was handed over, with the
     *   values for its bindings; null for nodes built in code.
     * @param SyntaxProducer|null $producer What handed the syntax over; null
     *   until it is.
     */
    public function __construct(
        public readonly ?BoundSyntax $bound,
        private ?SyntaxProducer $producer = null,
    ) {}

    public function producer(): ?SyntaxProducer
    {
        return $this->producer;
    }

    public function setProducer(SyntaxProducer $producer): void
    {
        $this->producer = $producer;
    }
}
