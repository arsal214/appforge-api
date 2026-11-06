<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use App\Support\Activity;

class AccountController extends Controller
{
    public function update(UpdateAccountRequest $request): UserResource
    {
        $user = $request->user();

        $was = ['name' => $user->name, 'email' => $user->email];

        $user->update([
            'name' => trim($request->validated('name')),
            'email' => $request->validated('email'),
        ]);

        $changed = array_keys(array_diff_assoc(
            ['name' => $user->name, 'email' => $user->email],
            $was,
        ));

        if ($changed !== []) {
            Activity::record($user, 'account.details', null, $user->getKey(), [
                'changed' => $changed,
            ]);
        }

        return new UserResource($user);
    }
}
