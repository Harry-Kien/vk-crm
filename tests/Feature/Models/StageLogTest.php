<?php

use App\Exceptions\StageLogImmutable;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Illuminate\Database\QueryException;

it('records who wrote the log and orders newest first on the matter', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');
    $matter = Matter::factory()->create();

    $old = StageLog::factory()->for($matter)->create(['occurred_at' => now()->subDays(5)]);
    $new = StageLog::factory()->for($matter)->create(['occurred_at' => now()->subDay()]);

    expect($old->created_by)->toBe($author->id)
        ->and($old->author->is($author))->toBeTrue()
        ->and($matter->stageLogs->first()->is($new))->toBeTrue();
});

it('allows publishing flags to change but nothing else', function () {
    $log = StageLog::factory()->internalOnly()->create();

    $log->update(['is_published' => true, 'published_at' => now(), 'notified_at' => now()]);
    expect($log->fresh()->is_published)->toBeTrue();

    expect(fn () => $log->update(['public_content' => 'Nội dung đã bị sửa sau khi công bố, không được phép']))
        ->toThrow(StageLogImmutable::class);
    expect(fn () => $log->update(['occurred_at' => now()]))->toThrow(StageLogImmutable::class);
});

it('cannot be deleted', function () {
    $log = StageLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(StageLogImmutable::class)
        ->and(StageLog::count())->toBe(1);
});

it('stores one view per client user and log', function () {
    $log = StageLog::factory()->published()->create();
    $viewer = ClientUser::factory()->create(['client_id' => $log->matter->client_id]);

    StageLogView::factory()->create(['stage_log_id' => $log->id, 'client_user_id' => $viewer->id]);

    expect($log->views)->toHaveCount(1)
        ->and(fn () => StageLogView::factory()->create(['stage_log_id' => $log->id, 'client_user_id' => $viewer->id]))
        ->toThrow(QueryException::class);
});
