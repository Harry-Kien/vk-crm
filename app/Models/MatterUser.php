<?php

namespace App\Models;

use App\Enums\MatterRole;
use App\Models\Concerns\RestrictedToClientPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MatterUser extends Pivot
{
    use RestrictedToClientPortal;

    protected $table = 'matter_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role_in_matter' => MatterRole::class];
    }

    /**
     * Portal không bao giờ đọc bảng phân công nhân sự. Lưu ý: quan hệ belongsToMany của
     * Matter::team() đọc thẳng bảng matter_user qua join chứ không dựng truy vấn của model
     * Pivot này, nên chặn ở đây không ảnh hưởng đội ngũ vụ việc.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
