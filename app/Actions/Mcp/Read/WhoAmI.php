<?php

namespace App\Actions\Mcp\Read;

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Models\User;

/** Kết quả của {@see ReadWhoAmI} — dữ liệu cho `WhoAmIPresenter`, không phải đầu ra MCP. */
final readonly class WhoAmI
{
    /**
     * @param  list<Role>  $roles
     */
    public function __construct(
        public User $user,
        public array $roles,
        public int $matterCount,
        public bool $partyNamesPseudonymised,
        public AiAccessMode $mode,
    ) {}
}
