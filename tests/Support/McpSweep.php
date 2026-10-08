<?php

namespace Tests\Support;

use App\Enums\AiAccessMode;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpIds;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Lượt quét xuyên suốt của M11 Task 14 (`tests/Feature/Mcp/SensitiveDataSweepTest.php`): một bộ dữ
 * liệu mang chuỗi đánh dấu ở MỌI chỗ R4/R3/R10 cấm ra ngoài qua MCP, một kế hoạch gọi MỌI tool của
 * `CrmServer` với mọi tham số hợp lệ trỏ vào các vụ đó, và một bộ chạy kế hoạch qua HTTP thật.
 *
 * # Chuỗi đánh dấu chỉ dùng ký tự ASCII
 *
 * Thân phản hồi là JSON; chữ có dấu tiếng Việt có thể đi ra ở dạng `\uXXXX` và một phép tìm chuỗi thô
 * sẽ không thấy nó dù nó đã lọt. Nên mọi kim là `[A-Za-z0-9@.-]`, và phép quét còn tìm trên bản giải
 * mã rồi mã hoá lại không thoát ({@see self::haystacks()}).
 *
 * # Tập tool lấy từ `CrmServer`, không chép tay
 *
 * {@see self::plan()} đọc `inputSchema` của từng tool đăng ký ở `CrmServer::$tools`
 * (`McpToolCall::registeredTools()`) và tự sinh tham số theo TÊN và KIỂU khai báo. Tool mới thêm sau
 * này tự vào lượt quét; một tham số id có tên lạ (`foo_id`) làm kế hoạch ném lỗi, để người thêm tool
 * nói rõ id đó trỏ vào loại bản ghi nào thay vì để lượt quét lặng lẽ bỏ qua nó.
 */
final class McpSweep
{
    /** Tiền tố của mọi kim — không trùng chữ nào khác trong bộ dữ liệu. */
    public const PREFIX = 'SWEEPX';

    /** Kim DƯƠNG: tiêu đề vụ mở PHẢI xuất hiện, để chứng minh lượt quét nhìn đúng chỗ dữ liệu ra. */
    public const VISIBLE = 'SWEEPX-VISIBLE-OPEN-TITLE';

    /** Ghi chú nội bộ mà lượt quét GỬI vào `draft_progress_update`: tool ghi được, nhưng không bao giờ trả lại. */
    public const WRITTEN_INTERNAL_NOTE = 'SWEEPX-WRITTEN-INTERNAL-NOTE';

    /**
     * @param  array<string, list<string>>  $secrets  nhãn (chỗ đặt) → các dạng của kim
     * @param  array<string, ClientRequest>  $requests  theo khoá vụ
     */
    private function __construct(
        public readonly User $admin,
        public readonly User $outsider,
        public readonly Matter $open,
        public readonly Matter $restricted,
        public readonly Matter $denied,
        public readonly Matter $deleted,
        public readonly Matter $outsiderMatter,
        public readonly array $requests,
        public readonly ClientUser $portalUser,
        public readonly array $secrets,
    ) {}

    /**
     * Dựng bộ dữ liệu. Cần `Storage::fake('private')` trước (tệp của tài liệu) và vai trò đã seed.
     *
     * Admin `read_write` là luật sư phụ trách của CẢ BỐN vụ: web cho người này xem cả vụ hạn chế, nên
     * mọi lần vắng mặt ở dưới là do MCP thu hẹp (R3), không do web từ chối.
     */
    public static function build(): self
    {
        $admin = User::factory()->admin()->withAiAccess(AiAccessMode::ReadWrite)->create(['name' => 'Quan tri Quet']);
        $outsider = User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::ReadWrite)->create();
        $secrets = [];

        $openClient = Client::factory()->create([
            'name' => 'Khach Mo Quet',
            'id_number' => '079777123456',
            'phone' => '0912 777 888',
            'email' => 'sweepx-client-email@example.test',
            'address' => 'SWEEPX-CLIENT-ADDRESS',
            'note' => 'SWEEPX-CLIENT-NOTE',
        ]);
        $secrets['clients.id_number'] = ['079777123456'];
        $secrets['clients.phone (đủ số)'] = ['912777888', '912 777 888'];
        $secrets['clients.email'] = ['sweepx-client-email@example.test'];
        $secrets['clients.address'] = ['SWEEPX-CLIENT-ADDRESS'];
        $secrets['clients.note'] = ['SWEEPX-CLIENT-NOTE'];

        $open = Matter::factory()->aiAccessAllowed()->create([
            'client_id' => $openClient->id,
            'lead_lawyer_id' => $admin->id,
            'title' => 'Vu mo '.self::VISIBLE,
            'description_internal' => 'SWEEPX-OPEN-DESCRIPTION-INTERNAL',
            'summary_for_client' => 'SWEEPX-OPEN-SUMMARY-DRAFT',
            'is_published_to_portal' => true,
        ]);
        $secrets['matters.description_internal'] = ['SWEEPX-OPEN-DESCRIPTION-INTERNAL'];
        $secrets['matters.summary_for_client'] = ['SWEEPX-OPEN-SUMMARY-DRAFT'];

        // Ghi chú nội bộ của dòng tiến độ — một dòng đã công bố, một dòng chưa (để một scope cổng khách
        // bật nhầm làm hai lượt khác nhau ở test ngữ cảnh ambient).
        StageLog::factory()->for($open)->published()->create(['public_content' => 'Da cong bo', 'internal_note' => 'SWEEPX-STAGELOG-INTERNAL-NOTE-PUBLISHED', 'occurred_at' => now()->subDays(2)]);
        StageLog::factory()->for($open)->internalOnly()->create(['public_content' => 'Chua cong bo', 'internal_note' => 'SWEEPX-STAGELOG-INTERNAL-NOTE-DRAFT', 'occurred_at' => now()->subDay()]);
        $secrets['stage_logs.internal_note'] = ['SWEEPX-STAGELOG-INTERNAL-NOTE-PUBLISHED', 'SWEEPX-STAGELOG-INTERNAL-NOTE-DRAFT'];

        StageLogDraft::factory()->create(['matter_id' => $open->id, 'created_by' => $admin->id, 'internal_note' => 'SWEEPX-AI-DRAFT-INTERNAL-NOTE']);
        $secrets['stage_log_drafts.internal_note'] = ['SWEEPX-AI-DRAFT-INTERNAL-NOTE'];
        $secrets['internal_note gửi vào draft_progress_update'] = [self::WRITTEN_INTERNAL_NOTE];

        // Các bên: một khách của văn phòng (tên thật ra được), một bên thứ ba (tên giả ở chế độ
        // pseudonym), mang đủ CCCD, số điện thoại, địa chỉ, ghi chú.
        MatterParty::factory()->ourClient($openClient)->create(['matter_id' => $open->id]);
        $third = MatterParty::factory()->defendant()->identify('079555123456', '0913555777')->create([
            'matter_id' => $open->id,
            'name' => 'SWEEPX-THIRD-PARTY-NAME',
            'address' => 'SWEEPX-PARTY-ADDRESS',
            'note' => 'SWEEPX-PARTY-NOTE',
        ]);
        $secrets['matter_parties.name (bên thứ ba, pseudonym)'] = ['SWEEPX-THIRD-PARTY-NAME'];
        $secrets['matter_parties.address'] = ['SWEEPX-PARTY-ADDRESS'];
        $secrets['matter_parties.note'] = ['SWEEPX-PARTY-NOTE'];
        $secrets['matter_parties.phone_normalized'] = array_values(array_filter(['913555777', (string) $third->phone_normalized]));
        $secrets['matter_parties.id_number_hash'] = array_values(array_filter(['079555123456', (string) $third->id_number_hash]));

        // Tài liệu: nhóm D (tiêu đề, tệp), và một tài liệu nhóm A khách thấy được — tên tệp GỐC của cả
        // hai không bao giờ ra (chỉ metadata, R4).
        $internal = Document::factory()->for($open)->group(DocumentGroup::Internal)->create(['title' => 'SWEEPX-GROUP-D-TITLE', 'status' => DocumentStatus::Published]);
        $internal->addMedia(UploadedFile::fake()->createWithContent('SWEEPX-ORIGINAL-FILENAME-D.pdf', '%PDF-1.4 d'))->toMediaCollection('file');
        $visible = Document::factory()->for($open)->group(DocumentGroup::ClientProvided)->create([
            'title' => 'Giay to khach gui', 'status' => DocumentStatus::Published, 'client_can_view' => true, 'client_can_download' => true,
        ]);
        $visible->addMedia(UploadedFile::fake()->createWithContent('SWEEPX-ORIGINAL-FILENAME-A.pdf', '%PDF-1.4 a'))->toMediaCollection('file');
        $secrets['documents.title nhóm D'] = ['SWEEPX-GROUP-D-TITLE'];
        $secrets['tên tệp gốc của tài liệu'] = ['SWEEPX-ORIGINAL-FILENAME'];

        MatterChecklistItem::factory()->for($open)->create(['name' => 'Ban sao giay to']);

        Deadline::factory()->for($open)->published()->create(['name' => 'Moc da cong bo', 'responsible_user_id' => $admin->id, 'due_date' => today()->addDays(3)]);
        Deadline::factory()->for($open)->create(['name' => 'Moc noi bo', 'is_published' => false, 'responsible_user_id' => $admin->id, 'due_date' => today()->addDays(4)]);

        Audit::record('conflict_check_run', $open, ['query' => 'SWEEPX-CONFLICT-QUERY', 'matches' => ['SWEEPX-CONFLICT-MATCH']], $admin);
        $secrets['activity_log conflict_*: properties'] = ['SWEEPX-CONFLICT-QUERY', 'SWEEPX-CONFLICT-MATCH'];

        $portalUser = ClientUser::factory()->activated()->create(['client_id' => $openClient->id, 'email' => 'sweepx-portal-sender@example.test']);
        $secrets['client_users.email (người gửi yêu cầu)'] = ['sweepx-portal-sender@example.test'];

        $requests = ['open' => ClientRequest::factory()->create([
            'matter_id' => $open->id, 'client_user_id' => $portalUser->id, 'subject' => 'Hoi tien do', 'content' => 'Xin cap nhat',
        ])];

        OutboundMessage::factory()->create([
            'recipient' => 'sweepx-recipient@example.test',
            'related_type' => $requests['open']->getMorphClass(),
            'related_id' => $requests['open']->id,
        ]);
        $secrets['outbound_messages.recipient'] = ['sweepx-recipient@example.test'];

        // Ba vụ MCP không bao giờ thấy, dù admin phụ trách: hạn chế, `denied`, đã xoá mềm (R3).
        $hidden = [];

        foreach (['restricted', 'denied', 'deleted'] as $kind) {
            $label = strtoupper($kind);
            $client = Client::factory()->create(['name' => "SWEEPX-{$label}-CLIENT-NAME"]);
            $factory = Matter::factory();
            $factory = $kind === 'denied' ? $factory : $factory->aiAccessAllowed();
            $factory = $kind === 'restricted' ? $factory->restricted() : $factory;

            $matter = $factory->create([
                'client_id' => $client->id,
                'lead_lawyer_id' => $admin->id,
                'title' => "SWEEPX-{$label}-TITLE",
                'description_internal' => "SWEEPX-{$label}-DESCRIPTION-INTERNAL",
            ]);

            StageLog::factory()->for($matter)->published()->create(['public_content' => "SWEEPX-{$label}-PUBLIC-CONTENT", 'internal_note' => "SWEEPX-{$label}-INTERNAL-NOTE"]);
            Deadline::factory()->for($matter)->create(['name' => "SWEEPX-{$label}-DEADLINE", 'responsible_user_id' => $admin->id, 'due_date' => today()->addDays(2)]);
            Document::factory()->for($matter)->group(DocumentGroup::Issued)->create(['title' => "SWEEPX-{$label}-DOCUMENT"]);
            MatterChecklistItem::factory()->for($matter)->create(['name' => "SWEEPX-{$label}-CHECKLIST"]);
            $requests[$kind] = ClientRequest::factory()->create(['matter_id' => $matter->id, 'subject' => "SWEEPX-{$label}-REQUEST-SUBJECT", 'content' => "SWEEPX-{$label}-REQUEST-CONTENT"]);

            $secrets["vụ {$kind}: tiêu đề, mã, khách, ghi chú nội bộ, nội dung con"] = [
                "SWEEPX-{$label}-TITLE", (string) $matter->code, "SWEEPX-{$label}-CLIENT-NAME",
                "SWEEPX-{$label}-DESCRIPTION-INTERNAL", "SWEEPX-{$label}-INTERNAL-NOTE", "SWEEPX-{$label}-PUBLIC-CONTENT",
                "SWEEPX-{$label}-DEADLINE", "SWEEPX-{$label}-DOCUMENT", "SWEEPX-{$label}-CHECKLIST",
                "SWEEPX-{$label}-REQUEST-SUBJECT", "SWEEPX-{$label}-REQUEST-CONTENT",
            ];

            $hidden[$kind] = $matter;
        }

        $hidden['deleted']->delete();

        // Vụ của người ngoài đội (cặp dương của lượt "không thấy vụ → not_found").
        $outsiderMatter = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $outsider->id, 'title' => 'Vu cua nguoi ngoai']);
        $requests['outsider'] = ClientRequest::factory()->create(['matter_id' => $outsiderMatter->id]);

        return new self($admin, $outsider, $open, $hidden['restricted'], $hidden['denied'], $hidden['deleted'], $outsiderMatter, $requests, $portalUser, $secrets);
    }

    /** @return list<Matter> bốn vụ của lượt quét (không gồm vụ của người ngoài đội) */
    public function sweptMatters(): array
    {
        return [$this->open, $this->restricted, $this->denied, $this->deleted];
    }

    /**
     * Ứng viên của một tham số id theo LOẠI bản ghi nó trỏ tới ({@see self::idKind()}).
     *
     * @return list<string>
     */
    public function idsOfKind(string $kind): array
    {
        $matters = array_map(fn (Matter $matter): string => McpIds::encode(McpIds::MATTER, $matter->id), $this->sweptMatters());
        $requests = array_map(
            fn (string $key): string => McpIds::encode(McpIds::REQUEST, $this->requests[$key]->id),
            ['open', 'restricted', 'denied', 'deleted'],
        );

        return match ($kind) {
            'matter' => $matters,
            'request' => $requests,
            'record' => [...$matters, ...$requests],
            'user' => [McpIds::encode(McpIds::USER, $this->admin->id)],
            default => throw new LogicException("Loại id lạ: {$kind}"),
        };
    }

    /**
     * Loại bản ghi của một tham số id, suy từ TÊN tham số. `null`: không phải tham số id.
     *
     * @throws LogicException tham số `…_id` chưa biết — người thêm tool phải khai loại của nó ở đây
     */
    public static function idKind(string $tool, string $param): ?string
    {
        return match (true) {
            $param === 'id' => 'record',
            $param === 'matter_id' => 'matter',
            $param === 'request_id' => 'request',
            $param === 'responsible_id' => 'user',
            str_ends_with($param, '_id') => throw new LogicException("Tham số id mới {$tool}.{$param}: khai loại bản ghi của nó trong McpSweep::idKind()."),
            default => null,
        };
    }

    /**
     * Chuỗi tìm kiếm cho tham số kiểu `query`: mã, chữ trong tiêu đề, tên khách của mọi vụ; và (chỉ ở
     * tool đọc) chính các kim — một lần tìm theo ghi chú nội bộ hay tên bên thứ ba không được trả gì có
     * chứa chúng. Tool ghi không nhận kim ở văn bản tự do (nó lưu và có thể trả lại văn bản người gọi
     * gửi: đó không phải rò rỉ).
     *
     * @return list<string>
     */
    public function queries(bool $withSecrets): array
    {
        $queries = [];

        foreach ($this->sweptMatters() as $matter) {
            $queries[] = (string) $matter->code;
            $queries[] = (string) $matter->title;
            $queries[] = (string) Client::query()->whereKey($matter->client_id)->value('name');
        }

        $queries[] = 'SWEEPX';

        if ($withSecrets) {
            foreach ($this->secrets as $needles) {
                foreach ($needles as $needle) {
                    $queries[] = $needle;
                }
            }
        }

        return array_values(array_unique(array_filter($queries, fn (string $query): bool => $query !== '' && mb_strlen($query) <= 100)));
    }

    /**
     * Kế hoạch gọi: mọi tool của `CrmServer`, mỗi tham số id lần lượt trỏ vào từng bản ghi cùng loại
     * của bốn vụ; với tham số không phải id, từng giá trị ứng viên một (trong khi các tham số khác giữ
     * mặc định), và mỗi giá trị đó thêm một lần với từng vụ ở tham số id tuỳ chọn (`matter_id` của
     * `list_deadlines`…). `$variations = false` chỉ giữ lượt theo id (test ngữ cảnh ambient).
     *
     * `cursor` và `confirmation_token` không sinh ở đây: bộ chạy đi theo `next_cursor` và
     * `confirmation_token` mà phản hồi trả ({@see self::run()}).
     *
     * @return list<array{tool: string, arguments: array<string, mixed>}>
     */
    public function plan(bool $variations = true): array
    {
        $plan = [];
        $counter = 0;

        foreach (McpToolCall::registeredTools() as $class) {
            ['name' => $name, 'base' => $base, 'ids' => $idParams, 'optional' => $optional, 'variants' => $variants] = $this->describe($class);

            $requiredIds = array_filter($idParams, fn (array $id): bool => $id['required']);
            $optionalIds = array_filter($idParams, fn (array $id): bool => ! $id['required']);

            // Một bộ "đường nền" cho mỗi bản ghi mà tham số id bắt buộc trỏ tới; không có id bắt buộc thì
            // một đường nền duy nhất, cộng mỗi id tuỳ chọn trỏ vào từng bản ghi.
            $grounds = [[]];

            foreach ($requiredIds as $param => $id) {
                $grounds = array_merge(...array_map(
                    fn (array $ground): array => array_map(fn (string $value): array => [...$ground, $param => $value], $this->idsOfKind($id['kind'])),
                    $grounds,
                ));
            }

            foreach ($optionalIds as $param => $id) {
                foreach ($this->idsOfKind($id['kind']) as $value) {
                    $grounds[] = [...($grounds[0] ?? []), $param => $value];
                }
            }

            foreach ($grounds as $ground) {
                $plan[] = ['tool' => $name, 'arguments' => $this->fresh([...$base, ...$ground], $counter)];

                if (! $variations) {
                    continue;
                }

                foreach ($variants as $param => $candidates) {
                    foreach ($candidates as $value) {
                        $plan[] = ['tool' => $name, 'arguments' => $this->fresh([...$base, ...$ground, $param => $value], $counter)];
                    }
                }

                // Mọi tham số tuỳ chọn cùng lúc ở giá trị đầu tiên (rộng nhất) của nó.
                if ($optional !== []) {
                    $plan[] = ['tool' => $name, 'arguments' => $this->fresh([...$base, ...$ground, ...array_map(fn (array $c): mixed => $c[0], $optional)], $counter)];
                }
            }
        }

        return $plan;
    }

    /**
     * Đọc `inputSchema` của một tool: tham số bắt buộc không phải id ở giá trị mặc định (`base`), tham
     * số id và loại của chúng (`ids`), ứng viên của tham số tuỳ chọn (`optional`), và mọi giá trị thay
     * thế để quét (`variants`: phần còn lại của tham số bắt buộc, mọi ứng viên của tham số tuỳ chọn).
     *
     * @param  class-string<CrmTool>  $class
     * @return array{name: string, writes: bool, base: array<string, mixed>, ids: array<string, array{kind: string, required: bool}>, optional: array<string, list<mixed>>, variants: array<string, list<mixed>>}
     */
    public function describe(string $class): array
    {
        /** @var CrmTool $tool */
        $tool = app($class);
        $name = $tool->name();
        $schema = $tool->toArray()['inputSchema'];
        $required = (array) ($schema['required'] ?? []);
        $writes = $tool->isWriteTool();
        $described = ['name' => $name, 'writes' => $writes, 'base' => [], 'ids' => [], 'optional' => [], 'variants' => []];

        foreach ((array) ($schema['properties'] ?? []) as $param => $definition) {
            if (in_array($param, ['cursor', 'confirmation_token'], true)) {
                continue;
            }

            $kind = self::idKind($name, $param);

            if ($kind !== null) {
                $described['ids'][$param] = ['kind' => $kind, 'required' => in_array($param, $required, true)];

                continue;
            }

            $candidates = $this->candidates($param, (array) $definition, $writes);

            if (in_array($param, $required, true)) {
                $described['base'][$param] = $candidates[0];
                $described['variants'][$param] = array_slice($candidates, 1);
            } else {
                $described['optional'][$param] = $candidates;
                $described['variants'][$param] = $candidates;
            }
        }

        return $described;
    }

    /**
     * Tham số hợp lệ tối thiểu của một tool (bắt buộc ở giá trị mặc định, `idempotency_key` mới) cộng
     * `$ids`.
     *
     * @param  class-string<CrmTool>  $class
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    public function arguments(string $class, array $ids): array
    {
        static $counter = 5000;

        return $this->fresh([...$this->describe($class)['base'], ...$ids], $counter);
    }

    /**
     * Giá trị ứng viên của một tham số KHÔNG phải id, theo tên rồi theo kiểu khai báo. Phần tử đầu là
     * giá trị mặc định (và "rộng nhất" cho bộ lọc).
     *
     * @param  array<string, mixed>  $definition
     * @return list<mixed>
     */
    private function candidates(string $param, array $definition, bool $writes): array
    {
        $type = $definition['type'] ?? 'string';
        $format = $definition['format'] ?? null;

        if (isset($definition['enum'])) {
            return array_values((array) $definition['enum']);
        }

        $minimum = (int) ($definition['minimum'] ?? 0);
        $maximum = (int) ($definition['maximum'] ?? 1000);

        return match (true) {
            $type === 'boolean' => [true, false],
            $param === 'limit' => [$maximum],
            $type === 'integer' || $type === 'number' => [max($minimum, min(30, $maximum))],
            $param === 'query' => $this->queries(! $writes),
            $param === 'from' => ['2000-01-01', today()->toDateString()],
            $param === 'to' => ['2100-12-31', today()->addDays(7)->toDateString()],
            $param === 'responsible' => ['any', 'me', McpIds::encode(McpIds::USER, $this->admin->id)],
            $param === 'matter_type' => array_values(array_unique(array_merge(...array_map(
                fn (Matter $matter): array => [(string) $matter->matterType?->code, (string) $matter->matterType?->name],
                array_map(fn (Matter $matter): Matter => Matter::withTrashed()->with('matterType')->findOrFail($matter->id), $this->sweptMatters()),
            )))),
            $param === 'stage' => array_values(array_unique(array_map(fn (Matter $matter): string => (string) $matter->stage, $this->sweptMatters()))),
            $param === 'internal_note' => [self::WRITTEN_INTERNAL_NOTE],
            $param === 'idempotency_key' => ['sweep-key-0000'],
            $format === 'date' => [today()->addDays(10)->toDateString()],
            $format === 'date-time' => [now()->subHour()->startOfMinute()->toIso8601String()],
            default => $writes ? ['Noi dung quet tu dong'] : $this->queries(true),
        };
    }

    /**
     * Mỗi lời gọi tool nháp một `idempotency_key` riêng: cùng khoá với nội dung khác là lỗi (đúng
     * luật R6), không phải thứ lượt quét muốn hỏi.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function fresh(array $arguments, int &$counter): array
    {
        if (array_key_exists('idempotency_key', $arguments)) {
            $arguments['idempotency_key'] = sprintf('sweep-key-%04d', ++$counter);
        }

        return $arguments;
    }

    /**
     * Chạy kế hoạch qua HTTP thật (`McpToolCall::call()`: token Passport thật, mọi middleware của
     * `/mcp`), đi theo `confirmation_token` (lần gọi thứ hai của tool hai bước) và `next_cursor` (trang
     * kế, tối đa năm trang). Giới hạn tần suất không phải câu hỏi của lượt quét (có test riêng): bộ đếm
     * được xoá trước mỗi lần gọi.
     *
     * @param  list<array{tool: string, arguments: array<string, mixed>}>  $plan
     * @return list<array{tool: string, arguments: array<string, mixed>, status: int, body: string}>
     */
    public static function run(object $test, string $token, array $plan): array
    {
        $transcript = [];

        foreach ($plan as $call) {
            $arguments = $call['arguments'];

            for ($step = 0; $step < 6; $step++) {
                Cache::flush();

                $response = McpToolCall::call($test, $token, $call['tool'], $arguments);
                $body = (string) $response->getContent();
                $transcript[] = ['tool' => $call['tool'], 'arguments' => $arguments, 'status' => $response->getStatusCode(), 'body' => $body];

                $structured = json_decode($body, true)['result']['structuredContent'] ?? null;

                if (! is_array($structured)) {
                    break;
                }

                if (is_string($structured['confirmation_token'] ?? null) && ! isset($arguments['confirmation_token'])) {
                    $arguments['confirmation_token'] = $structured['confirmation_token'];
                } elseif (is_string($structured['next_cursor'] ?? null)) {
                    $arguments['cursor'] = $structured['next_cursor'];
                } else {
                    break;
                }
            }
        }

        return $transcript;
    }

    /**
     * Các dạng của một thân phản hồi để tìm kim: nguyên văn, và bản giải JSON rồi mã hoá lại không thoát
     * Unicode hay dấu `/` (một kim đi ra dạng `\uXXXX` hay `\/` vẫn bị thấy).
     *
     * @return list<string>
     */
    public static function haystacks(string $body): array
    {
        $decoded = json_decode($body, true);

        return [
            $body,
            $decoded === null ? '' : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Mọi lần lộ: "nhãn — kim — tool(tham số)".
     *
     * @param  list<array{tool: string, arguments: array<string, mixed>, status: int, body: string}>  $transcript
     * @return list<string>
     */
    public function leaks(array $transcript): array
    {
        $leaks = [];

        foreach ($transcript as $entry) {
            $haystacks = self::haystacks($entry['body']);

            foreach ($this->secrets as $label => $needles) {
                foreach ($needles as $needle) {
                    foreach ($haystacks as $haystack) {
                        if ($needle !== '' && stripos($haystack, $needle) !== false) {
                            $leaks[] = "{$label} — {$needle} — {$entry['tool']}(".json_encode($entry['arguments'], JSON_UNESCAPED_UNICODE).')';

                            break;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($leaks));
    }
}
