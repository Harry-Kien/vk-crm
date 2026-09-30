{{-- MUC-LUC.pdf — khối 2: danh sách tài liệu đánh số, CÙNG số với tên entry trong zip (`HandoverEntry`). --}}
<h2>{{ __('handover.pdf.documents_heading') }}</h2>
@if ($entries->isEmpty())
    <p class="muted">{{ __('handover.pdf.documents_empty') }}</p>
@else
    <p class="muted">{{ __('handover.pdf.documents_intro') }}</p>
    <table>
        <thead>
            <tr>
                <th style="width: 7%;">{{ __('handover.pdf.columns.number') }}</th>
                <th style="width: 30%;">{{ __('handover.pdf.columns.title') }}</th>
                <th style="width: 19%;">{{ __('handover.pdf.columns.group') }}</th>
                <th style="width: 32%;">{{ __('handover.pdf.columns.file') }}</th>
                <th style="width: 12%;">{{ __('handover.pdf.columns.date') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td>{{ str_pad((string) $entry->number, 2, '0', STR_PAD_LEFT) }}</td>
                    <td>{{ $entry->title }}</td>
                    <td>{{ $entry->group->label() }}</td>
                    <td>{{ $entry->zipPath }}</td>
                    <td>{{ $entry->date?->format('d/m/Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
