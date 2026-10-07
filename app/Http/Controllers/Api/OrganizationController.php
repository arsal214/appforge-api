<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    /**
     * There is no organisation id in the route. A user belongs to exactly one
     * organisation and may only edit that one, so taking an id from the client
     * would add a parameter whose only possible use is to try someone else's.
     */
    public function update(UpdateOrganizationRequest $request): OrganizationResource
    {
        $organization = $request->user()->organization;

        $organization->update([
            'name' => trim($request->validated('name')),
            'slug' => Str::slug($request->validated('name')),
        ]);

        return new OrganizationResource($organization);
    }
}
