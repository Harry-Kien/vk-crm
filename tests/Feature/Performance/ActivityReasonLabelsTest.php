<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterActivityRelationManager;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 3 — nhãn tiếng Việt cho `reason` của dòng nhật ký (kế hoạch M13, R9 "Nhãn lý do").
 *
 * Mọi hằng số `*_REASON` dưới `app/Actions` là giá trị `reason` của một dòng `Audit::record()` trong
 * CHÍNH tệp đó; khoá nhãn là `activity.reasons.<sự kiện của dòng đó>.<giá trị>`. Test quét token (bỏ
 * chú thích): tìm `const X_REASON = '…'`, rồi tìm lời gọi `Audit::record('<sự kiện>', …)` mà tham số
 * của nó tham chiếu `::X_REASON`. Một hằng số không đi vào lời gọi nào là lỗi: không biết nó thuộc sự
 * kiện nào thì không biết khoá nhãn của nó.
 *
 * Chiều ngược lại cũng được ghim: mọi khoá trong nhóm `reasons` ứng với một hằng số — không nhãn mồ côi.
 *
 * Modal "Xem chi tiết" (trang Nhật ký hệ thống và tab "Nhật ký" của vụ việc) in nhãn thay mã khi có
 * nhãn, giữ nguyên giá trị khi không có (lý do tự do của `matter_reassigned`, mã chưa có nhãn).
 *
 * Hàm tiện ích mang tiền tố `m13bRl` (làn m13b, tệp này).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

/**
 * Hằng số `*_REASON` của một tệp, kèm giá trị và các sự kiện `Audit::record()` mang nó.
 *
 * @return array<string, array{value: string, events: list<string>}>
 */
function m13bRlReasonConstants(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn (mixed $token): bool => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
    ));
    $count = count($tokens);
    $constants = [];

    // const [type] NAME_REASON = 'value';
    for ($i = 0; $i < $count; $i++) {
        if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_CONST)) {
            continue;
        }

        $name = null;

        for ($j = $i + 1; $j < $count && $tokens[$j] !== '='; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
            }
        }

        $value = $tokens[$j + 1] ?? null;

        if ($name !== null && str_ends_with($name, '_REASON') && is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
            $constants[$name] = ['value' => substr($value[1], 1, -1), 'events' => []];
        }
    }

    // Audit::record('<sự kiện>', …, …::NAME_REASON …)
    for ($i = 0; $i < $count; $i++) {
        $isRecordCall = is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'Audit'
            && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][1] === 'record'
            && ($tokens[$i + 3] ?? null) === '(';

        if (! $isRecordCall) {
            continue;
        }

        $events = [];
        $references = [];
        $depth = 0;
        $firstArgument = true;

        for ($j = $i + 3; $j < $count; $j++) {
            $token = $tokens[$j];

            if (in_array($token, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $firstArgument = false;
            } elseif ($firstArgument && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $events[] = substr($token[1], 1, -1);
            } elseif (is_array($token) && $token[0] === T_DOUBLE_COLON && is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_STRING) {
                $references[] = $tokens[$j + 1][1];
            }
        }

        foreach (array_intersect($references, array_keys($constants)) as $name) {
            $constants[$name]['events'] = array_values(array_unique([...$constants[$name]['events'], ...$events]));
        }
    }

    return $constants;
}

/** @return array<string, array<string, array{value: string, events: list<string>}>> tệp dưới `app/Actions/` => hằng số */
function m13bRlActionReasons(): array
{
    return collect(File::allFiles(app_path('Actions')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->mapWithKeys(fn ($file): array => [
            str_replace('\\', '/', $file->getRelativePathname()) => m13bRlReasonConstants((string) file_get_contents($file->getPathname())),
        ])
        ->filter()
        ->sortKeys()
        ->all();
}

it('has a Vietnamese label for every reason constant under app/Actions, keyed by the event that carries it', function () {
    $problems = [];

    foreach (m13bRlActionReasons() as $file => $constants) {
        foreach ($constants as $name => ['value' => $value, 'events' => $events]) {
            if ($events === []) {
                $problems[] = "{$file}: {$name} không đi vào Audit::record() nào";
            }

            foreach ($events as $event) {
                $key = "activity.reasons.{$event}.{$value}";

                if (! Lang::has($key) || ! is_string(__($key))) {
                    $problems[] = "{$file}: {$name} → thiếu khoá {$key}";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('finds the four reason constants of today, each on its event', function () {
    $pairs = collect(m13bRlActionReasons())
        ->flatMap(fn (array $constants, string $file): array => collect($constants)
            ->flatMap(fn (array $constant, string $name): array => array_map(
                fn (string $event): string => "{$file}::{$name} => {$event}.{$constant['value']}",
                $constant['events'],
            ))->all())
        ->values()
        ->all();

    expect($pairs)->toBe([
        'Deadline/SetDeadlineCompletion.php::REOPEN_HANDOVER_REASON => deadline_responsible_changed.reopened_holder_no_longer_qualifies',
        'Deadline/UpdateDeadline.php::HANDOVER_REASON => deadline_responsible_changed.deadline_updated',
        'Matter/ReassignMatter.php::DEADLINE_HANDOVER_REASON => deadline_responsible_changed.matter_reassigned',
        'Matter/ReassignMatter.php::REQUEST_HANDOVER_REASON => client_request_assigned.matter_reassigned',
    ]);
});

it('keeps no orphan label: every activity.reasons key belongs to a reason constant', function () {
    $fromConstants = collect(m13bRlActionReasons())
        ->flatMap(fn (array $constants): array => collect($constants)
            ->flatMap(fn (array $constant): array => array_map(fn (string $event): string => "{$event}.{$constant['value']}", $constant['events']))
            ->all())
        ->unique()->sort()->values()->all();

    $fromLang = collect(Lang::get('activity.reasons'))
        ->flatMap(fn (array $labels, string $event): array => array_map(fn (string $reason): string => "{$event}.{$reason}", array_keys($labels)))
        ->sort()->values()->all();

    expect($fromLang)->toBe($fromConstants);
});

// =========================================================================================
// Hàm quét trên fixture.
// =========================================================================================

it('maps a reason constant to the event of the Audit::record call that carries it', function () {
    $constants = m13bRlReasonConstants(<<<'PHP'
<?php
class Fixture
{
    public const KEPT_REASON = 'kept';
    public const string TYPED_REASON = 'typed';
    public const UNUSED_REASON = 'unused';
    public const OTHER = 'not_a_reason';

    public function handle($thread, $actor): void
    {
        Audit::record('first_event', $thread, [
            'reason' => self::KEPT_REASON,
            'nested' => ['x' => strtoupper('y')],
        ], causer: $actor);
        Audit::record($flag ? 'second_event' : 'third_event', $thread, ['reason' => static::TYPED_REASON]);
        // Audit::record('commented_event', $thread, ['reason' => self::UNUSED_REASON]);
        Audit::record('fourth_event', $thread, ['note' => 'self::UNUSED_REASON']);
    }
}
PHP);

    expect($constants)->toBe([
        'KEPT_REASON' => ['value' => 'kept', 'events' => ['first_event']],
        'TYPED_REASON' => ['value' => 'typed', 'events' => ['second_event', 'third_event']],
        'UNUSED_REASON' => ['value' => 'unused', 'events' => []],
    ]);
});

// =========================================================================================
// Modal "Xem chi tiết" — qua Livewire.
// =========================================================================================

/** Một dòng `deadline_responsible_changed` thật, ghi bởi lần mở lại có chuyển người. */
function m13bRlReopenHandoverRow(Matter $matter, User $lead): Activity
{
    $former = User::factory()->withRole(Role::Lawyer)->create();
    $matter->addTeamMember($former, MatterRole::Associate);
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $former->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);
    $former->update(['is_active' => false]);

    app(SetDeadlineCompletion::class)->handle($deadline, false, $lead);

    return Activity::query()->where('event', 'deadline_responsible_changed')->sole();
}

function m13bRlModalContent(Testable $component, Activity $row): string
{
    $mounted = $component->mountTableAction('viewProperties', $row)->instance()->getMountedActions();

    expect($mounted)->toHaveCount(1);

    return (string) $mounted[0]->getModalContent();
}

/** Dòng `"reason": "…"` đúng như view in ra (JSON đẹp, qua `{{ }}` của Blade). */
function m13bRlReasonLine(string $value): string
{
    return e('"reason": '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

it('prints the label, not the code, of a reopened_holder_no_longer_qualifies reason on the activity log page', function () {
    $row = m13bRlReopenHandoverRow($this->matter, $this->lead);
    $label = __('activity.reasons.deadline_responsible_changed.reopened_holder_no_longer_qualifies');

    $this->actingAs($this->admin, 'web');
    $content = m13bRlModalContent($this->livewire(ActivityLogPage::class), $row);

    expect($label)->not->toBe('activity.reasons.deadline_responsible_changed.reopened_holder_no_longer_qualifies')
        ->and($content)->toContain(m13bRlReasonLine($label))
        ->and($content)->not->toContain('reopened_holder_no_longer_qualifies');
});

it('prints the same label in the matter activity tab', function () {
    $row = m13bRlReopenHandoverRow($this->matter, $this->lead);

    $this->actingAs($this->lead, 'web');
    $content = m13bRlModalContent($this->livewire(MatterActivityRelationManager::class, [
        'ownerRecord' => $this->matter,
        'pageClass' => ViewMatter::class,
    ]), $row);

    expect($content)->toContain(m13bRlReasonLine(__('activity.reasons.deadline_responsible_changed.reopened_holder_no_longer_qualifies')))
        ->and($content)->not->toContain('reopened_holder_no_longer_qualifies');
});

it('keeps a reason that has no label as it was written: free text, and a code without a label', function () {
    $freeText = Audit::record('matter_reassigned', $this->matter, [
        'from_user_id' => $this->lead->id,
        'reason' => 'Luật sư cũ chuyển công tác',
    ], $this->admin);
    $deadline = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $this->lead->id]);
    $unlabelled = Audit::record('deadline_responsible_changed', $deadline, [
        'from' => $this->lead->id,
        'reason' => 'future_reason_code',
    ], $this->admin);
    $otherEventSameCode = Audit::record('client_request_status_changed', $this->matter, [
        'reason' => 'reopened_holder_no_longer_qualifies',
    ], $this->admin);

    $this->actingAs($this->admin, 'web');

    // Giá trị in ra ĐÚNG như đã ghi — không thành tên khoá dịch, không thành nhãn của sự kiện khác.
    expect(m13bRlModalContent($this->livewire(ActivityLogPage::class), $freeText))->toContain(m13bRlReasonLine('Luật sư cũ chuyển công tác'))
        ->and(m13bRlModalContent($this->livewire(ActivityLogPage::class), $unlabelled))->toContain(m13bRlReasonLine('future_reason_code'))
        // Nhãn theo CẶP sự kiện + lý do: cùng mã dưới một sự kiện khác không mượn nhãn.
        ->and(m13bRlModalContent($this->livewire(ActivityLogPage::class), $otherEventSameCode))->toContain(m13bRlReasonLine('reopened_holder_no_longer_qualifies'));
});
