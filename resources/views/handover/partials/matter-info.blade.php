{{-- MUC-LUC.pdf — khối 1: thông tin vụ việc (xem handover/index.blade.php). `$matterInfo` đã bỏ dòng rỗng. --}}
<h2>{{ __('handover.pdf.matter_heading') }}</h2>
<table class="info">
    @foreach ($matterInfo as $label => $value)
        <tr>
            <td class="label">{{ $label }}</td>
            <td>{{ $value }}</td>
        </tr>
    @endforeach
</table>
