<?php

use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ViewOutboundMessage;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Đọc markup THẬT của một ô cột `status`, cho đúng một dòng — cùng thành ngữ
 * `MatterResourceTest::lastClientUpdateCellHtml()`, vì một lời gọi thẳng
 * `OutboundMessagesTable::statusColor()` không đo được liệu Filament có thật sự VẼ màu ra
 * `->badge()` hay không.
 */
function outboundStatusCellHtml(string $html, OutboundMessage $message): string
{
    $marker = 'table.record.'.$message->getKey().'.column.status';
    $start = strpos($html, $marker);

    expect($start)->not->toBeFalse("Không tìm thấy ô status của dòng {$message->getKey()} trong HTML.");

    $end = strpos($html, '</td>', $start);

    return substr($html, $start, $end - $start);
}

/** Kế toán chỉ có matter.viewAny, không có matter.view/auditLog.view (SPEC §5): 404 cả hai trang. */
it('denies the accountant access to the outbound log with a 404', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $log->id]);

    $this->actingAs($accountant, 'web');

    $this->get(OutboundMessageResource::getUrl('index', panel: 'admin'))->assertNotFound();
    $this->get(OutboundMessageResource::getUrl('view', ['record' => $message], panel: 'admin'))->assertNotFound();
});

/**
 * Test bắt buộc của brief: "Manager thấy một thư failed kèm lý do." — cả trên bảng (màu đỏ) lẫn
 * trang xem (nguyên văn lý do, không cắt).
 */
it('lets a manager see a failed message with its error reason, in red on the table and in full on the view page', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    $failed = OutboundMessage::factory()->create([
        'related_type' => 'stage_log',
        'related_id' => $log->id,
        'status' => OutboundStatus::Failed,
        'error' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: SMTP khong tra loi',
    ]);
    // Cặp dương/âm màu: một dòng ĐÃ gửi đứng cạnh, không được mang màu đỏ.
    $sent = OutboundMessage::factory()->sent()->create([
        'related_type' => 'stage_log',
        'related_id' => $log->id,
    ]);

    $this->actingAs($manager, 'web');

    $html = $this->livewire(ListOutboundMessages::class)->assertCanSeeTableRecords([$failed, $sent])->html();

    expect(outboundStatusCellHtml($html, $failed))->toContain('fi-color-danger')
        ->and(outboundStatusCellHtml($html, $sent))->not->toContain('fi-color-danger');

    $this->livewire(ViewOutboundMessage::class, ['record' => $failed->getKey()])
        ->assertSee('Symfony\\Component\\Mailer\\Exception\\TransportException: SMTP khong tra loi');
});

/**
 * Test bắt buộc: "Manager không thấy dòng của vụ restricted." Cặp dương ngay trên (vụ thường,
 * manager thấy) đã có; đây là nửa âm.
 */
it('hides a restricted matter\'s outbound message from a manager who is not its lead', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $restricted = Matter::factory()->restricted()->create();
    $log = StageLog::factory()->create(['matter_id' => $restricted->id]);
    $message = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $log->id]);

    $this->actingAs($manager, 'web');

    $this->livewire(ListOutboundMessages::class)->assertCanNotSeeTableRecords([$message]);
});

/**
 * Test bắt buộc: "Luật sư không phụ trách không thấy thư của vụ người khác." Cặp dương: luật sư
 * phụ trách thấy đúng thư của vụ MÌNH — trên CÙNG một bảng (hai vụ khác nhau), để chứng minh đây
 * là lọc theo vụ chứ không phải lọc theo vai trò.
 */
it('shows a lawyer only the outbound messages of matters they can view, not another lawyer\'s matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $otherMatter = Matter::factory()->create();

    $ownLog = StageLog::factory()->create(['matter_id' => $ownMatter->id]);
    $ownMessage = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $ownLog->id]);

    $otherLog = StageLog::factory()->create(['matter_id' => $otherMatter->id]);
    $otherMessage = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $otherLog->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertCanSeeTableRecords([$ownMessage])
        ->assertCanNotSeeTableRecords([$otherMessage]);
});

/**
 * `getRecordRouteBindingEloquentQuery()` phải áp cùng `visibleTo()` như `getEloquentQuery()`:
 * nếu không, một luật sư ngoài đội ngũ không thấy dòng trong danh sách vẫn mở được thẳng URL
 * trang xem và đọc được lý do lỗi của vụ việc người đó không được xem (cùng luật
 * `MatterResourceTest`, "returns 404 when opening the view page of a matter outside scope").
 */
it('returns 404 when opening the view page of an outbound message outside the users scope', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $otherMatter = Matter::factory()->create();
    $log = StageLog::factory()->create(['matter_id' => $otherMatter->id]);
    $message = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $log->id]);

    $this->actingAs($outsider, 'web')
        ->get(OutboundMessageResource::getUrl('view', ['record' => $message], panel: 'admin'))
        ->assertNotFound();
});

/**
 * Quyết định của task (SPEC §4.15/§7.4 im lặng): một dòng KHÔNG gắn vụ việc nào (OTP cổng khách,
 * thư nội bộ không về vụ việc) mặc định CHỈ ADMIN xem — xem docblock
 * `OutboundMessage::NO_MATTER_TYPES` / `OutboundMessagePolicy::view()`.
 */
it('shows a message without a related matter only to admin, not to a manager', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $otpMessage = OutboundMessage::factory()->create(['template' => 'client.otp', 'related_type' => null, 'related_id' => null]);

    $this->actingAs($admin, 'web');
    $this->livewire(ListOutboundMessages::class)->assertCanSeeTableRecords([$otpMessage]);

    $this->actingAs($manager, 'web');
    $this->livewire(ListOutboundMessages::class)->assertCanNotSeeTableRecords([$otpMessage]);
});

/**
 * Tab vụ việc: liên kết "Thư đã gửi" phải mở đúng danh sách đã lọc sẵn theo vụ (khoá query string
 * `filters`, xem docblock `ViewMatter::outboundMessagesAction()`), và một mã vụ việc bị GÁN TAY
 * cho một vụ người xem KHÔNG được thấy phải trả về rỗng — không phải một đường vòng qua
 * `visibleTo()`.
 */
it('wires the matter page header action to the outbound log, pre-filtered to that matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $expectedUrl = OutboundMessageResource::getUrl('index', [
        'filters' => ['matter' => ['value' => $ownMatter->getKey()]],
    ], panel: 'admin');

    $this->livewire(ViewMatter::class, ['record' => $ownMatter->getKey()])
        ->assertActionVisible('outboundMessages')
        ->assertActionHasUrl('outboundMessages', $expectedUrl);
});

/**
 * Nửa còn lại của cùng luật: bấm đúng liên kết đó cho thấy đúng thư của vụ mình, và gõ tay mã
 * của một vụ KHÔNG thuộc mình vào cùng khoá lọc thì không lộ gì — bộ lọc chỉ THU HẸP bên trong
 * tập `visibleTo()` đã lọc trước, không phải một đường vòng qua nó.
 */
it('shows only the linked matter\'s messages, and returns nothing for a matter id the viewer cannot see', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $ownLog = StageLog::factory()->create(['matter_id' => $ownMatter->id]);
    $ownMessage = OutboundMessage::factory()->create([
        'related_type' => 'stage_log',
        'related_id' => $ownLog->id,
        'recipient' => 'chinh-chu@vidu.vn',
    ]);

    $otherMatter = Matter::factory()->create();
    $otherLog = StageLog::factory()->create(['matter_id' => $otherMatter->id]);
    $otherMessage = OutboundMessage::factory()->create([
        'related_type' => 'stage_log',
        'related_id' => $otherLog->id,
        'recipient' => 'nguoi-khac@vidu.vn',
    ]);

    $this->actingAs($lawyer, 'web');

    $ownUrl = OutboundMessageResource::getUrl('index', [
        'filters' => ['matter' => ['value' => $ownMatter->getKey()]],
    ], panel: 'admin');

    $this->get($ownUrl)
        ->assertOk()
        ->assertSee('chinh-chu@vidu.vn')
        ->assertDontSee('nguoi-khac@vidu.vn');

    // Crafted: cùng luật sư, gõ tay mã của vụ KHÔNG thuộc mình vào chính khoá lọc đó.
    $craftedUrl = OutboundMessageResource::getUrl('index', [
        'filters' => ['matter' => ['value' => $otherMatter->getKey()]],
    ], panel: 'admin');

    $this->get($craftedUrl)
        ->assertOk()
        ->assertDontSee('nguoi-khac@vidu.vn');
});

// -------------------------------------------------------------------------------------------
// Fix round 1 (opus review of bf0fca4..e8cb7a5)
// -------------------------------------------------------------------------------------------

/**
 * I1: một mốc thời hạn bị xoá mềm (R14, "Xoá một mốc hạn là xoá mềm kèm lý do") không được làm
 * MẤT dòng nhật ký thư nhắc mốc của nó khỏi tầm nhìn của lead — cả trong danh sách (label/link
 * đúng vụ việc, không phải "Không gắn vụ việc nào") lẫn trang xem (200, không 403/404).
 */
it('still shows the lead their deadline_reminder message after the deadline was soft-deleted', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $deadline = Deadline::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create([
        'template' => 'staff.deadline_reminder',
        'related_type' => 'deadline',
        'related_id' => $deadline->id,
    ]);

    $deadline->delete();
    expect(Deadline::query()->whereKey($deadline->getKey())->exists())->toBeFalse(); // xoá mềm.

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertCanSeeTableRecords([$message])
        ->assertSeeHtml($matter->code);

    $this->get(OutboundMessageResource::getUrl('view', ['record' => $message], panel: 'admin'))
        ->assertOk();
});

/** Cặp âm của test trên: một luật sư ngoài đội ngũ vẫn không thấy dòng đó. */
it('still hides the deadline_reminder message of a soft-deleted deadline from an outside lawyer', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();
    $deadline = Deadline::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create([
        'template' => 'staff.deadline_reminder',
        'related_type' => 'deadline',
        'related_id' => $deadline->id,
    ]);

    $deadline->delete();

    $this->actingAs($outsider, 'web');

    $this->livewire(ListOutboundMessages::class)->assertCanNotSeeTableRecords([$message]);
    $this->get(OutboundMessageResource::getUrl('view', ['record' => $message], panel: 'admin'))
        ->assertNotFound();
});

/**
 * Ruling (chủ nhiệm): admin thấy MỌI dòng, kể cả dòng có `related_type` lạ/mồ côi (dữ liệu hỏng,
 * hay một bí danh morph tương lai chưa được khai ở `DIRECT_MATTER_TYPES`) và dòng của một vụ
 * việc đã xoá mềm — nhật ký thư không bao giờ được phép "mất" một dòng trước mắt admin. Manager
 * (không phải admin) vẫn theo đúng `listableBy`, nên không thấy cả hai.
 */
it('shows the admin an orphaned row and a soft-deleted matter\'s row that a manager cannot see', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $orphan = OutboundMessage::factory()->create([
        'related_type' => 'unknown_type_from_the_future',
        'related_id' => 999999,
    ]);

    $deletedMatter = Matter::factory()->create();
    $deletedMatterMessage = OutboundMessage::factory()->create([
        'related_type' => 'matter',
        'related_id' => $deletedMatter->id,
    ]);
    $deletedMatter->delete();

    $this->actingAs($admin, 'web');
    $html = $this->livewire(ListOutboundMessages::class)
        ->assertCanSeeTableRecords([$orphan, $deletedMatterMessage])
        ->html();
    // Nhãn phải đúng mã vụ việc, KHÔNG phải "Không gắn vụ việc nào" — đo đúng
    // `relatedMatter()` tìm được vụ việc đã xoá mềm (`Matter::query()->withTrashed()`), không
    // chỉ đo việc admin qua được Gate nhờ nhánh "không có vụ việc → admin" (nhánh đó cũng trả
    // `true` cho admin, nên riêng `assertOk()` ở trang xem không tự phân biệt được hai lý do).
    expect($html)->toContain($deletedMatter->code);
    $this->get(OutboundMessageResource::getUrl('view', ['record' => $orphan], panel: 'admin'))->assertOk();
    $this->get(OutboundMessageResource::getUrl('view', ['record' => $deletedMatterMessage], panel: 'admin'))
        ->assertOk()
        ->assertSee($deletedMatter->code);

    $this->actingAs($manager, 'web');
    $this->livewire(ListOutboundMessages::class)
        ->assertCanNotSeeTableRecords([$orphan, $deletedMatterMessage]);
});

/**
 * I2: bộ lọc "vụ việc" của bảng phải tự lọc theo `listableBy()`, không liệt kê mọi vụ việc của
 * văn phòng — dropdown là một nơi rò rỉ mã/tiêu đề vụ việc CÒN TRƯỚC KHI người dùng bấm lọc.
 * Không tạo dòng nào gắn với hai vụ việc dưới đây: mã vụ việc chỉ có thể xuất hiện trên trang
 * qua chính ô chọn của bộ lọc, không lẫn với cột "Bản ghi liên quan" của bảng.
 */
it('scopes the matter filter dropdown to a lawyer\'s own matters, excluding another lawyer\'s', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $otherMatter = Matter::factory()->create();

    $this->actingAs($lawyer, 'web');

    $html = $this->livewire(ListOutboundMessages::class)->html();

    expect($html)->toContain($ownMatter->code)
        ->not->toContain($otherMatter->code);
});

/** Nửa còn lại: một vụ `restricted` không hiện trong dropdown của manager (không phải lead). */
it('scopes the matter filter dropdown to a manager\'s visible matters, excluding a restricted one', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normalMatter = Matter::factory()->create();
    $restrictedMatter = Matter::factory()->restricted()->create();

    $this->actingAs($manager, 'web');

    $html = $this->livewire(ListOutboundMessages::class)->html();

    expect($html)->toContain($normalMatter->code)
        ->not->toContain($restrictedMatter->code);
});

/**
 * Minor: bí danh `deadline` chưa có test riêng nào trước fix round 1 (chỉ `stage_log` được
 * dùng) — cùng cơ chế `DIRECT_MATTER_TYPES` nhưng đo trên một bí danh khác, kèm cặp âm
 * `restricted`.
 */
it('shows the lead a staff.deadline_reminder row, and hides a restricted matter\'s from a manager', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $deadline = Deadline::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create([
        'template' => 'staff.deadline_reminder',
        'related_type' => 'deadline',
        'related_id' => $deadline->id,
    ]);

    $this->actingAs($lawyer, 'web');
    $this->livewire(ListOutboundMessages::class)->assertCanSeeTableRecords([$message]);

    $manager = User::factory()->withRole(Role::Manager)->create();
    $restrictedMatter = Matter::factory()->restricted()->create();
    $restrictedDeadline = Deadline::factory()->create(['matter_id' => $restrictedMatter->id]);
    $restrictedMessage = OutboundMessage::factory()->create([
        'template' => 'staff.deadline_reminder',
        'related_type' => 'deadline',
        'related_id' => $restrictedDeadline->id,
    ]);

    $this->actingAs($manager, 'web');
    $this->livewire(ListOutboundMessages::class)->assertCanNotSeeTableRecords([$restrictedMessage]);
});

/**
 * Minor: kế toán phải bị chặn ngay ở SCOPE (`OutboundMessage::scopeVisibleTo()`), không chỉ ở
 * `OutboundMessagePolicy::viewAny()` — trước fix round 1, `Matter::listableBy($accountant)` trả
 * về TOÀN BỘ vụ việc thường (kế toán có `matter.viewAny`), nên nếu policy từng bị nới lỏng ở một
 * chỗ khác (hay scope này được gọi từ một nơi không qua policy), kế toán vẫn đọc được nhật ký
 * thư của mọi vụ việc thường. Test thẳng vào scope, không qua trang, để đo đúng lớp phòng thủ
 * này — độc lập với `OutboundMessagePolicy`.
 */
it('excludes the accountant in the query scope itself, independently of the page policy', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $log->id]);

    expect(OutboundMessage::query()->visibleTo($accountant)->whereKey($message->getKey())->exists())->toBeFalse();
});

// -------------------------------------------------------------------------------------------
// Fix round 2 (vấn đề còn sót lại từ rà soát của fix round 1)
// -------------------------------------------------------------------------------------------

/**
 * `OutboundMessagePolicy::view()` phải TỰ đồng ý với `OutboundMessage::scopeVisibleTo()`, không
 * được phép trông vào việc route binding của `OutboundMessageResource` đã chặn từ trước để che
 * một chỗ lệch của chính nó. `relatedMatter()` (fix round 1) đọc vụ việc bằng `withTrashed()`,
 * nên `$matter` có thể là một vụ ĐÃ XOÁ MỀM — và `MatterPolicy::view()` CỐ Ý cho vụ xoá mềm lọt
 * qua (để admin còn thao tác được). Gọi thẳng `Gate::forUser($manager)->allows('view', $row)`
 * (không qua HTTP/route binding, như một Action hay lệnh console tương lai sẽ làm) phải vẫn ra
 * đúng câu trả lời: manager (không phải admin) bị từ chối, admin thì không.
 */
it('denies Gate view for a manager on a trashed-matter row, directly (not through route binding)', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    $message = OutboundMessage::factory()->create(['related_type' => 'stage_log', 'related_id' => $log->id]);

    $matter->delete();

    expect(Gate::forUser($manager)->allows('view', $message))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $message))->toBeTrue();
});
