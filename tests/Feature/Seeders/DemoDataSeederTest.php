<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\UserPosition;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the staff roster', function () {
    expect(User::count())->toBe(8)
        ->and(User::where('position', UserPosition::Admin)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Manager)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Lawyer)->count())->toBe(3)
        ->and(User::where('position', UserPosition::Assistant)->count())->toBe(2)
        ->and(User::where('position', UserPosition::Accountant)->count())->toBe(1)
        ->and(User::where('email', 'admin@luatvukhang.com')->exists())->toBeTrue();
});

it('seeds six matter types each with a stage set', function () {
    expect(MatterType::count())->toBe(6)
        ->and(MatterType::pluck('code')->sort()->values()->all())->toBe(['DD', 'DN', 'DS', 'HN', 'HS', 'LD'])
        ->and(MatterType::all()->every(fn ($t) => $t->stages()->count() >= 5))->toBeTrue()
        ->and(MatterType::where('code', 'DS')->first()->stages()->count())->toBe(11);
});

it('seeds the land dispute checklist with twelve items and two more templates', function () {
    $land = ChecklistTemplate::whereHas('matterType', fn ($q) => $q->where('code', 'DD'))->firstOrFail();

    expect(ChecklistTemplate::count())->toBe(3)
        ->and($land->items)->toHaveCount(12)
        ->and($land->items->where('is_required', true))->toHaveCount(4)
        ->and($land->items->first()->name)->toContain('Giấy tờ tuỳ thân');
});

it('seeds twelve clients each with one or two portal accounts', function () {
    expect(Client::count())->toBe(12)
        ->and(ClientUser::count())->toBe(16)
        ->and(Client::doesntHave('clientUsers')->count())->toBe(0)
        ->and(Client::withCount('clientUsers')->get()->max('client_users_count'))->toBe(2)
        ->and(ClientUser::where('email', 'khach1@example.com')->exists())->toBeTrue();
});

/**
 * 21 = 20 vụ theo kịch bản SPEC §12 + 1 vụ `restricted` (mang sang từ rà soát M2, task 10):
 * MatterSeeder::restrictedMatter() thêm đúng một vụ mật ngoài 20 vụ đánh số, để nhánh
 * `restricted` của Matter::scopeListableBy() có dữ liệu thật thay vì chỉ có trong test.
 */
it('seeds twenty matters with the deliberate situations from the spec, plus one restricted matter', function () {
    expect(Matter::count())->toBe(21)
        ->and(Matter::where('last_client_update_at', '<', now()->subDays(14))->count())->toBeGreaterThanOrEqual(3)
        ->and(Deadline::query()->upcoming(3)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(2)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::Missing)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(4)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::PendingReview)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(5)
        ->and(Matter::pluck('stage')->unique()->count())->toBeGreaterThanOrEqual(4);
});

it('gives every matter a lead in the team, three to eight stage logs and two to four parties', function () {
    Matter::with(['team', 'stageLogs', 'parties'])->get()->each(function (Matter $m) {
        expect($m->team->pluck('id'))->toContain($m->lead_lawyer_id)
            ->and($m->stageLogs->count())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8)
            ->and($m->parties->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($m->parties->where('is_our_client', true)->count())->toBe(1);
    });

    expect(StageLog::where('is_published', true)->count())->toBeGreaterThan(0)
        ->and(StageLog::where('is_published', false)->count())->toBeGreaterThan(0);
});

it('plants one red conflict of interest between two matters', function () {
    $ours = MatterParty::where('is_our_client', true)->whereNotNull('id_number_hash')->get();

    $conflicts = MatterParty::where('is_our_client', false)
        ->whereIn('id_number_hash', $ours->pluck('id_number_hash'))
        ->get()
        ->filter(fn ($p) => $ours->where('id_number_hash', $p->id_number_hash)->where('matter_id', '!=', $p->matter_id)->isNotEmpty());

    expect($conflicts)->toHaveCount(1);
});

it('leaves some published logs unread so the dashboard has data', function () {
    $published = StageLog::where('is_published', true)->pluck('id');
    $viewed = StageLogView::whereIn('stage_log_id', $published)->pluck('stage_log_id')->unique();

    expect($viewed->count())->toBeGreaterThan(0)
        ->and($published->diff($viewed)->count())->toBeGreaterThan(0);
});
