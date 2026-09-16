<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Models\Client;
use App\Models\ClientUser;
use Filament\Resources\Pages\CreateRecord;
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

        return $data;
    }
}
