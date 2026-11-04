<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $minutes = (int) config('sanctum.idle_minutes', 60 * 24 * 14);

        if ($minutes <= 0) {
            return;
        }

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunk(500, function ($tokens) use ($minutes) {
                foreach ($tokens as $token) {
                    $from = $token->last_used_at ?? $token->created_at;

                    if ($from === null) {
                        continue;
                    }

                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update([
                            'expires_at' => Carbon::parse($from)->addMinutes($minutes),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->update(['expires_at' => null]);
    }
};
