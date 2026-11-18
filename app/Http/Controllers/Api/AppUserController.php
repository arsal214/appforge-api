<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\AppUser;
use App\Models\Order;
use App\Support\Accounts\AppSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accounts for the people who use a customer's app. Every route here is
 * public, so the app is looked up by hand rather than through the
 * organisation scope, the way the order endpoint does it.
 */
class AppUserController extends Controller
{
    // Checked when the email is unknown, so a miss costs the same time as a
    // wrong password and the response time does not say which it was.
    private const NOBODY = '$2y$12$yiO5TDHd.yeLN1JVvBbEI.nk9H/ZqKK9vNeBcIXHwkCt8Oa7f4V2q';

    // shortcut: the newest thirty and no paging. Add a `before` cursor,
    // as the panel's order list has, when a customer has ever sent more.
    private const ORDERS_SHOWN = 30;

    public function register(Request $request, string $app): JsonResponse
    {
        $this->lowercaseEmail($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', Password::min(8), 'max:200'],
        ], [
            'name.*' => 'Enter your name.',
            'email.*' => 'Enter a valid email address.',
            'password.*' => 'Use at least 8 characters.',
        ]);

        $this->appOrFail($app);

        try {
            $user = AppUser::create([
                'app_id' => $app,
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['That email already has an account. Sign in instead.'],
            ]);
        }

        return $this->signedIn($user, 201);
    }

    public function login(Request $request, string $app): JsonResponse
    {
        $this->lowercaseEmail($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $this->appOrFail($app);

        $user = AppUser::where('app_id', $app)->where('email', $validated['email'])->first();

        if (! Hash::check($validated['password'], $user?->password ?? self::NOBODY) || $user === null) {
            throw ValidationException::withMessages([
                'email' => ['That email and password do not match.'],
            ]);
        }

        return $this->signedIn($user, 200);
    }

    public function me(Request $request, string $app): JsonResponse
    {
        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        return response()->json(['data' => ['user' => $user->profile()]]);
    }

    /**
     * What the signed-in person has sent through this app, newest first.
     *
     * Matched on the account as well as the app, so the answer can only
     * ever be their own — and it leaves out the details they typed, which
     * they do not need read back and a borrowed phone should not show.
     */
    public function orders(Request $request, string $app): JsonResponse
    {
        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        $orders = Order::where('app_id', $app)
            ->where('app_user_id', $user->getKey())
            ->orderByDesc('number')
            ->limit(self::ORDERS_SHOWN)
            ->get()
            ->map(fn (Order $order): array => [
                'number' => $order->number,
                'kind' => $order->kind,
                'status' => $order->status,
                'total' => $order->total,
                'createdAt' => Iso8601::from($order->created_at),
                'lines' => array_map(fn (array $line): array => [
                    'title' => $line['title'],
                    'quantity' => $line['quantity'],
                ], $order->lines ?? []),
            ])
            ->all();

        return response()->json(['data' => ['orders' => $orders]]);
    }

    /**
     * Deletes the account for good, at once. No password is asked for:
     * someone who has forgotten theirs is still signed in, and must not be
     * left holding an account they cannot close.
     */
    public function destroy(Request $request, string $app): JsonResponse
    {
        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        // The row and nothing else. Its tokens go with it by foreign key,
        // and its orders stay with the shop with the name taken off.
        $user->delete();

        return response()->json(null, 204);
    }

    public function logout(Request $request): JsonResponse
    {
        AppSession::end($request);

        return response()->json(null, 204);
    }

    private function signedIn(AppUser $user, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => AppSession::start($user),
                'user' => $user->profile(),
            ],
        ], $status);
    }

    private function appOrFail(string $app): void
    {
        $exists = DB::table('apps')
            ->where('id', $app)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($exists, 404, 'That app is not available.');
    }

    private function lowercaseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
    }
}
