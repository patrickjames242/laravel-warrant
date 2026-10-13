<?php

namespace Warrant\DSL\Parsing;

/**
 * Which kind of mistake a {@see Diagnostic} is about. Its message says what is
 * wrong in words for a person, and changes with the details; the code stays the
 * same for every mistake of the kind, so an editor can act on it, as a quick fix
 * does. Each value is a stable name for the kind, which an editor is handed.
 */
enum DiagnosticCode: string
{
    // -- lexical: text that is not a token ------------------------------------

    /** A character no token starts with, as `~`. */
    case UnexpectedCharacter = 'unexpected-character';

    /** A `:` with no binding name after it. */
    case MissingBindingName = 'missing-binding-name';

    /** An `@` with no word after it, or a word other than context, column, sql or include. */
    case UnknownReference = 'unknown-reference';

    /** An escape inside a string other than `\'`, `\"` and `\\`. */
    case InvalidEscape = 'invalid-escape';

    /** A string with no closing quote. */
    case UnterminatedString = 'unterminated-string';

    /** A number missing a digit, as `-` or `1.` with nothing after. */
    case MalformedNumber = 'malformed-number';

    // -- syntax: tokens that do not fit the grammar ---------------------------

    /** A token where none of its kind can be, as text after the end of a rule set. */
    case UnexpectedToken = 'unexpected-token';

    /** A bracket or keyword the grammar needs and the text leaves out, as a closing `)`. */
    case MissingToken = 'missing-token';

    /** A reserved word, as `if` or `they`, written where a name is needed. */
    case ReservedWordAsName = 'reserved-word-as-name';

    /** A name the grammar needs and the text leaves out: a schema, ability, template, context key or alias. */
    case MissingName = 'missing-name';

    /** A condition the grammar needs and the text leaves out, as after `if` or `and`. */
    case MissingCondition = 'missing-condition';

    /** An argument the grammar needs and the text leaves out, or one written wrong, as `@context` with no key. */
    case MissingArgument = 'missing-argument';

    /** A `because` with no denial message after it. */
    case MissingDenialMessage = 'missing-denial-message';

    /** A rule with no `they can ...` or `they cannot ...` clause, or a `they` with neither after it. */
    case MissingClause = 'missing-clause';

    /** A key written twice in one `with` map. */
    case DuplicateContextKey = 'duplicate-context-key';

    /** An ability block header written without its `can they`, as `view, edit { ... }`. */
    case AbilityListWithoutCanThey = 'ability-list-without-can-they';

    /** A `{ ... }` block with no `for <schema>` header, where a rule set needs one. */
    case BlockWithoutHeader = 'block-without-header';

    /** A rule set beside another without braces, where every one of several needs them. */
    case UnbracedBlockBesideAnother = 'unbraced-block-beside-another';

    /** Rules with no `for` header followed by a `for` block. */
    case RulesBeforeBlock = 'rules-before-block';

    // -- bindings: placeholders and the values given for them ---------------

    /** Named (`:name`) and positional (`?`) placeholders in one text. */
    case MixedBindings = 'mixed-bindings';

    /** A placeholder with no value given for it. */
    case MissingBindingValue = 'missing-binding-value';

    /** A value given for no placeholder in the text. */
    case UnusedBinding = 'unused-binding';

    /** A value that cannot stand where its placeholder is, as a number for an `@sql` body. */
    case InvalidBindingValue = 'invalid-binding-value';

    // -- the language: rules the grammar reads but the language forbids --------

    /** An ability block header that names no ability. */
    case AbilityBlockNamesNone = 'ability-block-names-none';

    /** An ability block inside another. */
    case AbilityBlockInsideBlock = 'ability-block-inside-block';

    /** A clause or @include inside an ability block that names abilities, which the header names already. */
    case AbilitiesNamedInsideBlock = 'abilities-named-inside-block';

    /** A clause or @include outside an ability block that names no ability. */
    case AbilitiesUnnamedOutsideBlock = 'abilities-unnamed-outside-block';

    /** Rules with no `for` header of which some name their abilities and some do not. */
    case AbilityNamingDisagrees = 'ability-naming-disagrees';

    /** A denial message after `they can`, where it could never be shown. */
    case MessageOnCanClause = 'message-on-can-clause';

    /** A denial message that is not text, as a `@context` reference. */
    case MessageNotText = 'message-not-text';

    /** An ability listed in more than one `they cannot` clause of one rule. */
    case DuplicateCannotAbility = 'duplicate-cannot-ability';

    // -- the schema: names and shapes the schema does not have ---------------

    /** A schema name no schema is registered under. */
    case UnknownSchema = 'unknown-schema';

    /** An ability the schema does not declare. */
    case UnknownAbility = 'unknown-ability';

    /** A condition the schema does not declare. */
    case UnknownCondition = 'unknown-condition';

    /** Fewer arguments than a condition, or a schema's row key, needs. */
    case TooFewArguments = 'too-few-arguments';

    /** A `@column` reference naming a frame that is not in scope where it is written. */
    case ColumnNotInScope = 'column-not-in-scope';

    /** An `as` alias on a reference that selects no row for it to name. */
    case AliasWithoutRow = 'alias-without-row';

    /** A row selector on a reference to a schema that has no rows. */
    case RowTargetOnRowlessSchema = 'row-target-on-rowless-schema';

    /** A row selector whose value is null. */
    case NullRowTarget = 'null-row-target';

    /** A row selector on a reference to a schema that has no way to name a row. */
    case UnnameableRow = 'unnameable-row';

    /** A `with` map on a `can(...)` that crosses to no other schema. */
    case ContextMapWithoutSchema = 'context-map-without-schema';

    /** A constant inside a check(...) predicate, which asks the target nothing. */
    case ConstantInCheckPredicate = 'constant-in-check-predicate';

    /** A rule the schema cannot accept for a reason none of the kinds above names, as one its own code gives. */
    case InvalidRule = 'invalid-rule';
}
