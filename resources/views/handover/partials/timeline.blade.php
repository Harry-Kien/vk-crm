{{--
    MUC-LUC.pdf — khối 3: TOÀN BỘ dòng tiến độ ĐÃ CÔNG BỐ, cũ trước mới sau. `$timeline` là hình
    chiếu hẹp do `RenderHandoverIndex::timeline()` dựng — không có `internal_note`.
--}}
<h2>{{ __('handover.pdf.timeline_heading') }}</h2>
@if ($timeline->isEmpty())
    <p class="muted">{{ __('handover.pdf.timeline_empty') }}</p>
@else
    @foreach ($timeline as $row)
        <div class="entry">
            <div class="entry-head">{{ $row['date'] }} — {{ $row['stage'] }}</div>
            @if (filled($row['public_content']))
                <div class="entry-body">{{ $row['public_content'] }}</div>
            @endif
            @if (filled($row['next_step']))
                <div class="entry-body"><strong>{{ __('handover.pdf.timeline.next_step') }}</strong> {{ $row['next_step'] }}</div>
            @endif
            @if (filled($row['client_action']))
                <div class="entry-body"><strong>{{ __('handover.pdf.timeline.client_action') }}</strong> {{ $row['client_action'] }}</div>
            @endif
        </div>
    @endforeach
@endif
