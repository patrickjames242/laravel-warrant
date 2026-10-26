<?php

namespace Warrant\DSL\Resolution;

use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\Schema\Vocabulary;

/**
 * What a name in rule text means: the construct it names, and the schema that
 * declares it.
 *
 * A name is looked up from the schema its text was written in. Text written in
 * a keyed schema looks in that schema, then in each global schema in the
 * order they are registered. Text written in a global schema looks in the
 * global schemas alone, in the same order, so a global's text means the same
 * thing wherever it is reached from.
 *
 * The lookup stops at the first schema that declares the name, and a global is
 * read only when the lookup reaches it.
 */
final readonly class ConstructLookup
{
    public function __construct(private ISchemaCatalog $catalog)
    {
    }

    public function condition(string $name, ISchemaId $writtenIn): ?FoundCondition
    {
        foreach ($this->searchForConstruct($writtenIn) as [$id, $vocabulary]) {
            $definition = $vocabulary->condition($name);

            if ($definition !== null) {
                return new FoundCondition($definition, $id, $vocabulary->schemaClass);
            }
        }

        return null;
    }

    public function ruleTemplate(string $name, ISchemaId $writtenIn): ?FoundRuleTemplate
    {
        foreach ($this->searchForConstruct($writtenIn) as [$id, $vocabulary]) {
            $definition = $vocabulary->ruleTemplate($name);

            if ($definition !== null) {
                return new FoundRuleTemplate($definition, $id, $vocabulary->schemaClass);
            }
        }

        return null;
    }

    /**
     * The schema the text $producer answered with was written in: the global
     * schema whose method answered, when one did, and otherwise $schemaKey, the
     * keyed schema the text's rules are for. Null when neither is known.
     *
     * @return ($schemaKey is string ? ISchemaId : ISchemaId|null)
     */
    public function writtenIn(?SyntaxProducer $producer, ?string $schemaKey): ?ISchemaId
    {
        $class = $producer?->schemaClass;

        foreach ($class === null ? [] : $this->catalog->globalClasses() as $global) {
            if (strcasecmp($global, ltrim($class, '\\')) === 0) {
                return new GlobalSchemaId($global, $this->catalog);
            }
        }

        return $schemaKey === null ? null : new KeyedSchemaId($schemaKey);
    }

    /**
     * The schemas a name written in $writtenIn is looked up in, in order, each
     * with its vocabulary, read only as the search reaches it.
     *
     * @return iterable<array{ISchemaId, Vocabulary}>
     */
    private function searchForConstruct(ISchemaId $writtenIn): iterable
    {
        if ($writtenIn instanceof KeyedSchemaId) {
            $vocabulary = $this->catalog->find($writtenIn->key);

            if ($vocabulary !== null) {
                yield [$writtenIn, $vocabulary];
            }
        }

        foreach ($this->catalog->globalClasses() as $class) {
            yield [new GlobalSchemaId($class, $this->catalog), $this->catalog->findGlobal($class)];
        }
    }
}
