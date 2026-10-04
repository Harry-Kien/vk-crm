{{--
    Trang "Theo dõi đội ngũ" (M13) — luật ở docblock `App\Filament\Admin\Pages\TeamOverview`; tệp
    này chỉ vẽ ra: câu phạm vi cố định (R4), bảng N1–N10 (N11 chỉ ở trang của một người), và khối
    thu gọn "Cách tính các con số" (R6) liệt kê câu giải thích của mọi cột. Style nội tuyến trên biến
    CSS của Filament (dự án không có bước dựng CSS).
--}}
<x-filament-panels::page>
    @include('filament.admin.pages.performance-scope-note')

    {{ $this->table }}

    @include('filament.admin.pages.performance-explanations', ['explanations' => $this->explanations()])
</x-filament-panels::page>
