<?php

namespace App\Enums;

use App\Actions\Push\SendPushAlert;
use App\Actions\Schedule\CheckDeadlines;
use App\Filament\Admin\Pages\PushDevices as AdminPushDevices;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyRequests;
use App\Filament\Portal\Pages\PushDevices as PortalPushDevices;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Push\VkWebPushMessage;
use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Chủ đề của một thông báo đẩy (kế hoạch M12, R10–R11) — NƠI DUY NHẤT dựng nội dung đẩy: tiêu đề,
 * câu chữ, biểu tượng, `tag`, deep link, TTL và độ khẩn ({@see self::message()}). Test cấu trúc
 * (`tests/Feature/Push/PushStructureTest.php`) canh rằng không lớp nào khác dựng
 * {@see VkWebPushMessage} hay đọc khoá `push.alerts.*`.
 *
 * # Màn hình khoá không phải màn hình của văn phòng (R11)
 *
 * Payload có đúng `title` (tên văn phòng, `config('vkcrm.brand.short_name')`), `body` (một câu chung
 * từ `lang/vi/push.php`, mục `alerts`), `icon`, `badge`, `tag` (chủ đề + id bản ghi, để tin mới thay
 * tin cũ của cùng bản ghi) và `data.url` (đường dẫn TƯƠNG ĐỐI trong scope của panel người nhận).
 * Không bao giờ: mã hồ sơ, tiêu đề vụ, tên khách, tên các bên, tiêu đề tài liệu, tên đầu mục giấy tờ,
 * lý do từ chối, nội dung câu hỏi/câu trả lời, `internal_note`, tên toà, số thụ lý. Bản ghi liên quan
 * chỉ góp ID (vào `tag`, `data.url`), không góp chữ nào. Mức khẩn của mốc hạn ("hôm nay hoặc ngày
 * mai", "đã quá hạn") được phép, vì nó không chỉ ra khách nào.
 *
 * # Mỗi chủ đề đi cùng MỘT thư (R10)
 *
 * Giá trị chủ đề trùng đúng tên mẫu thư mà nó đi cùng (`client.stage_update`…), và bản ghi liên quan
 * ({@see self::relatedClass()}) là ĐÚNG bản ghi mà thư đó ghi vào nhật ký (`relatedRecord()` của
 * mailable) — nên dòng push của `outbound_messages` mang cùng `template`/`related_type` với dòng thư,
 * và luật "ai xem dòng nào" (`OutboundMessage::scopeVisibleTo()`) áp nguyên. Người nhận KHÔNG ở đây:
 * người gọi {@see SendPushAlert} đưa vào đúng collection người nhận của thư.
 *
 * Deep link (bảng R10):
 *  - khách: trang tiến độ hồ sơ (`/portal/ho-so/{vụ}`), khối Tài liệu (`#tai-lieu`) hay khối Hồ sơ
 *    giấy tờ (`#ho-so-giay-to`) — `id` của hai khối ở `matter-progress.blade.php`; trang yêu cầu của
 *    hồ sơ (`/portal/yeu-cau/{vụ}`). Phần `#…` không tới máy chủ, nên sau một lượt đăng nhập (hết
 *    phiên) trang mở ở đầu, không ở khối;
 *  - nhân sự: trang vụ việc, đúng tab (`?relation=` = chỉ số của RelationManager trong
 *    `MatterResource::getRelations()`, cùng cách `App\Mail\Staff\InstalmentOverdue::link()`). Giấy
 *    tờ khách nộp trỏ tab "Danh mục hồ sơ" (`ChecklistRelationManager`), nơi nhân sự duyệt nó — không
 *    có tab nào tên "Hồ sơ giấy tờ" (phán quyết (f) của controller). Câu hỏi tiếp của khách (`REQ-2`)
 *    đi dưới chủ đề yêu cầu mới, cùng `tag` của luồng: tin mới thay tin cũ của cùng luồng;
 *  - đợt thanh toán quá hạn: ĐÚNG nơi thư `staff.instalment_overdue` trỏ, theo người nhận
 *    (`InstalmentOverdue::link()`, phương thức riêng của mailable nên chép luật ở đây, và
 *    `tests/Feature/Push/StaffEventPushTest.php` so hai bên trên đường thật): trang "Công nợ" cho ai
 *    `Receivables::canBeOpenedBy()` (URL không mang id vụ nào — kế toán không xem được trang vụ
 *    việc), không thì tab "Hợp đồng và thanh toán" (`BillingRelationManager`) của vụ;
 *  - "Gửi thử": trang "Thông báo trên điện thoại" của panel người nhận.
 * Trang đích tự hỏi quyền như mọi request (SPEC §10.10): người không xem được vụ nhận 404.
 *
 * Thêm một chủ đề về sau = thêm một case ở đây (câu chữ ở `lang/vi/push.php`, nhãn ở
 * `lang/vi/enums.php` và `lang/vi/outbound.php`), một lời gọi {@see SendPushAlert} ở đúng nơi gửi thư,
 * và một dòng trong test đồng nhất người nhận (Task 8, 9). Cố ý KHÔNG có (R10): `client.otp`,
 * `client.activation`, `client.missing_documents`, `staff.stale_matter`, `staff.backup_alert.*` —
 * test ghim.
 *
 * `staff.instalment_overdue` (M9) là chủ đề thứ tám của bảng R10, theo phán quyết (e) của controller
 * (kế hoạch, "Ràng buộc toàn cục": Task 9 thêm sự kiện của M9 "nếu chúng đã có thư"): `normal`, TTL 72
 * giờ, một câu chung — không số tiền, không tên khách, không mã hợp đồng. `staff.handover_ready` (M7
 * Task 4) CHƯA có case: nơi gửi thư của nó chưa có trên nhánh này, mang sang lúc gộp M7 (Ghi chú M12,
 * Task 9 — một case không ai gọi là mã chết).
 */
enum PushTopic: string
{
    case ClientStageUpdate = 'client.stage_update';
    case ClientDocumentPublished = 'client.document_published';
    case ClientDocumentRejected = 'client.document_rejected';
    case ClientRequestAnswered = 'client.request_answered';
    case StaffDeadlineReminder = 'staff.deadline_reminder';
    case StaffNewClientRequest = 'staff.new_client_request';
    case StaffNewClientDocument = 'staff.new_client_document';
    case StaffInstalmentOverdue = 'staff.instalment_overdue';

    /** Nút "Gửi thử" của trang "Thông báo trên điện thoại" (`App\Actions\Push\SendTestPush`). */
    case Test = 'push.test';

    /**
     * Bậc nhắc của mốc hạn — đúng khoá `CheckDeadlines::tierFor()` trả (`d14` chỉ cho mốc `critical`;
     * `d1` = còn tối đa một ngày, tức hôm nay hoặc ngày mai).
     */
    public const DEADLINE_TIERS = ['d14', 'd7', 'd3', 'd1', CheckDeadlines::OVERDUE_KEY];

    /** R11: `urgency = high` cho bậc 1 ngày và quá hạn; mọi bậc khác `normal`. */
    public const PRESSING_TIERS = ['d1', CheckDeadlines::OVERDUE_KEY];

    /** R11: TTL (giây) — 24 giờ cho mốc hạn, 72 giờ cho các loại khác. */
    private const TTL_DEADLINE = 86400;

    private const TTL_DEFAULT = 259200;

    /** Một tin thử tới sau ba ngày (máy tắt) không thử được gì nữa: một giờ. */
    private const TTL_TEST = 3600;

    public function label(): string
    {
        return __('enums.push_topic.'.$this->value);
    }

    /** Panel của người nhận: `portal` (tài khoản cổng), `admin` (nhân sự), `null` = cả hai ("Gửi thử"). */
    public function panel(): ?string
    {
        return match ($this) {
            self::ClientStageUpdate, self::ClientDocumentPublished, self::ClientDocumentRejected, self::ClientRequestAnswered => 'portal',
            self::StaffDeadlineReminder, self::StaffNewClientRequest, self::StaffNewClientDocument, self::StaffInstalmentOverdue => 'admin',
            self::Test => null,
        };
    }

    /**
     * Loại bản ghi liên quan — ĐÚNG bản ghi mà thư đi cùng ghi vào nhật ký (`relatedRecord()` của
     * mailable: `StageUpdate` → dòng tiến độ, `DocumentPublished` → tài liệu, `DocumentRejected` → đầu
     * mục danh mục, `RequestAnswered` → câu trả lời, `DeadlineReminder` → mốc hạn, `NewClientRequest` →
     * yêu cầu, `NewClientDocument` → tài liệu đầu tiên của lượt nộp, `InstalmentOverdue` → đợt thu).
     * Câu hỏi tiếp của khách (`REQ-2`) không có thư: bản ghi là chính luồng yêu cầu, như thư của yêu
     * cầu mới. `null` = không có ("Gửi thử").
     *
     * @return class-string<Model>|null
     */
    public function relatedClass(): ?string
    {
        return match ($this) {
            self::ClientStageUpdate => StageLog::class,
            self::ClientDocumentPublished, self::StaffNewClientDocument => Document::class,
            self::ClientDocumentRejected => MatterChecklistItem::class,
            self::ClientRequestAnswered => ClientRequestReply::class,
            self::StaffDeadlineReminder => Deadline::class,
            self::StaffNewClientRequest => ClientRequest::class,
            self::StaffInstalmentOverdue => Instalment::class,
            self::Test => null,
        };
    }

    /**
     * Lời gọi đúng hình dạng chưa: bản ghi liên quan đúng loại, bậc có khi và chỉ khi là mốc hạn (và là
     * một khoá của {@see self::DEADLINE_TIERS}). Sai là lỗi lập trình — ném ngay, trước khi xếp gì.
     */
    public function assertAccepts(?Model $related, ?string $tier): void
    {
        $class = $this->relatedClass();

        if ($class === null ? $related !== null : ! $related instanceof $class) {
            throw new InvalidArgumentException("Chủ đề [{$this->value}] cần bản ghi liên quan loại [".($class ?? 'không có').'].');
        }

        $needsTier = $this === self::StaffDeadlineReminder;

        if ($needsTier ? ! in_array($tier, self::DEADLINE_TIERS, true) : $tier !== null) {
            throw new InvalidArgumentException("Chủ đề [{$this->value}] không nhận bậc [".($tier ?? 'null').'].');
        }
    }

    /** Người nhận đúng panel của chủ đề — nội dung và đường dẫn của panel kia không phải của họ. */
    public function assertRecipient(User|ClientUser $recipient): void
    {
        $panel = $this->panel();

        if ($panel !== null && $panel !== self::panelOf($recipient)) {
            throw new InvalidArgumentException("Chủ đề [{$this->value}] chỉ gửi tới tài khoản của panel [{$panel}].");
        }
    }

    /** TTL (giây) của lời gửi — R11. */
    public function ttl(): int
    {
        return match ($this) {
            self::StaffDeadlineReminder => self::TTL_DEADLINE,
            self::Test => self::TTL_TEST,
            default => self::TTL_DEFAULT,
        };
    }

    /** Độ khẩn (header `Urgency`, RFC 8030) — R11. */
    public function urgency(?string $tier = null): string
    {
        return $this === self::StaffDeadlineReminder && in_array($tier, self::PRESSING_TIERS, true) ? 'high' : 'normal';
    }

    /**
     * Thông điệp đẩy cho MỘT người nhận. Không đọc chữ nào của bản ghi liên quan — chỉ ID của nó và
     * của vụ việc chứa nó.
     */
    public function message(?Model $related, User|ClientUser $recipient, ?string $tier = null): VkWebPushMessage
    {
        $this->assertAccepts($related, $tier);
        $this->assertRecipient($recipient);

        return new VkWebPushMessage(
            title: (string) config('vkcrm.brand.short_name'),
            body: $this->body($tier),
            icon: self::publicPath(AppIcons::ANY[192]),
            badge: self::publicPath(AppIcons::BADGE),
            tag: $related === null ? $this->value : $this->value.':'.$related->getKey(),
            url: $this->url($related, $recipient),
            ttl: $this->ttl(),
            urgency: $this->urgency($tier),
            topic: $this->value,
            relatedType: $related?->getMorphClass(),
            relatedId: $related === null ? null : (int) $related->getKey(),
        );
    }

    private function body(?string $tier): string
    {
        return match ($this) {
            self::StaffDeadlineReminder => __('push.alerts.staff.deadline.'.match ($tier) {
                CheckDeadlines::OVERDUE_KEY => 'overdue',
                'd1' => 'imminent',
                default => 'upcoming',
            }),
            self::Test => __('push.alerts.test'),
            default => __('push.alerts.'.$this->value),
        };
    }

    /** `data.url`: đường dẫn tương đối (path, query, fragment) trong scope của panel người nhận. */
    private function url(?Model $related, User|ClientUser $recipient): string
    {
        $panel = self::panelOf($recipient);
        $matterId = $related === null ? null : self::matterIdOf($related);

        $absolute = match (true) {
            $this === self::Test => $panel === 'admin'
                ? AdminPushDevices::getUrl(panel: 'admin')
                : PortalPushDevices::getUrl(panel: 'portal'),
            // Bản ghi mồ côi (vụ việc đã bị xoá cứng): về trang chính của app — trang chính tự hỏi quyền.
            $matterId === null => PwaPanels::path($panel),
            $this === self::ClientStageUpdate => MatterProgress::getUrl(['record' => $matterId], panel: 'portal'),
            $this === self::ClientDocumentPublished => MatterProgress::getUrl(['record' => $matterId], panel: 'portal').'#tai-lieu',
            $this === self::ClientDocumentRejected => MatterProgress::getUrl(['record' => $matterId], panel: 'portal').'#ho-so-giay-to',
            $this === self::ClientRequestAnswered => MyRequests::getUrl(['record' => $matterId], panel: 'portal'),
            $this === self::StaffDeadlineReminder => self::matterTab($matterId, DeadlinesRelationManager::class),
            $this === self::StaffNewClientRequest => self::matterTab($matterId, ClientRequestsRelationManager::class),
            $this === self::StaffNewClientDocument => self::matterTab($matterId, ChecklistRelationManager::class),
            // Cùng luật `InstalmentOverdue::link()` — xem docblock lớp, mục deep link.
            $this === self::StaffInstalmentOverdue => $recipient instanceof User && Receivables::canBeOpenedBy($recipient)
                ? Receivables::getUrl(panel: 'admin')
                : self::matterTab($matterId, BillingRelationManager::class),
        };

        return self::relative($absolute);
    }

    /**
     * Trang vụ việc, mở sẵn tab của `$relationManager` — chỉ số trong `MatterResource::getRelations()`,
     * cùng cách `App\Mail\Staff\InstalmentOverdue::link()`. Không còn tab đó thì trang vụ việc trần.
     */
    private static function matterTab(int $matterId, string $relationManager): string
    {
        $parameters = ['record' => $matterId];
        $tab = array_search($relationManager, MatterResource::getRelations(), true);

        if ($tab !== false) {
            $parameters['relation'] = $tab;
        }

        return MatterResource::getUrl('view', $parameters, panel: 'admin');
    }

    /**
     * Vụ việc chứa bản ghi — đọc cột, không qua quan hệ có global scope: hàm có thể chạy trong request
     * của một khách đang mở cổng (`RestrictedToClientPortal`), mà ở đây chỉ cần một con số. Câu trả lời
     * đi qua luồng yêu cầu của nó, đợt thu qua hợp đồng của nó; mọi bản ghi khác mang `matter_id`.
     */
    private static function matterIdOf(Model $related): ?int
    {
        $matterId = match (true) {
            $related instanceof ClientRequestReply => ClientRequest::query()->withoutGlobalScopes()->whereKey($related->request_id)->value('matter_id'),
            $related instanceof Instalment => Contract::query()->withoutGlobalScopes()->whereKey($related->contract_id)->value('matter_id'),
            default => $related->getAttribute('matter_id'),
        };

        return $matterId === null ? null : (int) $matterId;
    }

    /**
     * Rút phần path/query/fragment của một URL tuyệt đối (có thể mang tên miền riêng của panel): payload
     * mang đường dẫn TƯƠNG ĐỐI, service worker tự ghép với origin của nó. Mọi đích ở {@see self::url()}
     * là trang của chính panel người nhận, nên nằm trong scope của app (`tests/Feature/Push/
     * PushTopicTest.php` khẳng định cho mọi chủ đề); service worker vẫn tự bỏ qua một URL ngoài scope.
     */
    private static function relative(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private static function panelOf(User|ClientUser $recipient): string
    {
        return $recipient instanceof ClientUser ? 'portal' : 'admin';
    }

    /** Đường dẫn gốc-tương-đối của một tệp trong `public/` (đi qua `asset()` như thẻ `<head>`). */
    private static function publicPath(string $file): string
    {
        return '/'.ltrim((string) parse_url(asset($file), PHP_URL_PATH), '/');
    }
}
