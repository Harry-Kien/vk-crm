<?php

use App\Actions\Matter\SyncMatterArchive;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\Matter\SyncMatterArchive` (M7 Task 3, phán quyết của chủ
 * nhiệm) — gọi trực tiếp, không qua `TransitionMatterStage`/sự kiện: chuỗi tích hợp đầy đủ (sự
 * kiện chỉ phát khi giai đoạn thật sự đổi, không phát khi rollback) có test riêng ở
 * `tests/Feature/Actions/TransitionMatterStageTest.php`. Tệp này đo đúng luật của chính Action:
 * hai nhánh (đóng/mở lại), tính idempotent, và các cột KHÔNG BAO GIỜ bị chạm.
 */
beforeEach(function () {
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function syncArchive(Matter $matter, ?User $actor = null): ?MatterArchive
{
    return app(SyncMatterArchive::class)->handle($matter->id, $actor);
}

// ---------------------------------------------------------------------------------------------
// Nhánh "đã đóng": tạo hoặc cập nhật, tính từ config chứ không số cứng.
// ---------------------------------------------------------------------------------------------

it('tạo bản ghi lưu trữ khi vụ việc đã đóng, đúng ngày theo cấu hình', function () {
    config(['vkcrm.client_access_days' => 45, 'vkcrm.retention_years' => 7]);

    $closedAt = now()->subDays(2)->startOfDay();
    $this->matter->update(['closed_at' => $closedAt->toDateString()]);

    $archive = syncArchive($this->matter, $this->lawyer);

    expect($archive)->not->toBeNull()
        ->and($archive->matter_id)->toBe($this->matter->id)
        ->and($archive->archived_by)->toBe($this->lawyer->id)
        ->and($archive->archived_at)->not->toBeNull()
        ->and($archive->client_access_until->toDateString())->toBe($closedAt->copy()->addDays(45)->toDateString())
        ->and($archive->retention_until->toDateString())->toBe($closedAt->copy()->addYears(7)->toDateString());
});

/**
 * Cùng test trên, nhưng đổi cấu hình để CHỨNG MINH không có số cứng — hai lần tính ra hai ngày
 * khác nhau cho cùng một `closed_at`.
 */
it('đổi cấu hình thì đổi ngày tính ra, không đọc số cứng', function () {
    $closedAt = now()->subDay()->startOfDay();
    $this->matter->update(['closed_at' => $closedAt->toDateString()]);

    config(['vkcrm.client_access_days' => 30, 'vkcrm.retention_years' => 5]);
    $first = syncArchive($this->matter, $this->lawyer);

    expect($first->client_access_until->toDateString())->toBe($closedAt->copy()->addDays(30)->toDateString())
        ->and($first->retention_until->toDateString())->toBe($closedAt->copy()->addYears(5)->toDateString());

    config(['vkcrm.client_access_days' => 120, 'vkcrm.retention_years' => 15]);
    $second = syncArchive($this->matter->fresh(), $this->lawyer);

    expect($second->client_access_until->toDateString())->toBe($closedAt->copy()->addDays(120)->toDateString())
        ->and($second->retention_until->toDateString())->toBe($closedAt->copy()->addYears(15)->toDateString());
});

it('gọi lại nhiều lần trên cùng một vụ đã đóng chỉ có đúng một dòng lưu trữ', function () {
    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);

    syncArchive($this->matter, $this->lawyer);
    syncArchive($this->matter->fresh(), $this->lawyer);
    syncArchive($this->matter->fresh(), $this->lawyer);

    expect(MatterArchive::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

it('archived_by để trống khi không có actor', function () {
    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);

    $archive = syncArchive($this->matter, null);

    expect($archive->archived_by)->toBeNull();
});

/**
 * Phòng thủ cho dữ liệu cũ/thao tác tay — xem docblock lớp, mục "KHÔNG BAO GIỜ xoá mềm...": mã
 * sản phẩm hôm nay không có đường nào xoá mềm một `MatterArchive`, nên đây là tiền đề chỉ dựng
 * được bằng tay (`->delete()` thẳng trên bản ghi) chứ không bằng một luồng thật.
 */
it('khôi phục một dòng archive đã bị xoá mềm từ trước thay vì tạo dòng mới (nhánh đóng)', function () {
    $this->matter->update(['closed_at' => now()->subDays(10)->toDateString()]);
    $archive = syncArchive($this->matter, $this->lawyer);
    $archiveId = $archive->id;

    $archive->delete();
    expect($archive->fresh()->trashed())->toBeTrue();

    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);
    $resynced = syncArchive($this->matter->fresh(), $this->lawyer);

    expect($resynced->id)->toBe($archiveId)
        ->and($resynced->trashed())->toBeFalse()
        ->and(MatterArchive::query()->withTrashed()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

it('khôi phục một dòng archive đã bị xoá mềm từ trước thay vì bỏ qua (nhánh mở lại)', function () {
    $this->matter->update(['closed_at' => now()->subDays(10)->toDateString()]);
    $archive = syncArchive($this->matter, $this->lawyer);
    $archiveId = $archive->id;

    $archive->delete();

    $this->matter->update(['closed_at' => null]);
    $reopened = syncArchive($this->matter->fresh(), $this->lawyer);

    expect($reopened)->not->toBeNull()
        ->and($reopened->id)->toBe($archiveId)
        ->and($reopened->trashed())->toBeFalse()
        ->and($reopened->client_access_until)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Nhánh "vừa mở lại" (đường bỏ qua của admin xoá closed_at, R8).
// ---------------------------------------------------------------------------------------------

it('mở lại xoá client_access_until về null, giữ nguyên phần còn lại của dòng', function () {
    $this->matter->update(['closed_at' => now()->subDays(5)->toDateString()]);
    $archive = syncArchive($this->matter, $this->lawyer);

    $originalArchivedAt = $archive->archived_at;
    $originalRetentionUntil = $archive->retention_until;

    expect($archive->client_access_until)->not->toBeNull();

    $this->matter->update(['closed_at' => null]);
    $reopened = syncArchive($this->matter->fresh(), $this->lawyer);

    expect($reopened->client_access_until)->toBeNull()
        ->and($reopened->archived_at->toDateTimeString())->toBe($originalArchivedAt->toDateTimeString())
        ->and($reopened->retention_until->toDateString())->toBe($originalRetentionUntil->toDateString())
        ->and(MatterArchive::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

it('mở lại một vụ chưa từng có bản ghi lưu trữ thì không tạo dòng nào', function () {
    expect($this->matter->closed_at)->toBeNull();

    $result = syncArchive($this->matter, $this->lawyer);

    expect($result)->toBeNull()
        ->and(MatterArchive::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

/**
 * Chuỗi đóng → mở lại → đóng lại — đúng chuỗi ràng buộc toàn cục của brief đòi ("Test chuỗi đóng
 * → mở lại → đóng lại, trên cả test:mariadb"). Không lỗi unique (`matter_id` unique tính cả dòng
 * đã xoá mềm trên MariaDB — Action không bao giờ xoá mềm dòng archive nên không chạm ràng buộc
 * đó, nhưng chuỗi này vẫn là bằng chứng trực tiếp nhất).
 */
it('đóng, mở lại, rồi đóng lại: cập nhật đúng dòng cũ, không lỗi trùng khoá', function () {
    $firstClose = now()->subDays(30)->toDateString();
    $this->matter->update(['closed_at' => $firstClose]);
    $archive = syncArchive($this->matter, $this->lawyer);
    $archiveId = $archive->id;

    $this->matter->update(['closed_at' => null]);
    syncArchive($this->matter->fresh(), $this->lawyer);

    $secondClose = now()->subDay()->toDateString();
    $this->matter->update(['closed_at' => $secondClose]);
    $reclosed = syncArchive($this->matter->fresh(), $this->lawyer);

    expect($reclosed->id)->toBe($archiveId)
        ->and($reclosed->client_access_until)->not->toBeNull()
        ->and(MatterArchive::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Không bao giờ chạm tới các cột thuộc Task 4/6.
// ---------------------------------------------------------------------------------------------

it('không bao giờ ghi đè destroyed_at hay các cột quyết định tiêu huỷ', function () {
    $this->matter->update(['closed_at' => now()->subDays(400)->toDateString()]);
    $archive = syncArchive($this->matter, $this->lawyer);

    $destroyer = User::factory()->withRole(Role::Admin)->create();
    $archive->update([
        'destroyed_at' => now(),
        'destruction_reason' => 'Hết hạn lưu theo chính sách.',
        'destruction_record_no' => 'BB-TH-2026-000123',
        'destroyed_by' => $destroyer->id,
    ]);

    syncArchive($this->matter->fresh(), $this->lawyer);

    $fresh = $archive->fresh();

    expect($fresh->destroyed_at)->not->toBeNull()
        ->and($fresh->destruction_reason)->toBe('Hết hạn lưu theo chính sách.')
        ->and($fresh->destruction_record_no)->toBe('BB-TH-2026-000123')
        ->and($fresh->destroyed_by)->toBe($destroyer->id);
});

it('không đụng handover_document_id', function () {
    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);
    $archive = syncArchive($this->matter, $this->lawyer);

    $document = Document::factory()->for($this->matter)->create();
    $archive->update(['handover_document_id' => $document->id]);

    syncArchive($this->matter->fresh(), $this->lawyer);

    expect($archive->fresh()->handover_document_id)->toBe($document->id);
});

// ---------------------------------------------------------------------------------------------
// ClientPortalScope: Action không phụ thuộc guard nào đang mở (xem docblock lớp).
// ---------------------------------------------------------------------------------------------

/**
 * Một nhân sự đang mở cả hai panel trong cùng trình duyệt (cookie phiên dùng chung — cùng bối
 * cảnh mà `OpensChecklistItem`/`SubmitClientDocument` đã phải tự bỏ `ClientPortalScope`). Không
 * `withoutGlobalScope(ClientPortalScope::class)` ở cả hai truy vấn (`Matter`, `MatterArchive`)
 * thì lần đọc dưới khoá trả rỗng/`whereRaw('1=0')`, và Action ném lỗi (đọc `Matter`) hoặc tạo
 * trùng dòng lưu trữ (đọc `MatterArchive`, không thấy dòng cũ nên `create()` lại) thay vì đồng
 * bộ đúng. Gọi HAI LẦN, cố ý: lần đầu chạm nhánh `Matter`, lần hai (dòng archive đã có) mới chạm
 * đúng nhánh `MatterArchive` — gộp một lần gọi sẽ không phân biệt được hai điều kiện.
 */
it('vẫn chạy đúng khi một phiên khách hàng cũng đang xác thực trong cùng request', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    auth('client')->setUser($clientUser);

    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);

    $archive = syncArchive($this->matter, $this->lawyer);
    $again = syncArchive($this->matter->fresh(), $this->lawyer);

    // `withoutGlobalScopes()`: đúng chính `ClientPortalScope` mà test này đang cố tình bật lên
    // (`MatterArchive::applyClientPortalConstraints()` là `whereRaw('1 = 0')`) sẽ làm một câu
    // hỏi trần rỗng — không phải bằng chứng Action đã hỏng, mà là hệ quả TẤT YẾU của việc có một
    // phiên khách đang mở. Bằng chứng thật là `$archive`/`$again` (đọc từ RETURN của Action)
    // không rỗng, và vẫn chỉ đúng MỘT dòng sau hai lần gọi.
    expect($archive)->not->toBeNull()
        ->and($again)->not->toBeNull()
        ->and($again->id)->toBe($archive->id)
        ->and(MatterArchive::query()->withoutGlobalScopes()->where('matter_id', $this->matter->id)->count())->toBe(1);

    auth('client')->forgetUser();
});

// ---------------------------------------------------------------------------------------------
// Khoá dòng matters trước (ràng buộc toàn cục của làn) — không sinh khoá thật trên SQLite, nên
// đây là một khẳng định về HÀNH VI của DB::transaction chứ không phải về khoá thật.
// ---------------------------------------------------------------------------------------------

it('chạy trong một transaction, và một lỗi giữa chừng không để lại dòng archive dở dang', function () {
    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);

    try {
        DB::transaction(function () {
            syncArchive($this->matter->fresh(), $this->lawyer);

            throw new RuntimeException('lỗi giả lập sau khi Action đã ghi trong transaction lồng của chính nó');
        });
    } catch (RuntimeException) {
        // mong đợi
    }

    expect(MatterArchive::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Mô hình: R1 (gói bàn giao là một Document, không phải đường dẫn) và cột quyết định tiêu huỷ.
// ---------------------------------------------------------------------------------------------

it('không còn ghi được handover_package_path qua mass assignment, và có quan hệ tới gói + người tiêu huỷ', function () {
    $archive = new MatterArchive;

    expect($archive->isFillable('handover_package_path'))->toBeFalse()
        ->and($archive->isFillable('handover_document_id'))->toBeTrue()
        ->and($archive->isFillable('destruction_reason'))->toBeTrue()
        ->and($archive->isFillable('destruction_record_no'))->toBeTrue()
        ->and($archive->isFillable('destroyed_by'))->toBeTrue();

    $this->matter->update(['closed_at' => now()->subDay()->toDateString()]);
    $stored = syncArchive($this->matter, $this->lawyer);
    $document = Document::factory()->for($this->matter)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $stored->update(['handover_document_id' => $document->id, 'destroyed_by' => $admin->id]);

    expect($stored->fresh()->handoverDocument->is($document))->toBeTrue()
        ->and($stored->fresh()->destroyer->is($admin))->toBeTrue();
});
