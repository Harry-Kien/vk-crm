<?php

use App\Actions\Intake\IntakeSummaryGate;
use App\Enums\ConflictLevel;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Filament\Admin\Widgets\UnansweredIntakesWidget;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Intake\FirstResponseClock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\IntakeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 8 — dữ liệu mẫu tiếp nhận (`IntakeSeeder`, gọi từ `DemoDataSeeder`, không bao giờ chạy
 * production qua `DatabaseSeeder`). Kế hoạch đòi: bản ghi ở MỌI trạng thái, một cặp tiếp nhận đối nhau,
 * một bản ghi Đỏ, một bản ghi quá hạn phản hồi, một bản ghi đã ẩn danh. Mọi bản ghi đi qua đúng các
 * Action của mã sản phẩm (cùng luật `MatterSeeder`: dữ liệu mẫu không được mang một hình dạng mà mã
 * sản phẩm không sinh ra được), nên mỗi khẳng định dưới đây đọc DẤU VẾT của Action (nhật ký, kết quả
 * kiểm tra đã lưu), không chỉ cột trạng thái.
 *
 * Giờ cố định (Thứ Tư 07/10/2026 10:00) để "quá hạn phản hồi" và hạn lưu không phụ thuộc lúc chạy test;
 * các test seeder khác (`DemoDataSeederTest`) seed ở giờ thật, nên seeder cũng được chạy ở mọi giờ.
 *
 * Hàm toàn cục mang tiền tố `isd…`.
 */
beforeEach(function () {
    Storage::fake('private');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Ho_Chi_Minh'));
});

/** Seed cả văn phòng mẫu (`DatabaseSeeder` → `DemoDataSeeder` → … → `IntakeSeeder`). */
function isdSeed(): void
{
    test()->seed(DatabaseSeeder::class);
}

function isdByName(string $name): IntakeRequest
{
    return IntakeRequest::query()->where('contact_name', $name)->where('status', '!=', IntakeStatus::Merged)->sole();
}

it('seeds an intake record in every status, each one written through RecordIntake and checked for conflicts', function () {
    isdSeed();

    $records = IntakeRequest::withTrashed()->get();

    expect($records->pluck('status')->unique()->sortBy(fn (IntakeStatus $s) => $s->value)->values()->all())
        ->toEqualCanonicalizing(IntakeStatus::cases());

    // Dấu vết của `RecordIntake`: một dòng `intake_recorded` cho mỗi bản ghi, causer là nhân sự thật
    // (không có bản ghi nào do một lối vào không nhân sự ghi), và mỗi bản ghi đã có ít nhất một lần
    // kiểm tra xung đột với chủ thể là chính nó.
    $recorded = Activity::query()->where('event', 'intake_recorded')->get();

    expect($recorded)->toHaveCount($records->count())
        ->and($recorded->pluck('subject_id')->sort()->values()->all())->toBe($records->modelKeys())
        ->and($recorded->every(fn (Activity $row): bool => $row->causer_id !== null && $row->properties['actor_explicit'] === true))->toBeTrue();

    $records->each(fn (IntakeRequest $intake) => expect(Activity::query()
        ->where('event', 'conflict_check_run')
        ->where('subject_type', 'intake_request')
        ->where('subject_id', $intake->id)
        ->exists())->toBeTrue("{$intake->code} chưa từng được kiểm tra xung đột"));

    // Người liên hệ chưa là khách hàng (R2): seeder không thêm hồ sơ khách nào — bản ghi chuyển thành
    // vụ việc gắn vào một khách ĐÃ CÓ.
    expect(Client::query()->count())->toBe(12);
});

it('plants two calls that oppose each other, each found by the second source of the other check', function () {
    isdSeed();

    $first = isdByName('Trịnh Văn Hùng');
    $second = isdByName('Lưu Thị Nga');

    expect($first->received_at->lessThan($second->received_at))->toBeTrue()
        ->and($second->parties->pluck('phone_normalized'))->toContain($first->contact_phone_normalized)
        ->and($first->parties->pluck('phone_normalized'))->toContain($second->contact_phone_normalized);

    // Lần gọi sau ra Vàng vì lần gọi trước — nguồn dò thứ hai (R1): khớp mang mã TN-… của lần trước,
    // không câu chuyện; ô câu chuyện của lần sau đóng tới khi có người xác nhận.
    $foundCodes = collect($second->conflict_result['matches'] ?? [])->pluck('matter_code');

    expect($second->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($foundCodes)->toContain($first->code)
        ->and(json_encode($second->conflict_result, JSON_UNESCAPED_UNICODE))->not->toContain((string) $first->summary)
        ->and($second->summary)->toBeNull()
        ->and(IntakeSummaryGate::blockers($second))->toContain(IntakeSummaryBlocker::ConflictAcknowledgement);
});

it('plants one red call that waits for a manager, and one call declined for a conflict by the manager', function () {
    isdSeed();

    $pending = IntakeRequest::query()->get()->filter(fn (IntakeRequest $i): bool => $i->hasUnresolvedRed() && $i->status !== IntakeStatus::Declined);

    expect($pending)->toHaveCount(1);

    $red = $pending->sole();
    $redMatch = collect($red->conflict_result['matches'])->firstWhere('level', ConflictLevel::Red->value);

    // Đỏ đến từ một khách hàng hiện hữu ở vai đối lập (`matter_parties`), không từ nguồn thứ hai.
    expect($red->conflict_level)->toBe(ConflictLevel::Red)
        ->and($red->summary)->toBeNull()
        ->and(IntakeSummaryGate::blockers($red))->toContain(IntakeSummaryBlocker::ConflictRed)
        ->and(Matter::query()->where('code', $redMatch['matter_code'])->exists())->toBeTrue();

    $declined = IntakeRequest::query()->where('decline_reason_is_conflict', true)->sole();
    $manager = User::query()->where('email', 'quanly@luatvukhang.com')->sole();

    expect($declined->status)->toBe(IntakeStatus::Declined)
        ->and($declined->summary)->toBeNull()
        ->and($declined->retention_until)->not->toBeNull()
        ->and(Activity::query()->where('event', 'intake_declined')->where('subject_id', $declined->id)->sole()->causer_id)->toBe($manager->id);
});

/**
 * Rà soát cuối M10, FI7 (t8-m10): lý do của lần từ chối THƯỜNG trong dữ liệu mẫu không được nói ngược
 * danh mục lĩnh vực của chính văn phòng mẫu. Bản Task 8 ghi "Sở hữu trí tuệ nằm ngoài lĩnh vực văn phòng
 * nhận", trong khi `MatterTypeSeeder` của `main` (M9 Task 1) có `SH` "Sở hữu trí tuệ và công nghệ".
 */
it('gives the ordinary decline a reason that does not contradict the practice areas of the demo office', function () {
    isdSeed();

    $declined = IntakeRequest::query()
        ->where('status', IntakeStatus::Declined)
        ->where('decline_reason_is_conflict', false)
        ->sole();

    expect(MatterType::query()->where('code', 'SH')->value('name'))->toBe('Sở hữu trí tuệ và công nghệ')
        ->and($declined->decline_reason)->not->toBeEmpty()
        ->and(mb_strtolower((string) $declined->decline_reason))->not->toContain('ngoài lĩnh vực');
});

it('leaves one call overdue for a first response, on the home widget of the person it is assigned to', function () {
    isdSeed();

    $overdue = isdByName('Kiều Văn Chờ');
    $clock = FirstResponseClock::fromConfig();

    expect($overdue->status)->toBe(IntakeStatus::New)
        ->and($overdue->first_response_at)->toBeNull()
        ->and($clock->isOverdue($overdue))->toBeTrue()
        ->and($clock->overdue(IntakeRequest::query())->pluck('id'))->toContain($overdue->id);

    // Một bản `new` vừa nhận thì chưa quá hạn — widget không phải "mọi bản new". Bản đó Xanh, ô câu
    // chuyện đã mở nhưng chưa ai ghi.
    $fresh = isdByName('Lý Thị Mới Gọi');

    expect($clock->isOverdue($fresh))->toBeFalse()
        ->and($fresh->status)->toBe(IntakeStatus::New)
        ->and(IntakeSummaryGate::isOpen($fresh))->toBeTrue()
        ->and($fresh->summary)->toBeNull();

    Filament::setCurrentPanel('admin');
    $this->actingAs(User::query()->findOrFail($overdue->assigned_to), 'web');

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertSee($overdue->code)
        ->assertDontSee('Kiều Văn Chờ');
});

it('anonymises one lost call past its retention through the real action, and keeps the row for the report', function () {
    isdSeed();

    $anonymised = IntakeRequest::query()->whereNotNull('anonymised_at')->sole();

    expect($anonymised->status)->toBe(IntakeStatus::Lost)
        ->and($anonymised->anonymised_by)->toBeNull()
        ->and($anonymised->contact_name)->toBeNull()
        ->and($anonymised->contact_phone)->toBeNull()
        ->and($anonymised->summary)->toBeNull()
        ->and($anonymised->parties->pluck('name')->filter()->all())->toBe([])
        ->and($anonymised->first_response_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_anonymised')->where('subject_id', $anonymised->id)->exists())->toBeTrue();

    // Mọi bản `lost`/`declined`/`merged` khác còn trong hạn lưu: chỉ đúng một bản bị ẩn danh.
    expect(IntakeRequest::query()->retentionExpired()->count())->toBe(0);
});

it('converts one call into a matter of an existing client through the conversion action, and suggests the quote for its contract', function () {
    isdSeed();

    $won = IntakeRequest::query()->where('status', IntakeStatus::Won)->sole();
    $matter = Matter::query()->findOrFail($won->matter_id);

    expect($won->client_id)->toBe($matter->client_id)
        ->and($matter->client->name)->toBe('Phạm Thị Dung')
        ->and($matter->is_published_to_portal)->toBeFalse()
        ->and(IntakeRequest::quotedAmountFor($matter))->toBe($won->quoted_amount)
        ->and($won->quoted_amount)->toBeGreaterThan(0)
        ->and($won->retention_until)->toBeNull()
        ->and(Activity::query()->where('event', 'intake_converted')->where('subject_id', $won->id)->exists())->toBeTrue()
        // Bên đối lập sang `matter_parties` qua `identify()`, không gõ lại.
        ->and(MatterParty::query()->where('matter_id', $matter->id)->where('is_our_client', false)->pluck('phone_normalized'))
        ->toEqual($won->parties->pluck('phone_normalized'));
});

it('merges a repeat call into the earlier record of the same caller', function () {
    isdSeed();

    $merged = IntakeRequest::query()->where('status', IntakeStatus::Merged)->sole();
    $target = isdByName('Trịnh Văn Hùng');

    expect($merged->merged_into_id)->toBe($target->id)
        ->and($merged->contact_phone_normalized)->toBe($target->contact_phone_normalized)
        ->and($merged->retention_until)->not->toBeNull()
        ->and($merged->received_at->greaterThan($target->received_at))->toBeTrue();
});

it('does not seed the intake records a second time', function () {
    isdSeed();

    $before = IntakeRequest::withTrashed()->count();

    $this->seed(DemoDataSeeder::class);
    $this->seed(IntakeSeeder::class);

    expect(IntakeRequest::withTrashed()->count())->toBe($before)
        // 23 vụ trước M13 + bốn vụ của TeamPerformanceSeeder (M13 Task 8).
        ->and(Matter::query()->count())->toBe(27);
});

/**
 * Hai mốc của seeder tính từ cấu hình, không viết cứng: bản ẩn danh mất liên hệ `retentionMonths() + 1`
 * tháng trước, và bản quá hạn phản hồi được nhận đủ sớm cho ngưỡng `INTAKE_RESPONSE_HOURS`. Một văn
 * phòng đặt 36 tháng và 30 giờ làm việc vẫn có đủ hai tình huống đó trên dữ liệu mẫu.
 */
it('keeps one anonymised and one overdue call whatever the office configured for retention and response time', function () {
    config(['vkcrm.prospect_retention_months' => 36, 'vkcrm.intake_response_hours' => 30]);

    isdSeed();

    $anonymised = IntakeRequest::query()->whereNotNull('anonymised_at')->sole();
    $overdue = IntakeRequest::query()->where('contact_phone_normalized', '84988000011')->sole();

    expect($anonymised->received_at->lessThan(now()->subMonthsNoOverflow(37)))->toBeTrue()
        ->and(FirstResponseClock::fromConfig()->thresholdHours)->toBe(30)
        ->and(FirstResponseClock::fromConfig()->isOverdue($overdue))->toBeTrue();
});
