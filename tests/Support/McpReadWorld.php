<?php

namespace Tests\Support;

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;

/**
 * Bộ dữ liệu R3 dùng chung cho test tool đọc của M11 (Review Focus 2, "rò rỉ chéo vụ việc"): một
 * vụ trong tập MCP của `$lead`, và đúng ba vụ mà `$lead` hỏi bằng id phải nhận câu trả lời y như một
 * id không tồn tại —
 *
 *  - `$otherTeam`: vụ thường, đã bật AI, của đội khác (`$outsider` phụ trách);
 *  - `$restricted`: vụ hạn chế, đã bật AI, do CHÍNH `$lead` phụ trách (web cho `$lead` xem);
 *  - `$denied`: vụ thường, chưa bật AI (`ai_access = denied`), do chính `$lead` phụ trách.
 *
 * `$assistant` thuộc đội của `$matter`. Mỗi vụ một tiêu đề và tên khách riêng có một "dấu" chữ
 * hiếm, để test tìm kiếm nhắm đúng một vụ.
 */
final class McpReadWorld
{
    public function __construct(
        public readonly User $lead,
        public readonly User $assistant,
        public readonly User $outsider,
        public readonly User $admin,
        public readonly User $accountant,
        public readonly Matter $matter,
        public readonly Matter $otherTeam,
        public readonly Matter $restricted,
        public readonly Matter $denied,
    ) {}

    public static function build(): self
    {
        $lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Phụ Trách']);
        $assistant = User::factory()->withRole(Role::Assistant)->create();
        $outsider = User::factory()->withRole(Role::Lawyer)->create();
        $admin = User::factory()->admin()->create();
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $matter = Matter::factory()->aiAccessAllowed()->create([
            'lead_lawyer_id' => $lead->id,
            'title' => 'Tranh chấp hợp đồng Quokkavan',
        ]);
        $matter->addTeamMember($assistant, MatterRole::Assistant);

        $otherTeam = Matter::factory()->aiAccessAllowed()->create([
            'lead_lawyer_id' => $outsider->id,
            'title' => 'Tranh chấp đất đai Narwhalix',
        ]);

        $restricted = Matter::factory()->aiAccessAllowed()->restricted()->create([
            'lead_lawyer_id' => $lead->id,
            'title' => 'Ly hôn bí mật Pangolinor',
        ]);

        $denied = Matter::factory()->create([
            'lead_lawyer_id' => $lead->id,
            'title' => 'Thừa kế chưa đồng ý Axolotlis',
        ]);

        return new self($lead, $assistant, $outsider, $admin, $accountant, $matter, $otherTeam, $restricted, $denied);
    }

    /**
     * Ba vụ mà `$lead` KHÔNG được thấy qua MCP, theo tên của Review Focus 2.
     *
     * @return array<string, Matter>
     */
    public function hiddenFromLead(): array
    {
        return ['other team' => $this->otherTeam, 'restricted' => $this->restricted, 'denied' => $this->denied];
    }
}
