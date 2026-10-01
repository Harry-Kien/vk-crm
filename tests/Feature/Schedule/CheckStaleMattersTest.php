<?php

use App\Actions\Schedule\CheckStaleMatters;
use App\Enums\Confidentiality;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Jobs\SendStaleMatterMail;
use App\Mail\Staff\StaleMatterReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\StaleMatterAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * SPEC §6.4 — hồ sơ quá hạn cập nhật cho khách. 14 ngày: thông báo trong hệ thống cho luật sư phụ
 * trách ({@see StaleMatterAlert}). 21 ngày: thư `staff.stale_matter` cho luật sư phụ trách +
 * mọi manager xem được vụ ({@see StaleMatterReminder}, gửi bởi {@see SendStaleMatterMail}).
 *
 * "Dùng CÙNG một định nghĩa 'quá hạn cập nhật' với `StaleMattersWidget`/cột tô màu của
 * `MattersTable`" (task-7-brief.md) không được đo lại ở đây bằng một truy vấn thứ hai: các test
 * "không published"/"đã đóng"/"chưa từng cập nhật" dưới đây ghim ĐÚNG BA điều kiện mà
 * `tests/Feature/Filament/StaleMattersWidgetTest.php` đã ghim cho `App\Support\
 * MatterStaleness::scopeStale()` — vì `CheckStaleMatters::handle()` gọi THẲNG hàm đó, một test đỏ
 * ở đây tự động nghĩa là widget cũng sẽ đỏ theo, và ngược lại.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function staleMatter(int $daysSinceUpdate, array $overrides = []): Matter
{
    if (! array_key_exists('lead_lawyer_id', $overrides)) {
        $overrides['lead_lawyer_id'] = User::factory()->withRole(Role::Lawyer)->create()->id;
    }

    return Matter::factory()->create(array_merge([
        'last_client_update_at' => now()->subDays($daysSinceUpdate),
    ], $overrides));
}

// ---------------------------------------------------------------------------------------------
// Mốc 14 ngày — thông báo trong hệ thống
// ---------------------------------------------------------------------------------------------

it('notifies the lead lawyer in-app after 14 days without a client update', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(15, ['lead_lawyer_id' => $lawyer->id]);

    $result = (new CheckStaleMatters)->handle();

    $lawyer->refresh();
    expect($lawyer->notifications)->toHaveCount(1)
        ->and($lawyer->notifications->first()->type)->toBe(StaleMatterAlert::class)
        ->and($lawyer->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id)
        ->and($result['notified'])->toBe(1);
});

/**
 * Mutation probe: xoá điều kiện `$daysSinceUpdate > self::DANGER_AFTER_DAYS` (14) khỏi
 * `MatterStaleness::scopeStale()`/`olderThan()` — test này ĐỎ (lawyer nhận thông báo dù mới cập
 * nhật 5 ngày trước).
 */
it('does not notify a matter that was updated within the last 14 days', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    staleMatter(5, ['lead_lawyer_id' => $lawyer->id]);

    $result = (new CheckStaleMatters)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($result['notified'])->toBe(0);
});

/** SPEC §6.4 đòi `is_published_to_portal = true` — cùng điều kiện StaleMattersWidgetTest ghim. */
it('does not notify or mail a matter that is not published to the portal', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    User::factory()->withRole(Role::Manager)->create();
    Matter::factory()->unpublished()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(30),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($result)->toBe(['notified' => 0, 'mailed' => 0]);
    Mail::assertNothingSent();
});

/** R8 (M6.5): vụ đã đóng không còn "việc dở dang" — cùng Matter::scopeOpen() mà scopeStale() dùng. */
it('does not notify or mail a closed matter', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);
    $matter->update(['closed_at' => now()->subDay()]);

    $result = (new CheckStaleMatters)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0]);
    Mail::assertNothingSent();
});

/**
 * SPEC §6.4 không nói rõ đồng hồ tính từ đâu khi CHƯA từng có `last_client_update_at` —
 * `MatterStaleness` chọn `stage_entered_at` (đúng lựa chọn `StaleMattersWidgetTest` đã ghim).
 */
it('uses stage_entered_at as the clock for a matter that has never been updated for the client', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => null,
        'stage_entered_at' => now()->subDays(20),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['notified'])->toBe(1)
        ->and($lawyer->fresh()->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id);
});

/**
 * R4 của kế hoạch M6: chạy Action hai lần liên tiếp không sinh thêm thông báo — chống lặp bằng
 * bảng `notifications` (khoá người nhận + matter_id + đợt), không thêm cột.
 *
 * Mutation probe: xoá `if ($this->alreadyNotified(...)) { continue; }` khỏi
 * `CheckStaleMatters::processOne()` — test này ĐỎ (2 thông báo thay vì 1).
 */
it('does not send a second notice on a second consecutive run', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    staleMatter(15, ['lead_lawyer_id' => $lawyer->id]);

    (new CheckStaleMatters)->handle();
    (new CheckStaleMatters)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(1);
});

/**
 * R5 — "đợt đình trệ" bắt đầu ở đồng hồ COALESCE(...) HIỆN TẠI: một cập nhật khách hàng dời đồng
 * hồ này tới, nên một đợt MỚI không bị chặn bởi thông báo của đợt CŨ.
 *
 * Mutation probe: xoá điều kiện `created_at >= episodeStart` khỏi
 * `CheckStaleMatters::alreadyNotified()` — test này ĐỎ (chỉ còn 1 thông báo, đợt mới bị chặn
 * nhầm bởi đợt cũ).
 */
it('sends a fresh notice once a new client update reopens the episode', function () {
    Mail::fake();
    $this->travelTo(now()->startOfDay());
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(15, ['lead_lawyer_id' => $lawyer->id]);

    (new CheckStaleMatters)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(1);

    // Đợt cũ kết thúc: khách vừa được cập nhật, đồng hồ dời tới HÔM NAY.
    $this->travelTo(now()->addDays(9));
    $matter->update(['last_client_update_at' => now()]);
    (new CheckStaleMatters)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(1); // vẫn 1: vụ không còn quá hạn.

    // 15 ngày sau lần cập nhật đó: đợt MỚI đã quá hạn — thông báo phải được gửi lại.
    $this->travelTo(now()->addDays(15));
    (new CheckStaleMatters)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(2);
});

/**
 * R3 (M6.5 Task 8) — "không bao giờ im lặng": luật sư phụ trách bị vô hiệu hoá thì
 * ResolveStaffRecipients tự thế chỗ bằng luật sư khác/manager/admin, không bỏ trống.
 */
it('falls back down the recipient chain when the lead lawyer is deactivated', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = staleMatter(15, ['lead_lawyer_id' => $lawyer->id]);

    (new CheckStaleMatters)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($manager->fresh()->notifications)->toHaveCount(1)
        ->and($manager->fresh()->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id);
});

// ---------------------------------------------------------------------------------------------
// Mốc 21 ngày — thư staff.stale_matter
// ---------------------------------------------------------------------------------------------

/**
 * Mutation probe: đổi điều kiện `MatterStaleness::olderThan($matter, EMAIL_AFTER_DAYS)` thành
 * `true` (bỏ ngưỡng) khỏi `CheckStaleMatters::processOne()` — test này ĐỎ (thư gửi ở ngày 15).
 */
it('does not mail before 21 days, even though it already notices at 14', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    staleMatter(15, ['lead_lawyer_id' => $lawyer->id]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['notified'])->toBe(1)
        ->and($result['mailed'])->toBe(0);
    Mail::assertNothingSent();
});

it('mails the lead lawyer and every manager who can view the matter after 21 days', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = staleMatter(22, ['lead_lawyer_id' => $lawyer->id]);

    $result = (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, 2);
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email) && $mail->matter->is($matter));
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($manager->email));
    expect($result['mailed'])->toBe(1);
});

/**
 * R3 (M6.5): "đồng gửi mọi manager" đọc là "mọi manager xem được vụ" — một vụ `restricted` thay
 * manager bằng admin (ResolveStaffRecipients::supervisorsFor()), và R6 (Review Focus 1) — manager
 * không xem được vụ thì không được lộ mã/tiêu đề qua thư.
 */
it('cc admin instead of manager for a restricted matter, and never mails a manager who cannot view it', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(22),
    ]);

    (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($admin->email));
    Mail::assertNotSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($manager->email));
});

/**
 * R5 của kế hoạch M6: "không quá một thư mỗi 7 ngày trong lúc còn đình trệ" — tra
 * `outbound_messages`, không thêm cột.
 *
 * Mutation probe: xoá điều kiện `! $this->recentlyMailed(...)` khỏi
 * `CheckStaleMatters::processOne()` — test này ĐỎ (thư gửi lại dù mới 3 ngày trước).
 */
it('does not mail again within 7 days of the last successful send', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDays(3),
    ]);

    $result = (new CheckStaleMatters)->handle();

    Mail::assertNothingSent();
    // `mailed` phải là 0, không chỉ "không ai thật sự nhận thư": nếu chỉ đo qua Mail::fake(), lớp
    // chống trùng THỨ HAI ở SendStaleMatterMail::alreadyDelivered() (cùng tiêu chí 7 ngày, cho một
    // người nhận) sẽ tự che mất một lần dispatch thừa ở CẤP ACTION — ghim luôn bộ đếm để mutation
    // probe của điều kiện `! $this->recentlyMailed(...)` không bị lớp phòng thủ thứ hai che mất.
    expect($result['mailed'])->toBe(0);
});

it('mails again once 7 days have passed since the last successful send, while still stale', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDays(8),
    ]);

    (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * R5 "7 ngày một lần" với lịch THẬT: `stale-matters.check` chạy đúng 07:30 mỗi ngày, còn `sent_at`
 * được đóng dấu lúc worker `queue:work --stop-when-empty` thật sự gửi — LUÔN muộn hơn lượt chạy đã
 * xếp job đó vài chục giây. Với `sent_at >= now()->subDays(7)` một thư gửi 07:30:40 ngày D vẫn nằm
 * TRONG cửa sổ ở lượt 07:30:02 ngày D+7 → thư trôi sang D+8 (chu kỳ 8 ngày). So theo NGÀY LỊCH
 * (MatterStaleness::mailWindowStart()) thì thư ngày D không còn tính từ D+7.
 *
 * Mutation probe: đổi `MatterStaleness::mailWindowStart()` về `now()->subDays(7)` — test này ĐỎ
 * (không gửi vì dòng sổ 07:30:40 còn trong cửa sổ).
 */
it('mails again on the 7th day even though the previous send was stamped seconds after that morning run', function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-14 07:30:02'));
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(40, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        // Lượt chạy D = 2026-10-07 07:30:02, worker gửi lúc 07:30:40.
        'sent_at' => now()->subDays(7)->addSeconds(38),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['mailed'])->toBe(1);
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

it('mails again when the previous send was 7 days ago plus one minute', function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-14 09:00:00'));
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(40, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDays(7)->addMinute(),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['mailed'])->toBe(1);
});

/**
 * Cặp âm của hai test trên: một thư gửi ngày lịch D+1 (6 ngày trước) vẫn CHẶN — cửa sổ theo ngày
 * lịch không được nới quá một ngày.
 *
 * Mutation probe: thu hẹp `mailWindowStart()` còn `today()->subDays(5)` — test này ĐỎ (gửi lại sau 6
 * ngày); nới ra `today()->subDays(7)` thì hai test trên ĐỎ.
 */
it('still holds back when the previous send was on the calendar day 6 days ago', function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-14 07:30:02'));
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(40, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => Carbon::parse('2026-10-08 00:00:05'),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['mailed'])->toBe(0);
    Mail::assertNothingSent();
});

/**
 * R1 của kế hoạch M6, hệ quả ghi trong docblock RecordOutboundMessage: một thư THẤT BẠI vẫn là
 * một dòng, nhưng KHÔNG được tính là "đã nhắc" — đếm cả dòng `failed` biến một lần gửi hỏng thành
 * một lần im lặng không gửi lại.
 *
 * `sent_at` gán TƯỜNG MINH dù `RecordOutboundMessage::markFailed()` thật sự không bao giờ đụng
 * tới cột đó (một dòng `failed` thật luôn có `sent_at = null`, thứ mà điều kiện `sent_at >= ...`
 * BÊN CẠNH đã tự loại): gán nó ở đây để phép thử này đo ĐÚNG một mình điều kiện `status = sent`,
 * không để điều kiện `sent_at` kia vô tình che mất một lỗi tương lai (thời điểm `markFailed()` có
 * thể đổi cách viết).
 *
 * Mutation probe: xoá `->where('status', OutboundStatus::Sent)` khỏi
 * `CheckStaleMatters::recentlyMailed()` — test này ĐỎ (không gửi lại, vì dòng `failed` bị đếm
 * nhầm là "đã nhắc").
 */
it('does not let a failed send count as already reminded', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Failed,
        'error' => 'Thử nghiệm',
        'sent_at' => now()->subDays(3),
    ]);

    (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

// ---------------------------------------------------------------------------------------------
// R4 — chạy Action hai lần liên tiếp không sinh thêm thư/thông báo (không Mail::fake(), cần
// outbound_messages ghi THẬT qua cánh cửa framework — xem docblock tests/Feature/Mail/OutboundLedgerTest.php).
// ---------------------------------------------------------------------------------------------

it('running the action twice in a row sends exactly one notice and one mail, not two', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    $first = (new CheckStaleMatters)->handle();
    $second = (new CheckStaleMatters)->handle();

    expect($first['notified'])->toBe(1)
        ->and($first['mailed'])->toBe(1)
        ->and($second['notified'])->toBe(0)
        ->and($second['mailed'])->toBe(0)
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);

    $sent = OutboundMessage::query()->withoutGlobalScopes()
        ->where('related_type', $matter->getMorphClass())
        ->where('related_id', $matter->getKey())
        ->where('template', 'staff.stale_matter')
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sent)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// MỘT định nghĩa "quá hạn cập nhật": widget, cột tô màu của danh sách, và Action này (task-7-brief)
// ---------------------------------------------------------------------------------------------

/** Ô `last_client_update_at` của một dòng trong HTML đã render của `ListMatters`. */
function staleParityCellHtml(string $html, Matter $matter): string
{
    $marker = 'table.record.'.$matter->getKey().'.column.last_client_update_at';
    $start = strpos($html, $marker);

    expect($start)->not->toBeFalse("Không tìm thấy ô last_client_update_at của vụ việc {$matter->getKey()}.");

    return substr($html, $start, strpos($html, '</td>', $start) - $start);
}

/**
 * Ba bề mặt, MỘT câu trả lời — đo ở các tuổi LẺ (giờ, không tròn ngày) quanh từng ngưỡng, vì đó là
 * chỗ hai định nghĩa lệch nhau lặng lẽ: `diffInDays()` của Carbon 3 trả SỐ THỰC, còn `scopeStale()`
 * so thẳng thời điểm bằng SQL. Một bản `color()`/`olderThan()` cắt cụt về số nguyên sẽ coi vụ 14 ngày
 * 1 giờ là "chưa quá 14 ngày" trong khi widget vẫn liệt kê nó và job vẫn nhắc.
 *
 * Mỗi hàng: [số giờ kể từ cập nhật gần nhất, màu ô danh sách, có trong widget, có thông báo 14
 * ngày, có thư 21 ngày].
 */
dataset('stale definition boundaries', [
    '5 ngày 12 giờ' => [132, null, false, false, false],
    '10 ngày 12 giờ' => [252, 'warning', false, false, false],
    '13 ngày 22 giờ' => [334, 'warning', false, false, false],
    '14 ngày 1 giờ' => [337, 'danger', true, true, false],
    '20 ngày 23 giờ' => [503, 'danger', true, true, false],
    '21 ngày 1 giờ' => [505, 'danger', true, true, true],
]);

it('gives the widget, the list column and the job the same answer at every boundary', function (
    int $hours,
    ?string $color,
    bool $inWidget,
    bool $notified,
    bool $mailed,
) {
    Mail::fake();
    Filament::setCurrentPanel('admin');
    $this->travelTo(now()->startOfSecond());

    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subHours($hours),
    ]);

    $this->actingAs($admin, 'web');

    $widget = $this->livewire(StaleMattersWidget::class);
    $inWidget ? $widget->assertCanSeeTableRecords([$matter]) : $widget->assertCanNotSeeTableRecords([$matter]);

    $cell = staleParityCellHtml($this->livewire(ListMatters::class)->html(), $matter);
    foreach (['warning', 'danger'] as $candidate) {
        $color === $candidate ? expect($cell)->toContain('fi-color-'.$candidate) : expect($cell)->not->toContain('fi-color-'.$candidate);
    }

    $result = (new CheckStaleMatters)->handle();

    expect($result['notified'])->toBe($notified ? 1 : 0)
        ->and($lawyer->fresh()->notifications)->toHaveCount($notified ? 1 : 0)
        ->and($result['mailed'])->toBe($mailed ? 1 : 0);
})->with('stale definition boundaries');

// ---------------------------------------------------------------------------------------------
// Vụ đã xoá mềm, vụ restricted, người nhận bị thế chỗ
// ---------------------------------------------------------------------------------------------

/** Cặp âm của "notifies the lead lawyer in-app after 14 days": `Matter::open()` loại vụ xoá mềm. */
it('does not notify or mail a soft-deleted matter', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    User::factory()->withRole(Role::Manager)->create();
    $matter = staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);
    $matter->delete();

    $result = (new CheckStaleMatters)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Mail::assertNothingSent();
});

/**
 * Review Focus 1: thông báo 14 ngày của vụ `restricted` không tới manager không xem được vụ. Luật
 * sư phụ trách bị vô hiệu hoá nên chuỗi dự phòng của ResolveStaffRecipients thế chỗ — manager rớt
 * (Gate::view), admin nhận, và CHỈ admin thấy mã hồ sơ trong thân thông báo.
 */
it('sends the 14-day notice of a restricted matter to the admin, never to a manager who cannot view it', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create([
        'confidentiality' => Confidentiality::Restricted,
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(15),
    ]);

    (new CheckStaleMatters)->handle();

    expect($manager->fresh()->notifications)->toHaveCount(0)
        ->and($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($admin->fresh()->notifications)->toHaveCount(1)
        ->and($admin->fresh()->notifications->first()->data['body'])->toContain($matter->code);
});

/** Thư 21 ngày: luật sư phụ trách bị vô hiệu hoá thì KHÔNG nhận thư; manager nhận đúng một bản. */
it('does not mail a deactivated lead lawyer, and mails the manager who replaces them exactly once', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, 1);
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($manager->email));
    Mail::assertNotSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/** Số ngày trong thân thư là số NGUYÊN, không phải "22.2083" của một phép trừ thời điểm. */
it('says a whole number of days in the mail body', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(22)->subHours(5),
    ]);

    (new CheckStaleMatters)->handle();

    Mail::assertSent(StaleMatterReminder::class, function ($mail) {
        $mail->assertSeeInHtml('đã 22 ngày')->assertSeeInText('đã 22 ngày');

        return true;
    });
});

// ---------------------------------------------------------------------------------------------
// R4 với hàng đợi THẬT: hai lần chạy trước khi ai rút hàng đợi
// ---------------------------------------------------------------------------------------------

/**
 * Cron gọi trùng (hoặc quản trị viên chạy tay) xảy ra TRƯỚC khi `queue:work` rút xong: sổ thư còn
 * trống nên lần chạy thứ hai vẫn xếp thêm một job. Lớp chống trùng thật là job — nó tra
 * `outbound_messages` ngay trước MỖI thư, mà sổ này được ghi đồng bộ bởi transport — nên khi hai
 * job chạy nối nhau mỗi người vẫn chỉ nhận một thư.
 *
 * Mutation probe: xoá `if ($this->alreadyDelivered(...)) { continue; }` khỏi
 * `SendStaleMatterMail::handle()` — test này ĐỎ (2 thư mỗi người).
 */
it('sends each recipient one mail even when the action ran twice before the queue was drained', function () {
    Queue::fake([SendStaleMatterMail::class]);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    (new CheckStaleMatters)->handle();
    (new CheckStaleMatters)->handle();

    $jobs = Queue::pushed(SendStaleMatterMail::class);
    expect($jobs)->toHaveCount(2);

    foreach ($jobs as $job) {
        $job->handle();
    }

    foreach ([$lawyer, $manager] as $recipient) {
        $count = OutboundMessage::query()->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', 'staff.stale_matter')
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->count();

        expect($count)->toBe(1);
    }
});

// ---------------------------------------------------------------------------------------------
// Danh sách ứng viên dựng TRƯỚC vòng lặp; điều kiện phải được đọc lại sau khi khoá dòng
// ---------------------------------------------------------------------------------------------

/**
 * Chạy `$change` ĐÚNG MỘT lần, ngay sau truy vấn dựng danh sách ứng viên (`select id from matters`)
 * — tức trong khoảng giữa `pluck('id')` và `lockForUpdate()` của `processOne()`, nơi một giao dịch
 * khác (luật sư đóng vụ, khách được cập nhật) chen vào được trên máy chủ thật.
 */
function afterCandidateListIsBuilt(Closure $change): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $change) {
        if (! $fired && preg_match('/^select [`"]id[`"] from [`"]matters[`"]/i', $query->sql) === 1) {
            $fired = true;
            $change();
        }
    });
}

/**
 * Mutation probe: xoá khối `if (! MatterStaleness::scopeStale(...)->exists()) { return; }` khỏi
 * `CheckStaleMatters::processOne()` — hai test này ĐỎ (thông báo/thư vẫn tới cho vụ vừa đóng, vụ
 * vừa được cập nhật cho khách).
 */
it('skips a matter that was closed after the candidate list was built', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);

    afterCandidateListIsBuilt(fn () => DB::table('matters')->where('id', $matter->id)->update(['closed_at' => now()]));

    $result = (new CheckStaleMatters)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Mail::assertNothingSent();
});

it('skips a matter that was updated for the client after the candidate list was built', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);

    afterCandidateListIsBuilt(fn () => DB::table('matters')->where('id', $matter->id)->update(['last_client_update_at' => now()]));

    $result = (new CheckStaleMatters)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Mail::assertNothingSent();
});

/** Cặp dương của hai test trên: cùng cơ chế nghe truy vấn, nhưng không đổi gì — vụ vẫn được nhắc. */
it('still handles the matter when nothing changes after the candidate list was built', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    staleMatter(30, ['lead_lawyer_id' => $lawyer->id]);

    afterCandidateListIsBuilt(fn () => null);

    $result = (new CheckStaleMatters)->handle();

    expect($result)->toBe(['notified' => 1, 'mailed' => 1]);
});

/**
 * Thân thông báo trong chuông được Filament vẽ THÔ (chỉ qua `sanitizeHtml`, vẫn giữ `<a href>`,
 * `<img>`) — một tiêu đề vụ việc do nhân sự gõ tự do không được biến thành một liên kết lừa đảo
 * trong chuông của luật sư khác (cùng lỗi Task 4 fix round 1, C1).
 *
 * Mutation probe: bỏ `e()` khỏi `title` trong `StaleMatterAlert::toDatabase()` — test này ĐỎ.
 */
it('escapes a client-typed matter title in the 14-day notice body', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    staleMatter(15, [
        'lead_lawyer_id' => $lawyer->id,
        'title' => 'Tranh chấp <a href="https://lua-dao.test">bấm vào đây</a>',
    ]);

    (new CheckStaleMatters)->handle();

    $body = $lawyer->fresh()->notifications->first()->data['body'];

    expect($body)->not->toContain('<a href')
        ->and($body)->toContain('&lt;a href');
});

/**
 * Chỉ thư CÙNG MẪU mới tính là "đã nhắc" ở tầng Action: một thư khác đã gửi về cùng vụ việc (ví dụ
 * thông báo khách gửi yêu cầu mới) không được nuốt lời nhắc hồ sơ quá hạn.
 *
 * Mutation probe: xoá `->where('template', 'staff.stale_matter')` khỏi
 * `CheckStaleMatters::recentlyMailed()` — test này ĐỎ (không có thư nào được xếp hàng).
 */
it('is not silenced by a sent mail of another template about the same matter', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staleMatter(25, ['lead_lawyer_id' => $lawyer->id]);

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.new_client_request',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDay(),
    ]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['mailed'])->toBe(1);
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});
