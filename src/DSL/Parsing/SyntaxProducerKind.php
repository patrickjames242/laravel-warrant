<?php

namespace Warrant\DSL\Parsing;

/**
 * What kind of thing produced a block of rule syntax, which decides what a
 * {@see SyntaxProducer} says about it.
 */
enum SyntaxProducerKind
{
    /** A schema's own `rules()`. */
    case SchemaRules;

    /** The application's global `RuleProvider`. */
    case RuleProvider;

    /** A schema's `#[RuleTemplate]` method, answering for an `@include`. */
    case RuleTemplate;

    /** A schema's `#[DerivedCondition]` method, answering for a rule naming it. */
    case DerivedCondition;

    /** A condition builder's `ifRaw()` or `orIfRaw()`. */
    case RawCondition;

    /** The language server, reading a document open in an editor. */
    case Editor;
}
