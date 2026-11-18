<?php

namespace App\Support\Accounts;

use App\Models\AppUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign-in tokens for the people who use a customer's app.
 *
 * Only the hash is stored, so a copy of the table signs nobody in.
 *
 * shortcut: a token lasts until the person signs out. Add an expiry and a
 * sweep when the table is large enough to matter or a phone is ever reported
 * stolen with no way to end its session.
 */
final class AppSession
{
    public static function start(AppUser $user): string
    {
        $token = Str::random(64);

        DB::table('app_user_tokens')->insert([
            'app_user_id' => $user->getKey(),
            'token_hash' => self::hash($token),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /**
     * Who the request's token belongs to, in this app. A token from another
     * app is a stranger here, exactly as no token is.
     */
    public static function user(Request $request, string $appId): ?AppUser
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $user = AppUser::query()
            ->join('app_user_tokens', 'app_user_tokens.app_user_id', '=', 'app_users.id')
            ->where('app_user_tokens.token_hash', self::hash($token))
            ->where('app_users.app_id', $appId)
            ->first(['app_users.*']);

        if ($user !== null) {
            DB::table('app_user_tokens')
                ->where('token_hash', self::hash($token))
                ->update(['last_used_at' => now()]);
        }

        return $user;
    }

    public static function end(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token !== null && $token !== '') {
            DB::table('app_user_tokens')->where('token_hash', self::hash($token))->delete();
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
