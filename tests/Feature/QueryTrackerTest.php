<?php

use Illuminate\Support\Facades\DB;
use Smwks\LaravelAiChatUi\Services\QueryTracker;

it('is bound as a singleton', function () {
    expect(app(QueryTracker::class))->toBe(app(QueryTracker::class));
});

it('only records queries executed between startTracking and stopTracking', function () {
    $tracker = app(QueryTracker::class);

    DB::select('select 1'); // before tracking starts — must not be captured

    $tracker->startTracking();
    DB::select('select 2');
    DB::select('select 3');
    $queries = $tracker->stopTracking();

    expect($queries)->toHaveCount(2);
    expect($queries[0]['sql'])->toBe('select 2');
    expect($queries[1]['sql'])->toBe('select 3');

    DB::select('select 4'); // after tracking stops — must not be captured

    expect($tracker->stopTracking())->toBe([]);
});
