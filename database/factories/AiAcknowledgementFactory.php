<?php

namespace Database\Factories;

use App\Models\AiAcknowledgement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lời cam kết R12 ĐÚNG phiên bản chính sách hiện hành (`config('vkcrm.mcp.policy_version')`) — dựng
 * trạng thái cho test, không qua `AcknowledgeAiPolicy` (không ghi audit).
 *
 * @extends Factory<AiAcknowledgement>
 */
class AiAcknowledgementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'policy_version' => (string) config('vkcrm.mcp.policy_version'),
            'accepted_at' => now(),
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Trình duyệt thử',
        ];
    }
}
