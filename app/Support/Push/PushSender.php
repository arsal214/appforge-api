<?php

namespace App\Support\Push;

use App\Models\AppDevice;
use App\Models\PushCampaign;

/**
 * Delivering a push notification to real phones.
 *
 * **Nothing is delivered yet, and that is a hard blocker rather than an
 * omission.** Reaching a device needs credentials that do not exist:
 *
 *  - **Android** needs an FCM service account key, per client.
 *  - **iOS** needs an APNs auth key, issued by an Apple Developer account — and
 *    it must be the *client's* account, never ours. Publishing under our own
 *    developer account is App Store Guideline 4.2.6 and is a hard no at any
 *    price, so this cannot be shortcut with one shared key across customers.
 *
 * Both are per-tenant secrets, so they belong in the credential vault (KMS
 * envelope encryption, decrypted only inside an ephemeral worker) that is also
 * not built. That dependency is why this is an interface with one logging
 * implementation rather than a half-written APNs client: the shape of the call
 * is knowable now, the credentials are not, and an HTTP/2 APNs client that has
 * never run against Apple is worth less than nothing.
 *
 * What *is* real today: campaigns are composed, validated, scheduled, stored
 * and counted. Everything except the last hop.
 */
interface PushSender
{
    /** @param  iterable<AppDevice>  $devices */
    public function send(PushCampaign $campaign, iterable $devices): PushResult;
}
