<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts a tenant-owned model to the authenticated user's organisation.
 *
 * Read this before trusting it: **this scope is defence in depth, not the
 * tenancy boundary.** Controllers query through the relationship
 * (`$request->user()->organization->apps()`), which is tenant-safe by
 * construction and does not depend on ambient authentication state. The scope
 * exists to catch the query someone writes later that forgets.
 *
 * A scope that reads global state has one dangerous failure mode: no
 * authenticated user, so no `where`, so every tenant's rows. That is exactly
 * the incident this is meant to prevent, so it is not silently allowed —
 * see the guard below.
 *
 * `withoutGlobalScopes()` is banned in the customer API layer. If you find
 * yourself reaching for it, you want a query the customer is not entitled to
 * make; write it in `appforge-admin` where that is the explicit job.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('organization_id');

        if (Auth::hasUser()) {
            $builder->where($column, Auth::user()->organization_id);

            return;
        }

        // No authenticated user. In an HTTP request that means the query is
        // running somewhere it should not be — fail closed rather than hand
        // back the whole table.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            // Migrations, seeders, queue workers and tinker legitimately have
            // no user. They get an unscoped query and are trusted to scope
            // themselves; nothing customer-facing runs here.
            //
            // Tests are excluded from that exemption on purpose: PHPUnit also
            // runs in the console, and without this the fail-closed branch
            // would never execute under test and could rot unnoticed.
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
