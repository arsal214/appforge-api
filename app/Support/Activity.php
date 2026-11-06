<?php

namespace App\Support;

use App\Models\AccountEvent;
use App\Models\User;
use InvalidArgumentException;

final class Activity
{
    /**
     * Writes one line of the account's history.
     *
     * The actor's name and email are copied in rather than joined, because the
     * point of this record is to survive the person leaving the team.
     *
     * An unknown action throws instead of being written. The vocabulary is
     * closed and every call site passes a literal, so the only way to get here
     * is a typo in a new call site — which is a bug to find in a test run, not
     * a row to store and puzzle over later.
     */
    public static function record(
        User $actor,
        string $action,
        ?string $subject = null,
        ?string $subjectId = null,
        array $context = [],
    ): AccountEvent {
        if (! array_key_exists($action, AccountEvent::ACTIONS)) {
            throw new InvalidArgumentException(
                "[{$action}] is not one of AccountEvent::ACTIONS."
            );
        }

        return AccountEvent::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'action' => $action,
            'subject' => $subject,
            'subject_id' => $subjectId,
            'context' => $context === [] ? null : $context,
        ]);
    }
}
