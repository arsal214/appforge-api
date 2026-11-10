<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'actor_id', 'actor_name', 'actor_email',
    'action', 'subject', 'subject_id', 'context',
])]
class AccountEvent extends Model
{
    use HasPrefixedUlid;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'app.created' => 'created the app {subject}',
        'app.renamed' => 'renamed an app to {subject}',
        'app.changed' => 'changed the settings of {subject}',
        'app.binned' => 'moved {subject} to the bin',
        'app.restored' => 'restored {subject} from the bin',
        'app.deleted' => 'deleted {subject} for good',
        'version.published' => 'published {subject}',
        'version.restored' => 'rolled {subject} back to an earlier version',
        'catalogue.imported' => 'imported the products for {subject}',
        'credential.saved' => 'connected {subject}',
        'credential.removed' => 'disconnected {subject}',
        'team.invited' => 'invited {subject}',
        'team.invite_cancelled' => 'cancelled the invite for {subject}',
        'team.role_changed' => 'changed what {subject} is allowed to do',
        'team.removed' => 'removed {subject} from the team',
        'business.renamed' => 'renamed the business to {subject}',
        'push.sent' => 'sent the notification {subject}',
        'account.exported' => 'downloaded a copy of the account',
        'account.closing' => 'asked to close {subject}',
        'account.reopened' => 'took back closing {subject}',
        'account.started' => 'started {subject} here',
        'account.details' => 'changed their own name or email',
        'account.password' => 'set a new password',
        'team.joined' => '{subject} joined the team',
        'order.worked' => 'moved {subject} on',
        'order.test_made' => 'sent a test to {subject}',
        'order.tests_cleared' => 'cleared the test records from {subject}',
        'image.added' => 'added the image {subject}',
        'image.removed' => 'deleted the image {subject}',
        'business.saved' => 'saved the business {subject}',
        'business.changed' => 'changed the saved business {subject}',
        'business.removed' => 'deleted the saved business {subject}',
        'push.written' => 'wrote the notification {subject}',
        'push.deleted' => 'deleted the notification {subject}',
    ];

    public const GROUPS = [
        'apps' => [
            'app.created', 'app.renamed', 'app.changed', 'app.binned',
            'app.restored', 'app.deleted',
            'version.published', 'version.restored',
            'credential.saved', 'credential.removed',
            'catalogue.imported',
            'push.written', 'push.sent', 'push.deleted',
        ],
        'orders' => [
            'order.worked', 'order.test_made', 'order.tests_cleared',
        ],
        'team' => [
            'team.invited', 'team.invite_cancelled', 'team.role_changed',
            'team.removed', 'team.joined',
        ],
        'account' => [
            'account.started', 'account.details', 'account.password',
            'account.exported', 'account.closing', 'account.reopened',
            'business.renamed', 'business.saved', 'business.changed',
            'business.removed',
            'image.added', 'image.removed',
        ],
    ];

    public static function idPrefix(): string
    {
        return 'evt';
    }

    public static function groupOf(string $action): ?string
    {
        foreach (self::GROUPS as $group => $actions) {
            if (in_array($action, $actions, true)) {
                return $group;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function sentence(): string
    {
        return str_replace(
            '{subject}',
            $this->subject ?? 'it',
            self::ACTIONS[$this->action] ?? $this->action,
        );
    }
}
