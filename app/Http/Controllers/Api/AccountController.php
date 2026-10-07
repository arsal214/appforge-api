<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\UserResource;

class AccountController extends Controller
{
    public function update(UpdateAccountRequest $request): UserResource
    {
        $user = $request->user();

        $user->update([
            'name' => trim($request->validated('name')),
            'email' => $request->validated('email'),
        ]);

        return new UserResource($user);
    }
}
