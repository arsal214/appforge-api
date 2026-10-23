<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('organization_id');

        if (Auth::hasUser()) {
            $builder->where($column, Auth::user()->organization_id);

            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
