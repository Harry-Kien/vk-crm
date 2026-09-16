<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Models\Client;
use App\Models\ClientUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditClientUser extends EditRecord
{
    protected static string $resource = ClientUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Cùng lý do CreateClientUser::mutateFormDataBeforeCreate() (review fix round 1, Important
     * #2): việc sửa lại có thể đổi client_id sang một khách hàng ngoài tầm nhìn qua một request
     * bị chỉnh sửa, VisibleClientOptions chỉ hạn chế những gì ô chọn hiển thị.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('client_id', $data)) {
            $client = Client::query()->find($data['client_id']);

            abort_if($client === null, 403);

            Gate::authorize('create', [ClientUser::class, $client]);
        }

        return $data;
    }
}
