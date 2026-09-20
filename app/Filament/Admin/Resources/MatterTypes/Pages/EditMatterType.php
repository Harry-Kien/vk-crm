<?php

namespace App\Filament\Admin\Resources\MatterTypes\Pages;

use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMatterType extends EditRecord
{
    protected static string $resource = MatterTypeResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
