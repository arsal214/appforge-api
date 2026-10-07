<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What people do inside a customer's app — as counters, and nothing else.
 *
 * There is deliberately **no row per event and no device identifier anywhere in
 * this table**. A row is `(app, day, event, detail, platform) → count`. You
 * cannot re-identify a person from a counter, so this never becomes personal
 * data, never needs a consent banner in someone's shop app, and never makes our
 * customer a data controller for something we talked them into collecting.
 *
 * The price is real and worth stating plainly: this can answer "how many screen
 * views yesterday" but not "how many *people*", not retention, and not funnels.
 * Those all require following one device across time, which is the exact thing
 * this design refuses to do. Adding it later means a new table, not a migration
 * of this one — counters cannot be un-aggregated.
 *
 * `day` is UTC. A merchant in Auckland sees a "Monday" that begins at 12pm
 * their time. For trend lines over weeks that is invisible; it would matter for
 * hour-of-day reporting, which is why there is no hour column to tempt anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_event_daily', function (Blueprint $table) {
            $table->char('app_id', 30);

            // UTC. Set by the server, never by the phone — see EventController.
            $table->date('day');

            // From the contract's `eventNames`. Narrow on purpose: a free-text
            // event name is an unbounded table.
            $table->string('event', 24);

            // What the event was about: a screen id, an action type, or empty.
            // Not nullable, because a NULL cannot take part in a primary key —
            // empty string is "this event has no detail".
            $table->string('detail', 64)->default('');

            $table->string('platform', 16);

            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            // The dimensions *are* the identity — there is no surrogate id,
            // because a rollup row has no life of its own. This is also the
            // key the upsert collides on, and being the clustered index makes
            // "one app, last 30 days" a range scan rather than a lookup.
            $table->primary(['app_id', 'day', 'event', 'detail', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_event_daily');
    }
};
