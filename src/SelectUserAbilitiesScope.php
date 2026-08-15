<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SelectUserAbilitiesScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $currentUser = auth()->user();

        if (! $currentUser instanceof Authenticatable) {
            return;
        }

        if (! method_exists($model, 'warrantSchema')) {
            return;
        }

        $builder->selectUserAbilities($currentUser);
    }
}
