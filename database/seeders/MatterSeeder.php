<?php

namespace Database\Seeders;

use App\Actions\ApplyChecklistTemplate;
use App\Actions\Document\SubmitClientDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\CommunicationType;
use App\Enums\Confidentiality;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\PartyRole;
use App\Enums\UserPosition;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 20 vụ việc với các tình huống cố ý theo SPEC §12:
 *  - vụ 1–3: quá 14 ngày chưa cập nhật cho khách
 *  - vụ 4–5: có hạn tố tụng trong 3 ngày tới
 *  - vụ 6–9: còn giấy tờ chưa nộp
 *  - vụ 10–14: có tài liệu khách nộp chờ duyệt
 *  - vụ 20: bị đơn trùng căn cước với khách hàng số 2 (đang là khách trong vụ 2) => xung đột đỏ
 *  - vụ 1–10: khách đã xem các dòng công bố; vụ 11–20: chưa xem
 * Chạy lại không tạo thêm nếu đã đủ 20 vụ.
 *
 * **Tài liệu có TỆP THẬT, và chúng đi qua đúng hai Action của mã sản phẩm.** Cho tới vòng rà
 * soát cuối M4, `grep -rn addMedia database/` không ra dòng nào: seeder dựng ra những `Document`
 * không có tệp, nên sau `migrate:fresh --seed` mọi nút tải trong bản demo trả 404 và route
 * `documents.download` (SPEC §10.4) chưa từng chạy bằng tay một lần nào. SPEC §12 đòi dữ liệu mẫu
 * "dùng thật được ngay", và một nút tải hỏng không đạt điều đó.
 *
 * Tệp đi vào qua `UploadStaffDocument` và `SubmitClientDocument` chứ KHÔNG qua `addMedia()` viết
 * tay, và đó là toàn bộ điểm: dòng dữ liệu mẫu vì vậy đi đúng con đường của dòng dữ liệu thật —
 * cổng tệp `FileGuard`, `VirusScanner`, chuỗi version nhóm A, việc đóng đầu mục danh mục, cặp
 * `published_at`/`published_by` và các dòng nhật ký kiểm toán. Một seeder ghi thẳng dòng `media`
 * sẽ tạo ra dữ liệu trông giống thật mà không chứng minh được gì về đường đi.
 *
 * Hệ quả cần biết khi chạy: seeder này GHI RA ĐĨA (`storage/app/private`), nên test nào chạy nó
 * phải `Storage::fake('private')` trước. Xem `DemoPdf` cho tệp PDF được sinh ra.
 */
class MatterSeeder extends Seeder
{
    private const TITLES = [
        'DD' => 'Tranh chấp ranh giới thửa đất tại %s',
        'DS' => 'Tranh chấp hợp đồng vay tài sản với %s',
        'HS' => 'Bào chữa trong vụ án liên quan %s',
        'DN' => 'Thay đổi đăng ký doanh nghiệp cho %s',
        'LD' => 'Tranh chấp chấm dứt hợp đồng lao động với %s',
        'HN' => 'Ly hôn và chia tài sản chung với %s',
    ];

    /**
     * Loại vụ việc theo thứ tự 1..20. Vụ 6–14 phải là loại có danh mục hồ sơ (DD, DS, HN)
     * để tình huống "thiếu giấy tờ" và "chờ duyệt" đủ số theo SPEC §12.
     */
    private const TYPE_SEQUENCE = [
        'DD', 'DS', 'HN', 'LD', 'DN', 'DD', 'DS', 'HN', 'DD', 'DS',
        'HN', 'DD', 'DS', 'HN', 'HS', 'LD', 'DN', 'HS', 'DD', 'DS',
    ];

    private const OPPONENTS = [
        'Lý Văn Lâm', 'Trương Thị Mai', 'Công ty TNHH Nam Phong', 'Đinh Quốc Nam', 'Hồ Thị Oanh',
        'Mai Văn Phúc', 'Công ty CP Quang Minh', 'Tạ Thị Quỳnh', 'Dương Văn Sơn', 'Lâm Thị Tuyết',
        'Chu Văn Uy', 'Công ty TNHH Vạn Xuân', 'Phan Thị Yến', 'Quách Văn Ân', 'Kiều Thị Bảo',
        'Tô Văn Chiến', 'La Thị Diệu', 'Ông Văn Đông', 'Từ Thị Giang', 'Trần Thị Bình',
    ];

    /**
     * Idempotency của MỖI phần dưới đây tự đứng riêng, không dựa vào một mốc tổng
     * (`Matter::count()`) so với một con số cố định — một cơ sở dữ liệu đã seed ở bản TRƯỚC khi
     * có vụ `restricted` (20 vụ, không có vụ mật) và chạy lại seeder ở bản này phải chỉ thêm đúng
     * vụ mật còn thiếu, không được lặp lại 20 vụ đánh số (review fix round 1, minor D).
     */
    public function run(): void
    {
        $numberedMattersSeeded = Matter::where('confidentiality', '!=', Confidentiality::Restricted->value)->count() >= 20;
        $restrictedMatterSeeded = Matter::where('confidentiality', Confidentiality::Restricted->value)->exists();

        if ($numberedMattersSeeded && $restrictedMatterSeeded) {
            return;
        }

        fake()->seed(20260914);

        $admin = User::query()->where('email', 'admin@luatvukhang.com')->firstOrFail();
        auth('web')->setUser($admin);

        $lawyers = User::query()->where('position', UserPosition::Lawyer)->orderBy('email')->get()->values();
        $assistants = User::query()->where('position', UserPosition::Assistant)->orderBy('email')->get()->values();
        $clients = Client::query()->orderBy('id')->get()->values();
        $types = MatterType::query()->with(['stages', 'checklistTemplates.items'])->get()->keyBy('code');

        if (! $numberedMattersSeeded) {
            for ($i = 1; $i <= 20; $i++) {
                $client = $clients[($i - 1) % $clients->count()];
                $type = $types[self::TYPE_SEQUENCE[$i - 1]];
                $lead = $lawyers[($i - 1) % $lawyers->count()];
                $opponent = self::OPPONENTS[$i - 1];

                $workingStages = $type->stages->reject(fn ($s) => $s->is_terminal || $s->key === 'on_hold')->values();
                $stage = $workingStages[$i % $workingStages->count()];
                $openedAt = now()->subDays(30 + $i * 7);

                $matter = Matter::query()->create([
                    'client_id' => $client->id,
                    'matter_type_id' => $type->id,
                    'title' => sprintf(self::TITLES[$type->code], $opponent),
                    'description_internal' => 'Ghi chú nội bộ vụ '.$i.': đánh giá sơ bộ khả năng thắng kiện trung bình.',
                    'summary_for_client' => 'Văn phòng đang đại diện anh/chị trong vụ việc này và sẽ cập nhật từng bước.',
                    'stage' => $stage->key,
                    'stage_entered_at' => now()->subDays($i % 10 + 1),
                    'lead_lawyer_id' => $lead->id,
                    'opened_at' => $openedAt->toDateString(),
                    'is_published_to_portal' => true,
                    'court_name' => in_array($type->code, ['DN'], true) ? null : 'Toà án nhân dân Quận '.($i % 12 + 1).', TP. Hồ Chí Minh',
                    'case_number' => $i % 2 === 0 ? sprintf('%02d/2026/TLST-DS', $i) : null,
                    'last_client_update_at' => $i <= 3 ? now()->subDays(20) : now()->subDays($i % 10),
                ]);

                $matter->addTeamMember($assistants[$i % $assistants->count()], MatterRole::Assistant);
                if ($i % 4 === 0) {
                    $matter->addTeamMember($lawyers[$i % $lawyers->count()], MatterRole::Associate);
                }

                $this->parties($matter, $client, $opponent, $i, $clients);
                $this->stageLogs($matter, $stage->key, $i, $openedAt);
                $this->checklist($matter, $type, $i, $client);
                $this->documents($matter, $lead, $i);
                $this->deadlines($matter, $lead, $i);
                $this->communications($matter, $client, $i);
                $this->requests($matter, $client, $lead, $i);
            }

            $this->views();
        }

        if (! $restrictedMatterSeeded) {
            $this->restrictedMatter($lawyers, $clients, $types);
        }

        auth('web')->forgetUser();
    }

    /**
     * Vụ việc mật (SPEC §5, §12 — mang sang từ rà soát M2: nhánh `restricted` của
     * Matter::scopeListableBy chưa có bằng chứng trên dữ liệu mẫu thật). lead_lawyer_id cố định
     * là luật sư đầu tiên theo thứ tự email (luatsu1@luatvukhang.com) để tài khoản demo đó luôn
     * xem được đúng một vụ mật, còn quản lý (quanly@luatvukhang.com) — có matter.view nhưng
     * không phải admin và không đứng tên vụ này — bị loại ở nhánh restricted của scopeListableBy.
     */
    private function restrictedMatter(Collection $lawyers, Collection $clients, Collection $types): void
    {
        // Không phải 1..20 nên không đụng TYPE_SEQUENCE/OPPONENTS (chỉ mảng 20 phần tử); chỉ số
        // này chỉ nạp cho parties()/stageLogs() dùng biến thiên số lượng (họ không đọc hai mảng
        // đó), để vụ mật vẫn khớp bất biến "3–8 dòng stage_logs, 2–4 bên" của toàn bộ seeder.
        $i = 21;
        $client = $clients->first();
        $type = $types['DD'];
        $lead = $lawyers->first();
        $stage = $type->stages->reject(fn ($s) => $s->is_terminal)->first();
        $openedAt = now()->subDays(10);

        $matter = Matter::query()->create([
            'client_id' => $client->id,
            'matter_type_id' => $type->id,
            'title' => 'Tranh chấp đất đai — hồ sơ hạn chế truy cập',
            'description_internal' => 'Ghi chú nội bộ: vụ việc nhạy cảm, chỉ luật sư phụ trách và quản trị viên được xem (SPEC §5).',
            'summary_for_client' => 'Văn phòng đang xử lý vụ việc này và sẽ liên hệ trực tiếp khi cần.',
            'stage' => $stage->key,
            'stage_entered_at' => now()->subDays(5),
            'lead_lawyer_id' => $lead->id,
            'opened_at' => $openedAt->toDateString(),
            'is_published_to_portal' => false,
            'confidentiality' => Confidentiality::Restricted,
            'last_client_update_at' => now()->subDays(5),
        ]);

        $this->parties($matter, $client, 'Bên bị đơn của hồ sơ hạn chế', $i, $clients);
        $this->stageLogs($matter, $stage->key, $i, $openedAt);

        // Một ghi chú nhóm D trên đúng vụ nhạy cảm nhất: hai ranh giới của SPEC §5 và §4.11 —
        // "chỉ luật sư phụ trách và quản trị viên" và "nhóm D không bao giờ ra tới khách" — nằm
        // chồng lên nhau ở đây, và cả hai đều cần một dòng dữ liệu thật để nhìn thấy được.
        app(UploadStaffDocument::class)->handle(
            matter: $matter,
            actor: $lead,
            file: DemoPdf::upload('ghi-chu-han-che.pdf', 'Ghi chu ho so han che truy cap', $matter->code),
            group: DocumentGroup::Internal,
            title: 'Ghi chú nội bộ hồ sơ hạn chế truy cập',
        );
    }

    private function parties(Matter $matter, Client $client, string $opponent, int $i, Collection $clients): void
    {
        MatterParty::factory()->ourClient($client)->for($matter)->create();

        // Vụ 20: bị đơn chính là khách hàng số 2 của văn phòng => xung đột lợi ích đỏ.
        $conflictClient = $i === 20 ? $clients[1] : null;
        $rawIdNumber = $conflictClient?->id_number ?? sprintf('0%011d', 900000000000 + $i);
        $rawPhone = $conflictClient?->phone ?? sprintf('093%07d', $i);

        MatterParty::factory()->for($matter)->defendant()->identify($rawIdNumber, $rawPhone)->create([
            'name' => $conflictClient?->name ?? $opponent,
            'address' => 'TP. Hồ Chí Minh',
        ]);

        if ($i % 3 === 0) {
            MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => 'Người liên quan vụ '.$i]);
        }
        if ($i % 4 === 0) {
            MatterParty::factory()->for($matter)->create(['role' => PartyRole::OpposingCounsel, 'name' => 'Luật sư đối phương vụ '.$i]);
        }
    }

    private function stageLogs(Matter $matter, string $currentStage, int $i, CarbonInterface $openedAt): void
    {
        $count = 3 + ($i % 6); // 3..8
        $stageKeys = $matter->matterType->stages->pluck('key')->all();
        $currentIndex = max(0, (int) array_search($currentStage, $stageKeys, true));

        for ($n = 0; $n < $count; $n++) {
            $occurredAt = $openedAt->copy()->addDays($n * 5);
            $isTransition = $n === 0 || $n % 3 === 0;
            $published = $n % 2 === 0;
            $toIndex = min($currentIndex, intdiv($n, 3));

            $log = StageLog::factory()->for($matter)->create([
                'from_stage' => $isTransition ? ($n === 0 ? null : $stageKeys[max(0, $toIndex - 1)]) : null,
                'to_stage' => $isTransition ? $stageKeys[$toIndex] : null,
                'occurred_at' => $occurredAt,
                'internal_note' => 'Nội bộ vụ '.$i.' dòng '.($n + 1).': đã trao đổi với đồng nghiệp về chiến lược.',
                'public_content' => $published
                    ? 'Văn phòng đã hoàn tất bước công việc số '.($n + 1).' và tiếp tục theo dõi sát vụ việc của anh/chị.'
                    : null,
                'next_step' => $published ? 'Chờ phản hồi của cơ quan có thẩm quyền.' : null,
                'client_action' => $published && $n === $count - 1 && $i % 2 === 0 ? 'Vui lòng bổ sung bản sao chứng thực giấy tờ còn thiếu.' : null,
                'expected_next_update_at' => $published ? $occurredAt->copy()->addDays(14)->toDateString() : null,
                'is_published' => $published,
                'published_at' => $published ? $occurredAt : null,
                'notified_at' => $published ? $occurredAt : null,
            ]);

            if ($published) {
                OutboundMessage::factory()->sent()->create([
                    'channel' => MessageChannel::Email,
                    'recipient' => $matter->client->clientUsers()->first()->email,
                    'template' => 'client.stage_update',
                    'payload' => ['matter_code' => $matter->code],
                    'related_type' => $log->getMorphClass(),
                    'related_id' => $log->id,
                    'status' => MessageStatus::Sent,
                    'sent_at' => $occurredAt,
                ]);
            }
        }
    }

    private function checklist(Matter $matter, MatterType $type, int $i, Client $client): void
    {
        $template = $type->checklistTemplates->first();

        if ($template === null) {
            return;
        }

        $items = app(ApplyChecklistTemplate::class)->handle($matter, $template);
        $clientUser = $client->clientUsers()->first();
        $lead = $matter->leadLawyer;

        foreach ($items as $index => $item) {
            if ($i >= 6 && $i <= 9) {
                // Còn thiếu giấy tờ: chỉ mục đầu tiên đã nhận, còn lại chưa nộp. Mục đầu tiên đi
                // qua `UploadStaffDocument` ở nhóm A — "nhân viên nộp thay" của SPEC §4.11 — nên
                // chính Action đặt `accepted` kèm người duyệt, thay vì seeder tự ghi trạng thái.
                $index === 0
                    ? app(UploadStaffDocument::class)->handle(
                        matter: $matter,
                        actor: $lead,
                        file: DemoPdf::upload(
                            Str::slug($item->name).'-ban-giay.pdf',
                            $item->name,
                            $matter->code.' - ban giay khach mang toi van phong',
                        ),
                        group: DocumentGroup::ClientProvided,
                        title: $item->name,
                        checklistItem: $item,
                    )
                    : $item->update(['status' => ChecklistItemStatus::Missing]);

                continue;
            }

            if ($i >= 10 && $i <= 14 && $index === 0) {
                // Khách vừa nộp, chờ văn phòng kiểm tra — qua đúng Action của portal, nên đầu mục
                // sang `pending_review`, chuỗi version bắt đầu ở 1 và dòng `document_submitted`
                // được ghi y như một lần nộp thật.
                app(SubmitClientDocument::class)->handle(
                    $item,
                    $clientUser,
                    DemoPdf::upload(
                        Str::slug($item->name).'.pdf',
                        $item->name,
                        $matter->code.' - khach gui len qua trang khach hang',
                    ),
                );

                continue;
            }

            $item->update(['status' => $item->is_required ? ChecklistItemStatus::Accepted : ChecklistItemStatus::NotApplicable]);
        }
    }

    /**
     * Tài liệu của văn phòng: nhóm C trên mọi vụ, nhóm B và nhóm D rải ra.
     *
     * Mọi tài liệu ở đây đi qua `UploadStaffDocument`, nên chúng có tệp thật trên disk `private`,
     * có dòng `media`, có bộ cờ mặc định đúng bảng SPEC §4.11 và có nhật ký kiểm toán — tức tab
     * "Tài liệu" (SPEC §7.2) và route `documents.download` (SPEC §10.4) chạy được ngay trên dữ
     * liệu demo, đúng yêu cầu "dùng thật được ngay" của SPEC §12.
     *
     * Nhóm D có mặt là có chủ đích: nhãn "Chỉ nội bộ — không bao giờ hiện cho khách" là thứ phải
     * NHÌN THẤY trên dữ liệu mẫu thì mới kiểm được bằng mắt, và cổng "nhóm D không bao giờ công
     * bố" cũng vậy.
     */
    private function documents(Matter $matter, User $lead, int $i): void
    {
        app(UploadStaffDocument::class)->handle(
            matter: $matter,
            actor: $lead,
            file: DemoPdf::upload('thong-bao-thu-ly.pdf', 'Thong bao thu ly vu an', $matter->code),
            group: DocumentGroup::Authority,
            title: 'Thông báo thụ lý vụ án',
            issuedAt: now()->subDays($i % 20 + 5)->toDateString(),
        );

        if ($i % 2 === 0) {
            app(UploadStaffDocument::class)->handle(
                matter: $matter,
                actor: $lead,
                file: DemoPdf::upload('don-khoi-kien.pdf', 'Don khoi kien', $matter->code),
                group: DocumentGroup::Issued,
                title: 'Đơn khởi kiện do văn phòng soạn',
                issuedAt: now()->subDays($i % 15 + 3)->toDateString(),
            );
        }

        if ($i % 3 === 0) {
            app(UploadStaffDocument::class)->handle(
                matter: $matter,
                actor: $lead,
                file: DemoPdf::upload('ghi-chu-noi-bo.pdf', 'Ghi chu cong viec noi bo', $matter->code),
                group: DocumentGroup::Internal,
                title: 'Ghi chú đánh giá khả năng thắng kiện',
            );
        }
    }

    private function deadlines(Matter $matter, User $lead, int $i): void
    {
        Deadline::factory()->for($matter)->create([
            'name' => 'Nộp bổ sung tài liệu theo yêu cầu của toà',
            'due_date' => now()->addDays(10 + $i)->toDateString(),
            'responsible_user_id' => $lead->id,
            'is_published' => true,
        ]);

        if ($i === 4 || $i === 5) {
            Deadline::factory()->for($matter)->critical()->published()->create([
                'name' => 'Hết thời hạn kháng cáo',
                'due_date' => now()->addDays(2)->toDateString(),
                'responsible_user_id' => $lead->id,
            ]);
        }
    }

    private function communications(Matter $matter, Client $client, int $i): void
    {
        CommunicationLog::factory()->for($matter)->create([
            'type' => $i % 2 === 0 ? CommunicationType::CallOut : CommunicationType::Meeting,
            'occurred_at' => now()->subDays($i % 7 + 1),
            'counterpart' => $client->name,
            'summary' => 'Trao đổi tiến độ vụ việc và hướng dẫn chuẩn bị giấy tờ.',
            'is_visible_to_client' => $i % 5 === 0,
        ]);
    }

    private function requests(Matter $matter, Client $client, User $lead, int $i): void
    {
        if ($i > 3) {
            return;
        }

        $clientUser = $client->clientUsers()->first();
        $request = ClientRequest::factory()->for($matter)->create([
            'client_user_id' => $clientUser->id,
            'subject' => 'Hỏi về thời gian dự kiến xét xử',
            'content' => 'Luật sư cho tôi hỏi bao lâu nữa thì toà xử ạ?',
            'assigned_to' => $lead->id,
        ]);

        ClientRequestReply::factory()->create([
            'request_id' => $request->id,
            'author_type' => $lead->getMorphClass(),
            'author_id' => $lead->id,
            'content' => 'Chào anh/chị, dự kiến toà sẽ mở phiên trong tháng tới, văn phòng sẽ báo ngay khi có lịch.',
        ]);
    }

    private function views(): void
    {
        Matter::query()->orderBy('id')->take(10)->with(['stageLogs', 'client.clientUsers'])->get()
            ->each(function (Matter $matter): void {
                $viewer = $matter->client->clientUsers->first();

                $matter->stageLogs->where('is_published', true)->each(fn (StageLog $log) => StageLogView::factory()->create([
                    'stage_log_id' => $log->id,
                    'client_user_id' => $viewer->id,
                    'viewed_at' => $log->published_at->copy()->addHours(3),
                    'ip' => '127.0.0.1',
                ]));
            });
    }
}
