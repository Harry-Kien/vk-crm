<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Matters\MatterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    // Trang chi tiết đầy đủ (tabs Tổng quan/Tiến độ/Danh mục hồ sơ/...) là Task 6. Ở task này
    // trang chỉ tồn tại để getRecordRouteBindingEloquentQuery() có route mà kiểm tra: mở thẳng
    // URL của một vụ ngoài quyền phải trả 404, không phải 403 hay 200.
}
