<?php

namespace App\Support\Push;

use App\Models\PushCampaign;
use Illuminate\Support\Facades\Log;

final class LogPushSender implements PushSender
{
    public function send(PushCampaign $campaign, iterable $devices): PushResult
    {
        $count = 0;
        foreach ($devices as $device) {
            $count++;
        }

        Log::info('[push] not delivered — no APNs/FCM credentials configured', [
            'campaign' => $campaign->id,
            'app' => $campaign->app_id,
            'title' => $campaign->title,
            'would_reach' => $count,
        ]);

        return new PushResult(
            delivered: 0,
            failed: $count,
            reason: 'Push delivery is not configured yet. No notifications were sent.',
        );
    }
}
