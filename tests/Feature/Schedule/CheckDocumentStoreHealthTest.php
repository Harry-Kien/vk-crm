<?php

use App\Actions\Schedule\CheckDocumentStoreHealth;
use App\Enums\DocumentStoreStatus;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Mail\Staff\DocumentStoreAlert;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Models\SystemHealth;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use App\Support\Storage\TransferDossier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — kiểm tra sức khoẻ kho mỗi giờ (`storage.health`, kế hoạch R5, R9, R10, R13)
|--------------------------------------------------------------------------
|
| Không `Mail::fake()`: thư đi qua đường thật (hàng đợi `sync` của phpunit.xml, transport `array`) để
| nhật ký `outbound_messages` ghi THẬT — luật chống trùng "mỗi loại sự cố một thư mỗi ngày, chỉ tính
| dòng `sent`" đọc chính bảng đó. Drive là máy chủ giả (`Http::fake()` +
| `Http::preventStrayRequests()`).
*/

final class T5HealthClosedTransport implements TransportInterface
{
    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP giả lập chết hẳn (M14 Task 5)');
    }

    public function __toString(): string
    {
        return 't5-closed://';
    }
}

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());

    $this->drive->drive['restrictions']['domainUsersOnly'] = true;

    config([
        'vkcrm.backup.notify_email' => 'van-hanh@luatvukhang.com',
        'vkcrm.storage.office.receipts_path' => null,
    ]);

    $this->travelTo(now()->setTime(10, 20));
    Store::enableRemote(now()->subDays(3));
});

function t5Health(): array
{
    return app(CheckDocumentStoreHealth::class)->handle();
}

/** @return list<string> mẫu thư cảnh báo kho đã ghi nhật ký với trạng thái đã cho */
function t5AlertTemplates(OutboundStatus $status = OutboundStatus::Sent): array
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'like', 'staff.document_store_alert.%')
        ->where('status', $status)
        ->orderBy('id')
        ->pluck('template')
        ->all();
}

it('kho đúng luật: trạng thái ok, ghi lúc kiểm, không thư nào', function () {
    $result = t5Health();

    $health = SystemHealth::current()->fresh();

    expect($result['status'])->toBe(DocumentStoreStatus::Ok)
        ->and($health->document_store_status)->toBe(DocumentStoreStatus::Ok)
        ->and($health->document_store_checked_at->equalTo(now()))->toBeTrue()
        ->and(t5AlertTemplates())->toBe([]);
});

it('thành viên lạ xuất hiện giữa hai lần chạy → misconfigured + MỘT thư sharing_drift; chạy lại cùng ngày không thư thứ hai', function () {
    t5Health();
    expect(t5AlertTemplates())->toBe([]);

    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'user', 'role' => 'writer', 'emailAddress' => 'nguoi-la@ngoai.vn'];

    $this->travel(1)->hours();
    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Misconfigured)
        ->and(SystemHealth::current()->fresh()->document_store_status)->toBe(DocumentStoreStatus::Misconfigured)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.sharing_drift']);

    $this->travel(1)->hours();
    t5Health();

    expect(t5AlertTemplates())->toBe(['staff.document_store_alert.sharing_drift']);

    // Ngày hôm sau, sự cố còn: một thư mới.
    $this->travel(1)->days();
    t5Health();

    expect(t5AlertTemplates())->toHaveCount(2);
});

it('thư mục gốc vào thùng rác → misconfigured + thư sharing_drift', function () {
    $this->drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['trashed'] = true;

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Misconfigured)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.sharing_drift'])
        ->and(SystemHealth::current()->fresh()->document_store_detail)->toContain(__('document_store.root.trashed'));
});

it('chia sẻ chỉ VÀNG (domainUsersOnly tắt, có chủ đích) không phải sự cố', function () {
    $this->drive->drive['restrictions']['domainUsersOnly'] = false;

    expect(t5Health()['status'])->toBe(DocumentStoreStatus::Ok)
        ->and(t5AlertTemplates())->toBe([]);
});

it('nhiều sự cố cùng lúc: trạng thái là loại nặng nhất, mỗi loại một thư', function () {
    Store::media(['created_at' => now()->subHours(3)]);
    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'anyone', 'role' => 'reader'];

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Misconfigured)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.sharing_drift', 'staff.document_store_alert.push_backlog']);
});

it('không người nhận nào (BACKUP_NOTIFY_EMAIL trống, không quản trị viên): ghi cảnh báo vào log, không ném lỗi', function () {
    config(['vkcrm.backup.notify_email' => null]);
    Log::spy();

    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'anyone', 'role' => 'reader'];

    expect(t5Health()['mailed'])->toBe([]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === __('document_store.health.log.no_recipients'))->once();
});

it('thư đi tới người nhận thư sao lưu (ResolveBackupNotificationRecipients)', function () {
    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'anyone', 'role' => 'reader'];

    t5Health();

    $message = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.document_store_alert.sharing_drift')->sole();

    expect($message->recipient)->toBe('van-hanh@luatvukhang.com');
});

it('Drive không tới được → unavailable + thư unavailable', function () {
    $this->drive->failNext('GET', 'drives/', 0, times: 20);

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Unavailable)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.unavailable']);
});

it('Shared Drive không còn (404) → misconfigured + thư misconfigured', function () {
    config(['vkcrm.storage.google_drive.shared_drive_id' => '0ASaiMaDrive']);

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Misconfigured)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.misconfigured']);
});

it('công tắc google_drive mà chưa có mốc → degraded + thư not_enabled', function () {
    Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Degraded)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.not_enabled']);
});

it('tệp mới chờ đẩy quá push_alert_minutes → degraded + thư push_backlog; tệp cũ không tính', function () {
    Store::media(['created_at' => now()->subDays(5)]);
    t5Health();
    expect(t5AlertTemplates())->toBe([]);

    Store::media(['created_at' => now()->subMinutes(config('vkcrm.storage.push_alert_minutes') + 1)]);

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Degraded)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.push_backlog']);
});

it('máy văn phòng đã cấu hình mà biên nhận cũ → thư office_copy_stale; chưa cấu hình thì không thư', function () {
    t5Health();
    expect(t5AlertTemplates())->toBe([]);

    config(['vkcrm.storage.office.receipts_path' => 'gdrive:VK-CRM-backups/office-receipts/test']);
    SystemHealth::current()->forceFill(['last_office_receipt_at' => now()->subHours(37)])->save();

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Degraded)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.office_copy_stale']);
});

it('lỗi biên nhận văn phòng → thư office_copy_error', function () {
    SystemHealth::current()->forceFill(['last_office_receipt_error' => 'Biên nhận bị từ chối.'])->save();

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Degraded)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.office_copy_error']);
});

it('ngày 45 của đồng hồ hồ sơ (production, chưa có ngày hồ sơ) → thư transfer_dossier_due; ngày 44 thì không', function () {
    config(['app.env' => 'production']);
    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-08-01');
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(44)->toIso8601String());

    t5Health();
    expect(t5AlertTemplates())->toBe([]);

    $this->travel(1)->days();
    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Degraded)
        ->and(t5AlertTemplates())->toBe(['staff.document_store_alert.transfer_dossier_due']);

    $this->travel(2)->hours();
    t5Health();
    expect(t5AlertTemplates())->toHaveCount(1);
});

it('đã ghi ngày hồ sơ thì không thư transfer_dossier_due, dù đã quá ngày 45', function () {
    config(['app.env' => 'production']);
    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-08-01');
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String());
    Store::setting(TransferDossier::KEYS['transfer_dossier_on'], '2026-09-20');

    t5Health();

    expect(t5AlertTemplates())->toBe([]);
});

it('ngoài production không có đồng hồ hồ sơ, không thư transfer_dossier_due', function () {
    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-08-01');
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String());

    t5Health();

    expect(t5AlertTemplates())->toBe([]);
});

it('đồng hồ hồ sơ chạy cả khi kho đã quay lui về local (dữ liệu đã từng rời máy chủ)', function () {
    config(['app.env' => 'production', 'vkcrm.storage.driver' => 'local']);
    Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String());

    t5Health();

    expect(t5AlertTemplates())->toBe(['staff.document_store_alert.transfer_dossier_due']);
    Http::assertNothingSent();
});

it('máy chủ thư hỏng → dòng failed, không ném lỗi ra scheduler; lượt sau thử gửi lại', function () {
    config(['mail.mailers.t5_closed' => ['transport' => 't5_closed'], 'mail.default' => 't5_closed']);
    Mail::extend('t5_closed', fn (): TransportInterface => new T5HealthClosedTransport);

    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'anyone', 'role' => 'reader'];

    $result = t5Health();

    expect($result['status'])->toBe(DocumentStoreStatus::Misconfigured)
        ->and(t5AlertTemplates(OutboundStatus::Failed))->toBe(['staff.document_store_alert.sharing_drift'])
        ->and(t5AlertTemplates())->toBe([]);

    $this->travel(1)->hours();
    t5Health();

    expect(t5AlertTemplates(OutboundStatus::Failed))->toHaveCount(2);
});

/**
 * Chỉ dòng `sent` chặn: một dòng `failed` CÓ `sent_at` hôm nay (gán tường minh, dù `markFailed()` không
 * đặt cột đó) vẫn không được tính là "đã báo", để điều kiện `status` không đứng sau điều kiện `sent_at`.
 */
it('dòng failed trong ngày (kể cả có sent_at) không chặn thư', function () {
    OutboundMessage::query()->withoutGlobalScopes()->forceCreate([
        'channel' => OutboundChannel::Email,
        'recipient' => 'van-hanh@luatvukhang.com',
        'template' => 'staff.document_store_alert.sharing_drift',
        'payload' => ['subject' => 'x'],
        'status' => OutboundStatus::Failed,
        'sent_at' => now()->subHour(),
    ]);

    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'anyone', 'role' => 'reader'];

    t5Health();

    expect(t5AlertTemplates())->toBe(['staff.document_store_alert.sharing_drift']);
});

it('kho không dùng (local, không media trên kho, chưa từng bật): không gọi Drive, trạng thái để trống', function () {
    config(['vkcrm.storage.driver' => 'local']);
    Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();

    $result = t5Health();

    expect($result['status'])->toBeNull()
        ->and(SystemHealth::current()->fresh()->document_store_status)->toBeNull()
        ->and(t5AlertTemplates())->toBe([]);

    Http::assertNothingSent();
});

it('local nhưng còn media trên kho: vẫn kiểm Drive (tệp đó vẫn được tải từ kho)', function () {
    config(['vkcrm.storage.driver' => 'local']);
    Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();
    Store::remoteMedia();

    t5Health();

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/permissions')))->not->toBeEmpty();
});

it('thư và dòng sức khoẻ không mang tiêu đề tài liệu, mã hồ sơ, tên khách hay file_id', function () {
    $client = Client::factory()->create(['name' => 'KHACH-DAU-T5']);
    $matter = Matter::factory()->create(['client_id' => $client->id, 'code' => 'HS-DAU-T5', 'title' => 'VU-DAU-T5']);
    Document::factory()->create(['matter_id' => $matter->id, 'title' => 'TAILIEU-DAU-T5']);
    Store::remoteMedia(object: ['file_id' => 'FILEID-DAU-T5']);
    Store::media(['created_at' => now()->subHours(2), 'name' => 'TENTEP-DAU-T5']);

    $this->drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'user', 'role' => 'writer', 'emailAddress' => 'nguoi-la@ngoai.vn'];
    SystemHealth::current()->forceFill(['last_office_receipt_error' => 'loi'])->save();

    t5Health();

    $mails = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages();
    expect($mails)->not->toBeEmpty();

    $haystack = SystemHealth::current()->fresh()->document_store_detail;

    foreach ($mails as $sent) {
        $haystack .= $sent->getOriginalMessage()->toString();
    }

    foreach (['KHACH-DAU-T5', 'HS-DAU-T5', 'VU-DAU-T5', 'TAILIEU-DAU-T5', 'FILEID-DAU-T5', 'TENTEP-DAU-T5'] as $marker) {
        expect($haystack)->not->toContain($marker);
    }
});

it('thư có nhãn tiếng Việt và mẫu thư theo loại sự cố', function (string $kind) {
    $mail = new DocumentStoreAlert($kind, ['count' => 3, 'days_left' => 12], ['van-hanh@luatvukhang.com']);

    $rendered = $mail->render();

    expect($mail->envelope()->subject)->not->toContain('document_store.')
        ->and($rendered)->not->toContain('document_store.')
        ->and($rendered)->toContain('vkcrm:storage:check');
})->with([
    'sharing_drift', 'unavailable', 'misconfigured', 'not_enabled',
    'push_backlog', 'office_copy_stale', 'office_copy_error', 'transfer_dossier_due',
]);

it('thư unavailable gọi đúng tên trang 503 của Task 4 mà người tải tệp trên kho thấy (m6 của m14b, kiểm lại sau gộp)', function () {
    // Gộp m14b vào làn M14: route tải tệp trên kho trả `errors/storage-unavailable` (503) khi kho
    // không trả lời — thư phải gọi trang đó bằng chính tiêu đề của nó, không bằng một câu tự đặt.
    expect(__('document_store.alert.heading.unavailable'))
        ->toContain('"'.__('storage.unavailable_page.heading').'"');
});
