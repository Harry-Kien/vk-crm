<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Matters\MatterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;

    /**
     * Nút "Mở vụ việc mới" (SPEC §13, tiêu chí M3). `CreateAction` tự ẩn khi
     * `MatterResource::canCreate()` sai, tức khi `MatterPolicy::create` từ chối — kế toán và trợ
     * lý không có `matter.create` (SPEC §5) nên không thấy nút này, và `CreateMatter::mount()`
     * cũng tự `abort(403)` nếu ai đó gõ thẳng URL.
     *
     * Nút mở một TRANG riêng chứ không phải modal: form mở vụ việc có repeater các bên và bảng kết
     * quả kiểm tra xung đột lợi ích, quá lớn cho một modal.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('matters.create_form.create_heading')),
        ];
    }
}
