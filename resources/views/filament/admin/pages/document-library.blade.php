{{--
    Trang "Tài liệu" (làn tl) — luật ở docblock `App\Filament\Admin\Pages\DocumentLibrary`; tệp này chỉ
    vẽ bảng. Lời giới thiệu nằm trong `->description()` của bảng.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
