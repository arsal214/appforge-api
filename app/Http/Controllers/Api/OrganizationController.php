<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
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
