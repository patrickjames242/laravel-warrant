<?php

namespace App\Schemas;

use App\Models\Document;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\RequiredContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Facades\Warrant;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * The documents users write and share.
 */
class DocumentSchema extends BaseSchema
{
    use ChecksTeams;

    public const model = Document::class;

    #[Ability(requiredContext: ['org'])]
    public const EDIT = 'edit';

    #[TenantAbility]
    public const ARCHIVE = 'archive';

    #[RequiredContext]
    public const ORG = 'org';

    /**
     * Whether the user wrote the document.
     */
    #[RowCondition]
    public function isOwner(RowConditionContext $c): mixed
    {
        return null;
    }

    #[RowCondition(key: 'in_folder')]
    public function insideFolder(RowConditionContext $c, int $folder): mixed
    {
        return null;
    }

    #[DerivedCondition]
    public function ownerOrTeam(mixed $team): WarrantSyntax
    {
        return Warrant::parse('is_owner or in_team(:team)', ['team' => $team]);
    }

    /**
     * Owners may edit.
     */
    #[RuleTemplate]
    public function ownerEdits(): string
    {
        return <<<'WARRANT'
            if is_owner they can
            WARRANT;
    }

    public function rules(): string
    {
        return <<<WARRANT
            if is_owner they can view, edti
            if can(view for folders(@context folder)) they can view
            WARRANT;
    }

    #[RuleTemplate]
    public function sharedWith(mixed $team): WarrantSyntax
    {
        return Warrant::parse('if in_team(:team) they can', ['team' => $team]);
    }
}
