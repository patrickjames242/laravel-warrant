<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\Schema\Constants\ConstantDefinition;

/**
 * How {@see SyntaxValidator} checks the constants a text names: that each one
 * names a constant, and that its value can stand where it is written.
 *
 * A bare name is looked up from the schema its text was written in, as a
 * condition is. A prefixed name is looked up in the schema the prefix names
 * alone. A value the catalog does not know, as the language server may not, is
 * not judged.
 *
 * @internal
 */
trait ValidatesConstants
{
    /**
     * Report every constant among $arguments that names none, and every `@sql`
     * body among them whose constant is not a string.
     *
     * @param list<INode> $arguments Arguments as written.
     * @param ISchemaId|null $writtenIn Where a bare name among them is looked up
     *   from; null when that is not known.
     */
    private function assertConstantsResolve(array $arguments, ?ISchemaId $writtenIn): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof ConstantRef) {
                $this->resolvedConstant($argument, $writtenIn);
            }

            if ($argument instanceof SqlRef && $argument->sql instanceof ConstantRef) {
                $constant = $this->resolvedConstant($argument->sql, $writtenIn);

                if ($constant !== null && $constant->valueIsKnown && ! is_string($constant->value)) {
                    $this->fail(
                        DiagnosticCode::InvalidConstantValue,
                        LanguageRuleMessages::sqlConstantNotText($argument->sql->written(), $constant->value),
                        $argument->sql->location,
                    );
                }
            }
        }
    }

    /**
     * The constant $ref names, reporting it when it names none. Null when it
     * names none, or when there is nothing to look it up in: no catalog, a bare
     * name written where no schema is known, or a name the text is missing,
     * which was reported where it was read.
     *
     * @param ISchemaId|null $writtenIn Where a bare name is looked up from.
     */
    private function resolvedConstant(ConstantRef $ref, ?ISchemaId $writtenIn): ?ConstantDefinition
    {
        $catalog = $this->input->catalog;
        $name = $ref->name->name;

        if ($catalog === null || $name === INode::MISSING_NAME) {
            return null;
        }

        if ($ref->schema !== null) {
            $schema = $catalog->find($ref->schema->name);

            if ($schema === null) {
                $this->fail(
                    DiagnosticCode::UnknownSchema,
                    LanguageRuleMessages::unknownConstantSchema($ref->schema->name, $ref->written()),
                    $ref->schema->location,
                    LanguageRuleMessages::didYouMean($ref->schema->name, $catalog->schemaKeys()),
                );

                return null;
            }

            $found = $this->constructs()->constantDeclaredBy($ref->schema->name, $name);

            if ($found === null) {
                $this->fail(
                    DiagnosticCode::UnknownConstant,
                    LanguageRuleMessages::constantNotDeclaredBy($ref->schema->name, $name),
                    $ref->name->location,
                    LanguageRuleMessages::didYouMean($name, $schema->constantNames()),
                );
            }

            return $found?->definition;
        }

        if ($writtenIn === null) {
            return null;
        }

        $found = $this->constructs()->constant($name, $writtenIn);

        if ($found === null) {
            $this->fail(
                DiagnosticCode::UnknownConstant,
                LanguageRuleMessages::unknownConstant($writtenIn, $name),
                $ref->name->location,
                LanguageRuleMessages::didYouMean($name, $this->constructs()->constantNamesFrom($writtenIn)),
            );
        }

        return $found?->definition;
    }
}
