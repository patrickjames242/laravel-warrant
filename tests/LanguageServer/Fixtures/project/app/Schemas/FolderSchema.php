<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;

final class FolderSchema extends BaseSchema
{
    public const model = 'App\Models\Folder';

    #[Ability]
    public const SHARE = 'share';

    #[RowCondition]
    public function isShared(RowConditionContext $c): mixed
    {
        return null;
    }

    public function matchKey(RowConditionContext $c, mixed $team, mixed $slug): mixed
    {
        return null;
    }
}
