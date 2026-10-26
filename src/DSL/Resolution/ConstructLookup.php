<?php

namespace Warrant\DSL\Resolution;

use LogicException;
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
 * read only when the lookup reaches it. No two global schemas may declare the
 * same name, since the later one could never be found: the first time a lookup
 * reaches the globals, every global is read, and a name two of them declare is
 * refused, on that lookup and every one after, until none is.
 */
final class ConstructLookup
{
    private bool $globalNamesChecked = false;

    /**
     * @param ISchemaCatalog $catalog The schemas names are looked up in.
     * @param bool $refuseDuplicateGlobalNames Whether a name two global schemas
     *   declare is refused. The language server passes false: it reports each
     *   one where it is declared, and goes on looking names up, the global listed
     *   first answering, so the rest of the text is still checked.
     */
    public function __construct(
        private readonly ISchemaCatalog $catalog,
        private readonly bool $refuseDuplicateGlobalNames = true,
    ) {
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

        $this->refuseDuplicateGlobalNames();

        foreach ($this->catalog->globalClasses() as $class) {
            yield [new GlobalSchemaId($class, $this->catalog), $this->catalog->findGlobal($class)];
        }
    }

    /**
     * Throw when two global schemas declare the same name, unless this lookup
     * reports them rather than refusing them. Once the globals have been found
     * to share none, they are not read for it again.
     */
    private function refuseDuplicateGlobalNames(): void
    {
        if ($this->globalNamesChecked || ! $this->refuseDuplicateGlobalNames) {
            return;
        }

        $duplicate = $this->duplicateGlobalNames()[0] ?? null;

        if ($duplicate !== null) {
            throw new LogicException($duplicate->message());
        }

        $this->globalNamesChecked = true;
    }

    /**
     * Every condition or rule template name declared by more than one global
     * schema, each naming the global listed first that declares it and one
     * listed after it. Reading it reads every global's vocabulary.
     *
     * @return list<DuplicateGlobalName>
     */
    public function duplicateGlobalNames(): array
    {
        $declaredBy = ['condition' => [], 'rule template' => []];
        $duplicates = [];

        foreach ($this->catalog->globalClasses() as $class) {
            $vocabulary = $this->catalog->findGlobal($class);

            foreach (['condition' => $vocabulary->conditionKeys(), 'rule template' => $vocabulary->ruleTemplateKeys()] as $kind => $names) {
                foreach ($names as $name) {
                    if (isset($declaredBy[$kind][$name])) {
                        $duplicates[] = new DuplicateGlobalName($kind, $name, $declaredBy[$kind][$name], $class);
                    } else {
                        $declaredBy[$kind][$name] = $class;
                    }
                }
            }
        }

        return $duplicates;
    }
}
