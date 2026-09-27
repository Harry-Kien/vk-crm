<?php

use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ViewOutboundMessage;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

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
