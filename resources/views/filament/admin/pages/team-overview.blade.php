{{--
    Trang "Theo dõi đội ngũ" (M13) — luật ở docblock `App\Filament\Admin\Pages\TeamOverview`; tệp
    này chỉ vẽ ra: câu phạm vi cố định (R4), dòng "Việc cần giao lại: n" và dòng "Hồ sơ cần phân công
    lại: n" (làn tk, vòng sửa polish 1 — chỉ cho người giao lại được), bảng N1–N10 (N11 chỉ ở trang
    của một người), và khối thu gọn "Cách tính các con số" (R6) liệt kê câu giải thích của mọi cột.
    Style nội tuyến trên biến CSS của Filament (dự án không có bước dựng CSS).
--}}
<x-filament-panels::page>
    @include('filament.admin.pages.performance-scope-note')

    @php($reassign = $this->needsReassignment())
    @if ($reassign !== null)
        <p style="margin:0;font-size:0.875rem;line-height:1.5rem;color:var(--gray-600)">
            <a href="{{ $reassign['url'] }}" style="{{ $reassign['count'] > 0 ? 'color:var(--danger-600);' : '' }}font-weight:600">{{ __('tasks.workload.needs_reassignment', ['count' => $reassign['count']]) }}</a>
            — {{ __('tasks.workload.needs_reassignment_hint') }}
        </p>
    @endif

    @php($stuckSeats = $this->seatsNeedingReassignment())
    @if ($stuckSeats !== null)
        <p style="margin:0;font-size:0.875rem;line-height:1.5rem;color:var(--gray-600)">
            <span style="{{ $stuckSeats['count'] > 0 ? 'color:var(--danger-600);' : '' }}font-weight:600">{{ __('tasks.workload.seats_needing_reassignment', ['count' => $stuckSeats['count']]) }}</span>
            @foreach ($stuckSeats['items'] as $item)
                <a href="{{ $item['url'] }}" style="font-weight:600">{{ $item['code'] }}</a>@if (! $loop->last),@endif
            @endforeach
            @if ($stuckSeats['count'] > count($stuckSeats['items'])), …@endif
            — {{ __('tasks.workload.seats_needing_reassignment_hint') }}
        </p>
    @endif

    {{ $this->table }}

    @include('filament.admin.pages.performance-explanations', ['explanations' => $this->explanations()])
</x-filament-panels::page>
