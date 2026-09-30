<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Actions\Portal\CreatePortalAccount;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CreateClientUser extends CreateRecord
{
    protected static string $resource = ClientUserResource::class;

    /**
     * Review fix round 1, Important #2: VisibleClientOptions chỉ hạn chế những gì Ô CHỌN hiển
     * thị — một request bị chỉnh sửa (bỏ qua UI) vẫn có thể gửi thẳng client_id ngoài tầm nhìn.
     * Chặn thật ở đây, đúng lúc client_id đã biết, bằng chính ClientUserPolicy::create() với
     * $client làm ngữ cảnh (xem docblock của ability đó).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $client = Client::query()->find($data['client_id'] ?? null);

        // client_id là trường bắt buộc trên form; nếu không khớp một Client thật (id giả mạo
        // ngoài danh sách VisibleClientOptions đã gửi), không cho lọt qua như "chưa biết
        // client" (nhánh đó của policy cho phép chung) — từ chối thẳng.
        abort_if($client === null, 403);

        Gate::authorize('create', [ClientUser::class, $client]);

        // Task 7 (R12, phát hiện `intake/intake-05`): must_change_password LUÔN true khi tạo —
        // không còn công tắc trên form (ClientUserForm đã gỡ Toggle này) để nhân sự tắt nó ngay
        // lúc tạo. Ép lại ở đây, KHÔNG ĐỌC bất kỳ gì $data mang cho khoá này (kể cả khi có), cùng
        // thành ngữ phòng thủ hai lớp đã dùng cho client_id ở trên: field không còn tồn tại thì
        // đủ để chặn qua UI, còn dòng này chặn cả một request đã "chỉnh sửa tay".
        $data['must_change_password'] = true;

        return $data;
    }

    /**
     * M8 Task 3 (SPEC §10.6, `portal_account_created`): tạo qua Action để có dòng nhật ký tường
     * minh cạnh dòng `created` của `LogsActivity` — xem {@see CreatePortalAccount}.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return app(CreatePortalAccount::class)->handle($data, $actor);
    }
}
