<?php

namespace App\Support\Push;

use App\Models\PushCampaign;
use Illuminate\Support\Facades\Log;

/**
 * The development driver: records what would have been sent.
 *
 * Deliberately not silent. A no-op sender that logs nothing makes "push is not
 * wired up" look identical to "push is broken", and someone will eventually
 * spend an afternoon on that.
 */
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

        // Reported as failed rather than delivered. Recording a delivery that
        // did not happen would put a number in the panel a customer would
        // reasonably act on.
        return new PushResult(
            delivered: 0,
            failed: $count,
            reason: 'Push delivery is not configured yet. No notifications were sent.',
        );
    }
}
