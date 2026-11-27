<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Mail\AppPasswordCode;
use App\Models\AppUser;
use App\Models\Order;
use App\Support\Accounts\AppSession;
use App\Support\PublicApp;
use AppForge\Schema\Contract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accounts for the people who use a customer's app. Every route here is
 * public, so the app is looked up by hand rather than through the
 * organisation scope, the way the order endpoint does it.
 */
class AppUserController extends Controller
{
    private static ?string $nobody = null;

    /**
     * Checked when the email is unknown, so a miss costs the same time as a
     * wrong password and the response time does not say which it was. Made
     * with the configured rounds, so it keeps matching if they change.
     */
    private static function nobody(): string
    {
        return self::$nobody ??= Hash::make(bin2hex(random_bytes(16)));
    }

    // shortcut: the newest thirty and no paging. Add a `before` cursor,
    // as the panel's order list has, when a customer has ever sent more.
    private const ORDERS_SHOWN = 30;

    // A code is six digits, so what keeps it from being guessed is how few
    // guesses there are: five per code, five codes an hour, fifteen minutes
    // each. Sustained, that is about one chance in seventeen hundred a day.
    private const CODE_MINUTES = 15;

    private const CODE_TRIES = 5;

    private const CODES_PER_HOUR = 5;

    private const RESEND_AFTER_SECONDS = 60;

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

        // Checked before the email comparison so a miss costs the same time.
        // The comparison is exact because the column's collation is not:
        // "àli@x.com" finds Ali, and has a rate-limit bucket of its own.
        if (! Hash::check($validated['password'], $user?->password ?? self::nobody())
            || $user === null
            || $user->email !== $validated['email']) {
            throw ValidationException::withMessages([
                'email' => ['That email and password do not match.'],
            ]);
        }

        return $this->signedIn($user, 200);
    }

    /**
     * Emails a code for setting a new password. The answer is the same
     * whether or not the email has an account here: this is not a way to
     * find out who shops where.
     */
    public function forgot(Request $request, string $app): JsonResponse
    {
        $this->lowercaseEmail($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
        ], [
            'email.*' => 'Enter a valid email address.',
        ]);

        $this->appOrFail($app);

        $user = AppUser::where('app_id', $app)->where('email', $validated['email'])->first();

        if ($user !== null && $user->email === $validated['email']) {
            $this->sendCode($user);
        }

        return response()->json(['data' => null], 202);
    }

    /**
     * Sets the new password, signs this phone in and every other one out —
     * a forgotten password is sometimes a lost phone.
     */
    public function reset(Request $request, string $app): JsonResponse
    {
        $this->lowercaseEmail($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', Password::min(8), 'max:200'],
        ], [
            'email.*' => 'Enter a valid email address.',
            'code.*' => 'Enter the code from the email.',
            'password.*' => 'Use at least 8 characters.',
        ]);

        $this->appOrFail($app);

        // Spaces and dashes are how people copy a code, not part of it.
        $code = preg_replace('/\D/', '', $validated['code']) ?? '';

        // Returned rather than thrown, so a wrong guess still counts: an
        // exception inside would roll the attempt back with everything else.
        $user = DB::transaction(function () use ($app, $validated, $code): ?AppUser {
            $user = AppUser::where('app_id', $app)->where('email', $validated['email'])->first();

            if ($user === null || $user->email !== $validated['email']) {
                return null;
            }

            $reset = DB::table('app_user_resets')
                ->where('app_user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($reset === null
                || Carbon::parse($reset->expires_at)->isPast()
                || $reset->attempts >= self::CODE_TRIES) {
                return null;
            }

            if (! hash_equals($reset->code_hash, self::codeHash($code))) {
                DB::table('app_user_resets')->where('id', $reset->id)->increment('attempts');

                return null;
            }

            DB::table('app_user_resets')->where('id', $reset->id)->delete();
            DB::table('app_user_tokens')->where('app_user_id', $user->getKey())->delete();
            $user->update(['password' => $validated['password']]);

            return $user;
        });

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => ['That code is wrong or has run out. Ask for a new one.'],
            ]);
        }

        return $this->signedIn($user, 200);
    }

    public function me(Request $request, string $app): JsonResponse
    {
        $this->appOrFail($app);

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
        $this->appOrFail($app);

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
     * The signed-in person changing their own name or phone number. The email
     * is not changed here: it is what they sign in with, and changing it
     * without proving the new one is theirs would hand the account to a typo.
     */
    public function update(Request $request, string $app): JsonResponse
    {
        $this->appOrFail($app);

        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:'.Contract::pattern('phone')],
        ], [
            'name.required' => 'Your name cannot be empty.',
            'phone.regex' => 'That does not look like a phone number.',
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'phone' => isset($validated['phone']) && trim($validated['phone']) !== ''
                ? trim($validated['phone'])
                : null,
        ]);

        return response()->json(['data' => ['user' => $user->profile()]]);
    }

    /**
     * A new profile photo, replacing the last one. Held to the same rules as
     * an owner's image upload, because it is served from our domain too, and
     * kept apart from the shop's own images: it is the customer's, not theirs.
     */
    public function storePhoto(Request $request, string $app): JsonResponse
    {
        $this->appOrFail($app);

        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        $edge = Contract::limit('maxImageEdge');

        $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.(int) (Contract::limit('maxUploadBytes') / 1024),
                Rule::dimensions()->maxWidth($edge)->maxHeight($edge),
            ],
        ], [
            'photo.required' => 'Choose a photo.',
            'photo.image' => 'That file is not a photo.',
            'photo.mimetypes' => 'Use a JPG, PNG or WebP photo.',
            'photo.max' => 'That photo is too large. Keep it under 5 MB.',
            'photo.dimensions' => "Keep the photo under {$edge} pixels on each side.",
        ]);

        $file = $request->file('photo');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = $file->storeAs("customer-photos/{$app}", Str::ulid()->toString().'.'.$extension, 'public');

        abort_if($path === false, 500, 'We could not save that photo. Try again in a moment.');

        $previous = $user->avatar_path;
        $user->update(['avatar_path' => $path]);

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json(['data' => ['user' => $user->profile()]]);
    }

    public function destroyPhoto(Request $request, string $app): JsonResponse
    {
        $this->appOrFail($app);

        $user = AppSession::user($request, $app);

        abort_if($user === null, 401, 'Sign in again.');

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json(['data' => ['user' => $user->profile()]]);
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

        // The row and the photo. Its tokens go with it by foreign key, and
        // its orders stay with the shop with the name taken off.
        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

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

    private function sendCode(AppUser $user): void
    {
        $sentJustNow = DB::table('app_user_resets')
            ->where('app_user_id', $user->getKey())
            ->where('created_at', '>', now()->subSeconds(self::RESEND_AFTER_SECONDS))
            ->exists();

        // Quietly, like every other answer here: someone pressing "send it
        // again" twice gets the code already on its way.
        if ($sentJustNow
            || ! RateLimiter::attempt("app-reset:{$user->getKey()}", self::CODES_PER_HOUR, fn () => true, 3600)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::table('app_user_resets')->upsert([[
            'app_user_id' => $user->getKey(),
            'code_hash' => self::codeHash($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['app_user_id'], ['code_hash', 'attempts', 'expires_at', 'created_at', 'updated_at']);

        $appName = (string) DB::table('apps')->where('id', $user->app_id)->value('name');

        Mail::to($user->email)->queue(new AppPasswordCode($appName, $code, self::CODE_MINUTES));
    }

    private static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function appOrFail(string $app): void
    {
        abort_unless(PublicApp::exists($app), 404, 'That app is not available.');
    }

    private function lowercaseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
    }
}
