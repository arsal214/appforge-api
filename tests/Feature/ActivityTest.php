<?php

namespace Tests\Feature;

use App\Models\AccountEvent;
use App\Models\App;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private function config(): array
    {
        return [
            'schemaVersion' => 1,
            'minShellVersion' => '1.0.0',
            'app' => ['name' => 'Sams Pizza', 'slug' => 'sams-pizza'],
            'theme' => [
                'accentColor' => '#0f766e',
                'backgroundColor' => '#ffffff',
                'surfaceColor' => '#f8fafc',
                'textColor' => '#0f172a',
                'mutedTextColor' => '#64748b',
                'borderColor' => '#e2e8f0',
                'cornerRadius' => 12,
            ],
            'tabs' => [
                ['id' => 'tab_home', 'label' => 'Home', 'icon' => 'home', 'screenId' => 'scr_home'],
            ],
            'screens' => [
                [
                    'id' => 'scr_home',
                    'title' => 'Home',
                    'sections' => [
                        [
                            'id' => 'sec_hero',
                            'type' => 'hero',
                            'props' => [
                                'title' => 'Fresh pizza, fast',
                                'ctaLabel' => 'Order now',
                                'ctaAction' => ['type' => 'navigate', 'screenId' => 'scr_home'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return string[] */
    private function actions(User $owner): array
    {
        return AccountEvent::where('organization_id', $owner->organization_id)
            ->orderBy('id')
            ->pluck('action')
            ->all();
    }

    #[Test]
    public function every_action_the_code_records_has_a_sentence_to_show(): void
    {
        $recorded = [];

        foreach (glob(app_path('Http/Controllers/Api/*.php')) as $file) {
            preg_match_all(
                "/Activity::record\(\s*[^,]+,\s*'([a-z_.]+)'/",
                (string) file_get_contents($file),
                $found,
            );

            $recorded = [...$recorded, ...$found[1]];
        }

        $this->assertNotEmpty($recorded, 'no Activity::record calls were found at all');

        $this->assertSame(
            [],
            array_values(array_diff($recorded, array_keys(AccountEvent::ACTIONS))),
            'a controller records an action that AccountEvent::ACTIONS cannot word',
        );
    }

    #[Test]
    public function an_action_nobody_declared_is_refused_rather_than_stored(): void
    {
        $owner = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        Activity::record($owner, 'app.exploded');
    }

    #[Test]
    public function building_an_app_writes_its_history_as_it_goes(): void
    {
        $owner = User::factory()->create();

        $created = $this->actingAs($owner)->postJson('/api/apps', [
            'name' => 'Mill Lane Pizza',
            'templateId' => 'tpl_restaurant',
            'sourceUrl' => 'https://example.com',
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)
            ->patchJson("/api/apps/{$created}", ['name' => 'Mill Lane Pizzeria'])
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson("/api/apps/{$created}", ['accentColor' => '#b45309'])
            ->assertOk();

        $this->actingAs($owner)
            ->putJson("/api/apps/{$created}/config", ['config' => $this->config()])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/apps/{$created}/versions")
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/apps/{$created}/versions/1/restore")
            ->assertOk();

        $this->actingAs($owner)->deleteJson("/api/apps/{$created}")->assertOk();
        $this->actingAs($owner)->postJson("/api/apps/{$created}/restore")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/apps/{$created}")->assertOk();
        $this->actingAs($owner)
            ->deleteJson("/api/apps/{$created}/permanently")
            ->assertOk();

        $this->assertSame([
            'app.created',
            'app.renamed',
            'app.changed',
            'version.published',
            'version.restored',
            'app.binned',
            'app.restored',
            'app.binned',
            'app.deleted',
        ], $this->actions($owner));
    }

    #[Test]
    public function saving_a_draft_is_work_and_is_not_recorded(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        // The editor sends one of these every second and a half while someone
        // is typing. Nine of them is a normal minute's work, not nine events.
        for ($save = 0; $save < 9; $save++) {
            $this->actingAs($owner)
                ->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()])
                ->assertOk();
        }

        $this->assertSame([], $this->actions($owner));
    }

    #[Test]
    public function the_team_changing_is_recorded_with_who_did_it(): void
    {
        $owner = User::factory()->create(['name' => 'Ayesha']);

        $invite = $this->actingAs($owner)->postJson('/api/team/invites', [
            'email' => 'sam@example.com',
            'role' => 'editor',
        ])->assertCreated()->json('data.invite.id');

        $this->actingAs($owner)
            ->deleteJson("/api/team/invites/{$invite}")
            ->assertOk();

        $member = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
            'name' => 'Bilal',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/team/members/{$member->id}", ['role' => 'editor'])
            ->assertOk();

        $this->actingAs($owner)
            ->deleteJson("/api/team/members/{$member->id}")
            ->assertOk();

        $events = AccountEvent::where('organization_id', $owner->organization_id)
            ->orderBy('id')
            ->get();

        $this->assertSame([
            'team.invited',
            'team.invite_cancelled',
            'team.role_changed',
            'team.removed',
        ], $events->pluck('action')->all());

        $this->assertSame('sam@example.com', $events[0]->subject);
        $this->assertSame(['role' => 'editor'], $events[0]->context);
        $this->assertEquals(['from' => 'staff', 'to' => 'editor'], $events[2]->context);
        $this->assertSame('Bilal', $events[3]->subject);

        foreach ($events as $event) {
            $this->assertSame('Ayesha', $event->actor_name);
            $this->assertSame($owner->email, $event->actor_email);
        }
    }

    #[Test]
    public function what_someone_did_outlives_them_leaving_the_team(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'editor',
            'name' => 'Bilal',
            'email' => 'bilal@example.com',
        ]);

        $this->actingAs($editor)->postJson('/api/apps', [
            'name' => 'Bilals Shop',
            'templateId' => 'tpl_restaurant',
            'sourceUrl' => 'https://example.com',
        ])->assertCreated();

        $this->actingAs($owner)
            ->deleteJson("/api/team/members/{$editor->id}")
            ->assertOk();

        $event = AccountEvent::where('action', 'app.created')->firstOrFail();

        $this->assertNull($event->actor_id, 'the row should have been unlinked');
        $this->assertSame('Bilal', $event->actor_name);
        $this->assertSame('bilal@example.com', $event->actor_email);
        $this->assertSame('created the app Bilals Shop', $event->sentence());
    }

    #[Test]
    public function connecting_a_shop_is_recorded_and_the_secret_is_not(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Mill Lane',
        ]);

        $this->actingAs($owner)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", [
                'storeUrl' => 'https://shop.example',
                'consumerKey' => 'ck_live_key_value_1234',
                'consumerSecret' => 'cs_live_never_log_me',
            ])->assertCreated();

        $this->actingAs($owner)
            ->deleteJson("/api/apps/{$app->id}/credentials/woocommerce")
            ->assertOk();

        $events = AccountEvent::orderBy('id')->get();

        $this->assertSame(
            ['credential.saved', 'credential.removed'],
            $events->pluck('action')->all(),
        );
        $this->assertSame('WooCommerce on Mill Lane', $events[0]->subject);

        $this->assertStringNotContainsString(
            'cs_live_never_log_me',
            $events->toJson(),
            'the credential value reached the activity log',
        );
    }

    #[Test]
    public function renaming_the_business_is_recorded(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->patchJson('/api/organization', ['name' => 'Mill Lane Group'])
            ->assertOk();

        $event = AccountEvent::firstOrFail();

        $this->assertSame('business.renamed', $event->action);
        $this->assertSame('renamed the business to Mill Lane Group', $event->sentence());
    }

    #[Test]
    public function the_owner_can_read_the_history_newest_first(): void
    {
        $owner = User::factory()->create(['name' => 'Ayesha']);

        $this->actingAs($owner)
            ->patchJson('/api/organization', ['name' => 'First Name'])
            ->assertOk();
        $this->actingAs($owner)
            ->patchJson('/api/organization', ['name' => 'Second Name'])
            ->assertOk();

        $page = $this->actingAs($owner)
            ->getJson('/api/account/activity')
            ->assertOk()
            ->json('data');

        $this->assertFalse($page['hasMore']);
        $this->assertCount(2, $page['events']);

        $this->assertSame('Second Name', $page['events'][0]['subject']);
        $this->assertSame(
            'renamed the business to Second Name',
            $page['events'][0]['sentence'],
        );
        $this->assertSame('Ayesha', $page['events'][0]['actor']['name']);
        $this->assertTrue($page['events'][0]['actor']['stillOnTheTeam']);
    }

    #[Test]
    public function older_history_is_reachable_a_page_at_a_time(): void
    {
        $owner = User::factory()->create();

        for ($i = 1; $i <= 51; $i++) {
            Activity::record($owner, 'business.renamed', "Name {$i}");
        }

        $first = $this->actingAs($owner)
            ->getJson('/api/account/activity')
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['hasMore']);
        $this->assertCount(50, $first['events']);
        $this->assertSame('Name 51', $first['events'][0]['subject']);

        $oldest = end($first['events'])['id'];

        $second = $this->actingAs($owner)
            ->getJson("/api/account/activity?before={$oldest}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($second['hasMore']);
        $this->assertCount(1, $second['events']);
        $this->assertSame('Name 1', $second['events'][0]['subject']);
    }

    #[Test]
    public function one_business_never_reads_anothers_history(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->actingAs($theirs)
            ->patchJson('/api/organization', ['name' => 'Not Mine'])
            ->assertOk();

        $page = $this->actingAs($mine)
            ->getJson('/api/account/activity')
            ->assertOk();

        $page->assertJsonCount(0, 'data.events');
        $this->assertStringNotContainsString('Not Mine', $page->content());
    }

    #[Test]
    public function only_the_owner_can_read_the_history(): void
    {
        foreach (['editor', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->getJson('/api/account/activity')
                ->assertForbidden();
        }

        // Otherwise the guard still holds the staff user from the loop above,
        // and the ability check answers 403 before auth is ever consulted.
        $this->forgetAuthentication();

        $this->getJson('/api/account/activity')->assertUnauthorized();
    }

    #[Test]
    public function downloading_a_copy_of_the_account_is_itself_recorded(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->getJson('/api/account/export')->assertOk();

        $this->assertSame(['account.exported'], $this->actions($owner));
    }

    #[Test]
    public function a_cursor_that_is_too_long_to_be_an_id_is_refused(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->getJson('/api/account/activity?before='.str_repeat('x', 31))
            ->assertJsonValidationErrors('before');
    }

    /**
     * Everything a customer changes is supposed to appear in their history.
     * The exceptions are listed here with their reasons, so adding a write
     * endpoint and forgetting the log fails rather than quietly shipping a
     * gap nobody notices until they ask "who did that?".
     */
    #[Test]
    public function every_route_that_changes_something_records_it(): void
    {
        $excused = [
            // Signing in and out changes nothing about the account, and a
            // line per sign-in would bury the lines that matter.
            'POST login',
            'POST logout',

            // Only sends an email. The change it leads to is recorded by
            // `account.password` when the new password is actually set.
            'POST forgot-password',

            // The builder. A draft save fires every second and a half while
            // someone is typing; publishing the result is the decision, and
            // that is recorded.
            'PUT apps/{app}/config',
        ];

        $routes = file_get_contents(base_path('routes/api.php'));

        preg_match_all(
            "/Route::(post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[(\w+)::class,\s*'(\w+)'\]/",
            (string) $routes,
            $found,
            PREG_SET_ORDER,
        );

        $this->assertGreaterThan(25, count($found), 'the route file was not parsed');

        $silent = [];

        foreach ($found as [, $verb, $uri, $controller, $action]) {
            $name = strtoupper($verb).' '.$uri;

            if (in_array($name, $excused, true)) {
                continue;
            }

            $file = app_path("Http/Controllers/Api/{$controller}.php");
            $body = file_exists($file) ? (string) file_get_contents($file) : '';

            preg_match("/function {$action}\(.*?
    \}/s", $body, $method);

            if (! str_contains($method[0] ?? '', 'Activity::record')) {
                $silent[] = $name;
            }
        }

        $this->assertSame(
            [],
            $silent,
            'these change something and leave no trace in the account history',
        );
    }

    #[Test]
    public function the_day_to_day_running_of_a_shop_is_all_recorded(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Mill Lane',
        ]);

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();

        $order = $app->orders()->firstOrFail();

        $this->actingAs($owner)
            ->patchJson("/api/apps/{$app->id}/orders/{$order->id}", ['status' => 'confirmed'])
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson('/api/account', ['name' => 'Ayesha K', 'email' => $owner->email])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/push", [
                'title' => 'Open late',
                'body' => 'Until 11 tonight.',
            ])->assertCreated();

        $this->actingAs($owner)
            ->deleteJson("/api/apps/{$app->id}/orders/test")
            ->assertOk();

        $recorded = AccountEvent::where('organization_id', $owner->organization_id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame([
            'order.worked',
            'account.details',
            'push.written',
            'order.tests_cleared',
        ], $recorded);

        $this->assertSame(
            'moved Booking #1 on',
            AccountEvent::where('action', 'order.worked')->firstOrFail()->sentence(),
        );
    }

    #[Test]
    public function every_action_belongs_to_exactly_one_filter(): void
    {
        $declared = array_keys(AccountEvent::ACTIONS);
        $grouped = array_merge(...array_values(AccountEvent::GROUPS));

        $this->assertSame(
            [],
            array_values(array_diff($declared, $grouped)),
            'these actions would be invisible under every filter',
        );

        $this->assertSame(
            [],
            array_values(array_diff($grouped, $declared)),
            'a filter claims an action that does not exist',
        );

        $this->assertSame(
            count($grouped),
            count(array_unique($grouped)),
            'an action is claimed by two filters, so it would be counted twice',
        );
    }

    #[Test]
    public function the_filter_narrows_the_list_to_one_kind_of_thing(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [], 'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();

        $order = $app->orders()->firstOrFail();

        $this->actingAs($owner)
            ->patchJson("/api/apps/{$app->id}/orders/{$order->id}", ['status' => 'confirmed'])
            ->assertOk();
        $this->actingAs($owner)
            ->postJson('/api/team/invites', ['email' => 'sam@example.com', 'role' => 'editor'])
            ->assertCreated();
        $this->actingAs($owner)
            ->patchJson('/api/organization', ['name' => 'Renamed'])
            ->assertOk();

        $only = fn (string $kind) => $this->actingAs($owner)
            ->getJson("/api/account/activity?kind={$kind}")
            ->assertOk()
            ->json('data.events');

        $this->assertSame(['order.worked'], array_column($only('orders'), 'action'));
        $this->assertSame(['team.invited'], array_column($only('team'), 'action'));
        $this->assertSame(['business.renamed'], array_column($only('account'), 'action'));
        $this->assertSame([], $only('apps'));

        $everything = $this->actingAs($owner)
            ->getJson('/api/account/activity')
            ->assertOk()
            ->json('data.events');

        $this->assertCount(3, $everything);
    }

    #[Test]
    public function each_event_says_which_filter_it_falls_under(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->patchJson('/api/organization', ['name' => 'Renamed'])
            ->assertOk();

        $this->actingAs($owner)
            ->getJson('/api/account/activity')
            ->assertOk()
            ->assertJsonPath('data.events.0.group', 'account');
    }

    #[Test]
    public function a_filter_nobody_offers_is_refused(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->getJson('/api/account/activity?kind=everything')
            ->assertJsonValidationErrors('kind');
    }

    #[Test]
    public function paging_keeps_the_filter_on(): void
    {
        $owner = User::factory()->create();

        for ($i = 1; $i <= 51; $i++) {
            Activity::record($owner, 'business.renamed', "Name {$i}");
            Activity::record($owner, 'order.worked', "Order #{$i}");
        }

        $first = $this->actingAs($owner)
            ->getJson('/api/account/activity?kind=orders')
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['hasMore']);
        $this->assertCount(50, $first['events']);

        $oldest = end($first['events'])['id'];

        $second = $this->actingAs($owner)
            ->getJson("/api/account/activity?kind=orders&before={$oldest}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $second['events']);
        $this->assertSame(
            ['order.worked'],
            array_unique(array_column($second['events'], 'action')),
            'the second page forgot the filter and let other kinds back in',
        );
    }

    /**
     * The route audit above only proves a controller mentions
     * `Activity::record`. These prove the line actually appears, saying the
     * right thing — one per action that nothing else in this file exercises.
     */
    #[Test]
    public function joining_the_team_from_an_invite_is_recorded(): void
    {
        $owner = User::factory()->create();

        $token = $this->actingAs($owner)
            ->postJson('/api/team/invites', ['email' => 'bilal@example.com', 'role' => 'editor'])
            ->assertCreated()
            ->json('data.token');

        $this->postJson("/api/auth/invites/{$token}", [
            'name' => 'Bilal',
            'password' => 'Str0ng-Passw0rd!',
            'passwordConfirmation' => 'Str0ng-Passw0rd!',
        ])->assertCreated();

        $event = AccountEvent::where('action', 'team.joined')->firstOrFail();

        $this->assertSame('Bilal joined the team', $event->sentence());
        $this->assertSame('bilal@example.com', $event->actor_email);
        $this->assertSame($owner->organization_id, $event->organization_id);
    }

    #[Test]
    public function setting_a_new_password_is_recorded(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'sam@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'An0ther-Passw0rd!',
            'passwordConfirmation' => 'An0ther-Passw0rd!',
        ])->assertOk();

        $event = AccountEvent::where('action', 'account.password')->firstOrFail();

        $this->assertSame('set a new password', $event->sentence());
        $this->assertSame('sam@example.com', $event->actor_email);
    }

    #[Test]
    public function adding_and_deleting_an_image_is_recorded(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();

        $upload = $this->actingAs($owner)
            ->postJson('/api/uploads', ['file' => UploadedFile::fake()->image('dish.png', 800, 600)])
            ->assertCreated()
            ->json('data.upload.id');

        $this->actingAs($owner)->deleteJson("/api/uploads/{$upload}")->assertOk();

        $this->assertSame(
            ['added the image dish.png', 'deleted the image dish.png'],
            AccountEvent::orderBy('id')->get()->map->sentence()->all(),
        );
    }

    #[Test]
    public function a_test_order_and_a_deleted_notification_are_recorded(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Mill Lane',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/orders/test", [
                'lines' => [],
                'total' => null,
                'details' => [['label' => 'Name', 'value' => 'Ayesha']],
            ])->assertCreated();

        $campaign = $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/push", ['title' => 'Open late', 'body' => 'Until 11.'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->deleteJson("/api/apps/{$app->id}/push/{$campaign}")
            ->assertOk();

        $this->assertSame([
            'sent a test to Mill Lane',
            'wrote the notification Open late',
            'deleted the notification Open late',
        ], AccountEvent::orderBy('id')->get()->map->sentence()->all());
    }

    /**
     * Belt and braces over the audit: every action the vocabulary declares is
     * reachable from somewhere, so none of them is wording for a line that can
     * never be written.
     */
    #[Test]
    public function no_action_is_declared_and_then_never_used(): void
    {
        $sources = array_merge(
            glob(app_path('Http/Controllers/Api/*.php')),
            glob(app_path('Console/Commands/*.php')),
        );

        $code = '';
        foreach ($sources as $file) {
            $code .= (string) file_get_contents($file);
        }

        $unused = [];
        foreach (array_keys(AccountEvent::ACTIONS) as $action) {
            if (! str_contains($code, "'{$action}'")) {
                $unused[] = $action;
            }
        }

        $this->assertSame([], $unused, 'these actions have wording but nothing ever records them');
    }
}
