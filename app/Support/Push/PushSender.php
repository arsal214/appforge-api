<?php

namespace App\Support\Push;

use App\Models\AppDevice;
use App\Models\PushCampaign;

interface PushSender
{
    /** @param  iterable<AppDevice>  $devices */
    public function send(PushCampaign $campaign, iterable $devices): PushResult;
}
