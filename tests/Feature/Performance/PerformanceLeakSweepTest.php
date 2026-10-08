<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\PerformanceRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Support\ArrayRecord;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * M13 Task 6 — quét rò rỉ vụ `restricted` trên trang "Hiệu suất theo kỳ" (R4, Review Focus 1). Tệp
 * riêng của làn m13b (phán quyết controller: `RestrictedLeakSweepTest` thuộc Task 4 của làn A).
 *
 * Vụ `restricted` của luật sư L mang một bản ghi cho MỌI chỉ số của kỳ: mốc đúng hạn, mốc lỡ, mốc đã gỡ,
 * một lần duyệt giấy tờ, yêu cầu đã trả lời, chưa trả lời và đóng không trả lời, dòng chuyển giai đoạn,
 * vụ kết thúc trong kỳ, khoản thu, và một lĩnh vực riêng. Số trưởng phòng đọc về L — và dòng "Chung", và
 * "Lĩnh vực chính" — phải BẰNG ĐÚNG số khi vụ đó không tồn tại, trên MỌI thuộc tính của `PerformanceRow`
 * (lặp qua tên thuộc tính bằng reflection: một chỉ số thêm sau mà quên điều kiện thì test đỏ). L và admin
 * thấy vụ đó được tính. Trang của trưởng phòng không khác một chữ.
 *
 * Hàm toàn cục mang tiền tố `m13bLs`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Viên']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư L']);
    $this->onlyRestricted = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Chỉ Mật']);
});

/** @return list<string> tên mọi thuộc tính của `PerformanceRow` */
function m13bLsFields(): array
{
    return array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(PerformanceRow::class))->getConstructor()->getParameters(),
    );
}

/** Mọi việc của một kỳ trên một vụ, như các Action ghi (một bản ghi cho mỗi chỉ số). */
function m13bLsWork(Matter $matter, User $lead): void
{
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);
    $deadline = fn (string $due, array $attributes = []) => Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $lead->id, 'due_date' => $due, 'created_at' => '2026-09-01 09:00:00', ...$attributes,
    ]);
    $thread = fn (array $attributes) => ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $clientUser->id, 'created_at' => '2026-09-03 09:00:00', ...$attributes,
    ]);

    $deadline('2026-09-10', ['is_completed' => true, 'completed_at' => '2026-09-10 09:00:00']);
    $deadline('2026-09-11');
    $deadline('2026-09-12', ['deleted_at' => '2026-09-13 10:00:00']);

    $thread(['status' => ClientRequestStatus::Answered, 'answered_at' => '2026-09-03 13:00:00']);
    $thread(['status' => ClientRequestStatus::New]);
    $thread(['status' => ClientRequestStatus::Closed, 'answered_at' => null]);

    $entry = StageLog::factory()->for($matter)->make(['occurred_at' => '2026-09-14', 'from_stage' => 'intake', 'to_stage' => 'collecting_documents']);
    $entry->blameOn($lead);
    $entry->save();

    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create();
    test()->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    Audit::record(ReviewChecklistItem::AUDIT_EVENT, $item, ['matter_id' => $matter->id, 'status' => 'accepted'], causer: $lead);

    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 7_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 7_000_000, 'due_date' => '2026-09-15']);
    Payment::factory()->for($instalment)->create(['amount' => 7_000_000, 'paid_on' => '2026-09-16', 'attributed_lawyer_id' => $lead->id]);

    $matter->forceFill(['closed_at' => '2026-09-25 10:00:00'])->save();
    test()->travelTo(Carbon::parse('2026-10-15 10:00:00'));
}

/** Một vụ `restricted` của `$lead`, ở một lĩnh vực không vụ nào khác có, mang mọi việc của kỳ. */
function m13bLsRestricted(User $lead): Matter
{
    $secret = MatterType::factory()->withStages()->create(['name' => 'Lĩnh vực mật '.$lead->id]);
    $matter = Matter::factory()->restricted()->for($secret)->create(['lead_lawyer_id' => $lead->id]);
    m13bLsWork($matter, $lead);

    return $matter;
}

function m13bLsPage(User $viewer): Testable
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(Performance::class);
}

/** @return array<int|string, array<string, mixed>> */
function m13bLsRows(User $viewer): array
{
    return m13bLsPage($viewer)->instance()->getTableRecords()->all();
}

/** HTML của trang bỏ phần trạng thái Livewire (snapshot, id component ngẫu nhiên) — chỉ còn thứ người xem đọc. */
function m13bLsVisibleHtml(User $viewer): string
{
    $page = m13bLsPage($viewer);
    $html = str_replace($page->id(), 'component-id', $page->html());

    return (string) preg_replace('/\s(?:wire:snapshot|wire:effects)="[^"]*"/', '', $html);
}

/** Dữ liệu thường của L: để "bằng nhau" không phải "bằng nhau vì toàn số 0". */
function m13bLsBaseline(User $lawyer): void
{
    m13bLsWork(Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]), $lawyer);
}

it('gives the manager the same number on every field of every row, the reference row and main practice areas included, with or without the restricted matter', function () {
    m13bLsBaseline($this->lawyer);
    $before = m13bLsRows($this->manager);

    m13bLsRestricted($this->lawyer);
    m13bLsRestricted($this->onlyRestricted);
    $after = m13bLsRows($this->manager);

    $leaks = [];

    foreach ([$this->lawyer->id, $this->onlyRestricted->id, Performance::REFERENCE_KEY] as $key) {
        // Không trường nào của dòng nằm ngoài DTO: mọi thứ trang hiện đều đi qua vòng quét dưới.
        expect(array_values(array_diff(array_keys($after[$key]), [ArrayRecord::getKeyName()])))->toBe(m13bLsFields());

        foreach (m13bLsFields() as $field) {
            if ($after[$key][$field] != $before[$key][$field]) {
                $leaks[] = "{$key}.{$field}";
            }
        }
    }

    expect($before[$this->lawyer->id]['deadlinesMissed'])->toBe(1)
        ->and($before[$this->lawyer->id]['mattersClosed'])->toBe(1)
        ->and($leaks)->toBe([])
        ->and(array_keys($after))->toBe(array_keys($before));
});

it('counts the restricted matter for its lead and for the admin, on every number it carries', function () {
    m13bLsBaseline($this->lawyer);
    $leadBefore = m13bLsRows($this->lawyer)[$this->lawyer->id];
    $adminBefore = m13bLsRows($this->admin)[$this->lawyer->id];

    m13bLsRestricted($this->lawyer);
    $leadAfter = m13bLsRows($this->lawyer)[$this->lawyer->id];
    $adminAfter = m13bLsRows($this->admin)[$this->lawyer->id];

    foreach ([[$leadBefore, $leadAfter], [$adminBefore, $adminAfter]] as [$before, $after]) {
        expect($after['deadlinesOnTime'])->toBe($before['deadlinesOnTime'] + 1)
            ->and($after['deadlinesMissed'])->toBe($before['deadlinesMissed'] + 1)
            ->and($after['deadlinesRemoved'])->toBe($before['deadlinesRemoved'] + 1)
            ->and($after['requestsReceived'])->toBe($before['requestsReceived'] + 2)
            ->and($after['requestsAnswered'])->toBe($before['requestsAnswered'] + 1)
            ->and($after['requestsClosedUnanswered'])->toBe($before['requestsClosedUnanswered'] + 1)
            ->and($after['stageEntries'])->toBe($before['stageEntries'] + 1)
            ->and($after['mattersMoved'])->toBe($before['mattersMoved'] + 1)
            ->and($after['mattersClosed'])->toBe($before['mattersClosed'] + 1)
            ->and($after['itemsReviewed'])->toBe($before['itemsReviewed'] + 1)
            ->and($after['revenueCollected'])->toBe($before['revenueCollected'] + 7_000_000)
            ->and(collect($after['mainPracticeAreas'])->pluck('name')->all())->toContain('Lĩnh vực mật '.$this->lawyer->id);
    }
});

it('shows a lawyer who only leads a restricted matter zeros, never "not applicable", to the manager', function () {
    m13bLsRestricted($this->onlyRestricted);

    $row = m13bLsRows($this->manager)[$this->onlyRestricted->id];

    expect($row['stageEntries'])->toBe(0)
        ->and($row['mattersMoved'])->toBe(0)
        ->and($row['mattersClosed'])->toBe(0)
        ->and($row['revenueCollected'])->toBe(0)
        ->and($row['deadlinesMissed'])->toBe(0)
        ->and($row['mainPracticeAreas'])->toBe([])
        ->and(m13bLsRows($this->onlyRestricted)[$this->onlyRestricted->id]['mattersClosed'])->toBe(1);
});

it('renders the same page for the manager, word for word and row for row, with or without the restricted matters', function () {
    m13bLsBaseline($this->lawyer);
    $before = m13bLsVisibleHtml($this->manager);

    m13bLsRestricted($this->lawyer);
    m13bLsRestricted($this->onlyRestricted);
    $after = m13bLsVisibleHtml($this->manager);

    expect($after)->toBe($before)
        ->and($after)->not->toContain('Lĩnh vực mật');
});
