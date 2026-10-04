<?php

namespace App\Support\Mcp\Presenters;

use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;
use Illuminate\Support\Collection;

/**
 * Vụ việc trong kết quả MCP (kế hoạch M11, R4; bảng tool: `search_matters`, `get_matter`, và tham
 * chiếu vụ trong kết quả của tool khác). Ba mức, mỗi mức một danh sách trường liệt kê tường minh:
 *
 *  - {@see self::reference()} — id, mã, tiêu đề, url: gắn vào mốc, yêu cầu… để biết chúng thuộc vụ nào;
 *  - {@see self::row()} — một dòng của `search_matters`: thêm loại, giai đoạn (nhãn NỘI BỘ), khách,
 *    luật sư phụ trách, mở/đóng;
 *  - {@see self::detail()} — phần của chính vụ việc trong `get_matter`: thêm toà, số thụ lý, đội ngũ,
 *    khách (số điện thoại đã che, {@see ClientPresenter}), các bên ({@see PartyPresenter}, R10), và cờ
 *    `has_internal_note`. Năm mốc sắp tới, "Đã nộp X/Y" và số yêu cầu đang mở do Action của Task 10
 *    tính và ghép bên cạnh — chúng không phải cột của vụ việc.
 *
 * Không bao giờ: `description_internal` (chỉ cờ `has_internal_note`), `summary_for_client` (bản nháp
 * lời gửi khách), `confidentiality` (luôn `normal` trong tập `McpMatterScope`), `ai_access`,
 * `is_published_to_portal`, email hay CCCD của khách. Presenter không kiểm quyền: nơi gọi chỉ đưa vào
 * vụ việc đã qua `App\Actions\Mcp\McpMatterScope`.
 */
final class MatterPresenter
{
    use ReadsLoadedRelations;

    public const REFERENCE_FIELDS = ['id', 'code', 'title', 'url'];

    public const ROW_FIELDS = [
        'id', 'code', 'title', 'matter_type', 'stage', 'stage_label', 'client_name', 'lead_lawyer',
        'is_open', 'opened_at', 'closed_at', 'url',
    ];

    public const DETAIL_FIELDS = [
        ...self::ROW_FIELDS,
        'court_name', 'case_number', 'has_internal_note', 'client', 'team', 'parties',
    ];

    public const TEAM_MEMBER_FIELDS = [...StaffPresenter::FIELDS, 'role_in_matter', 'role_in_matter_label'];

    /**
     * @return array{id: string, code: string, title: string, url: string}
     */
    public static function reference(Matter $matter): array
    {
        return [
            'id' => McpIds::encode(McpIds::MATTER, (int) $matter->getKey()),
            'code' => (string) $matter->code,
            'title' => (string) $matter->title,
            'url' => AdminUrls::matter($matter),
        ];
    }

    /**
     * Cần nạp sẵn: `client`, `leadLawyer`, `matterType.stages`.
     *
     * @return array<string, mixed>
     */
    public static function row(Matter $matter): array
    {
        /** @var Client|null $client */
        $client = self::loaded($matter, 'client');
        /** @var User|null $lead */
        $lead = self::loaded($matter, 'leadLawyer');
        /** @var MatterType|null $type */
        $type = self::loaded($matter, 'matterType');

        if ($type !== null) {
            // Nhãn giai đoạn tra trên `stages` đã nạp (MatterType::stage()); chưa nạp là một truy vấn.
            self::loaded($type, 'stages');
        }

        return [
            'id' => McpIds::encode(McpIds::MATTER, (int) $matter->getKey()),
            'code' => (string) $matter->code,
            'title' => (string) $matter->title,
            'matter_type' => $type?->name,
            'stage' => $matter->stage,
            'stage_label' => $matter->currentStage()?->label,
            'client_name' => $client?->name,
            'lead_lawyer' => $lead?->name,
            'is_open' => $matter->isOpen(),
            'opened_at' => $matter->opened_at?->toDateString(),
            'closed_at' => $matter->closed_at?->toDateString(),
            'url' => AdminUrls::matter($matter),
        ];
    }

    /**
     * Cần nạp sẵn: như {@see self::row()}, cộng `team` (kèm pivot) và `parties` (mọi bên chưa xoá).
     *
     * @return array<string, mixed>
     */
    public static function detail(Matter $matter): array
    {
        $row = self::row($matter);

        /** @var Client|null $client */
        $client = self::loaded($matter, 'client');
        /** @var Collection<int, User> $team */
        $team = self::loaded($matter, 'team');
        $parties = self::loaded($matter, 'parties');

        return [
            ...$row,
            'court_name' => $matter->court_name,
            'case_number' => $matter->case_number,
            // Chỉ cờ: nội dung `description_internal` không bao giờ rời hệ thống qua MCP (R4).
            'has_internal_note' => filled($matter->description_internal),
            'client' => $client === null ? null : ClientPresenter::present($client),
            'team' => $team->map(function (User $member): array {
                $role = self::loaded($member, 'pivot')?->role_in_matter;

                return [
                    ...StaffPresenter::present($member),
                    'role_in_matter' => $role?->value,
                    'role_in_matter_label' => $role?->label(),
                ];
            })->values()->all(),
            'parties' => PartyPresenter::presentAll($parties),
        ];
    }
}
