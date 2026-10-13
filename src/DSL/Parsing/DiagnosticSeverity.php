<?php

namespace Warrant\DSL\Parsing;

/**
 * How serious a {@see Diagnostic} is, numbered as the Language Server Protocol
 * numbers them, so an editor can be handed the value as it is.
 */
enum DiagnosticSeverity: int
{
    /** A mistake: the text cannot mean what it says. */
    case Error = 1;

    /** Something that reads, but is probably not what was meant. */
    case Warning = 2;

    /** Something worth knowing about the text that is not wrong with it. */
    case Information = 3;

    /** A suggestion for writing the text another way. */
    case Hint = 4;
}
