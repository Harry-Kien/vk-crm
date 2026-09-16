{{--
    Bảng kết quả kiểm tra xung đột lợi ích, hiện NGAY TRONG form tạo vụ việc (SPEC §6.10, đoạn
    "Giao diện").

    **Ranh giới lộ thông tin có chủ đích — đừng thêm cột nào vào bảng này.** Dữ liệu vào đây đi qua
    App\Support\ConflictMatch (readonly DTO) và chỉ mang đúng: mã hồ sơ, tên loại vụ việc, vai và
    tên của bên trùng, tiêu chí đã khớp, mức. Đây là ngoại lệ có chủ đích duy nhất của quy tắc phân
    quyền trong toàn hệ thống: đủ để nhận ra xung đột, KHÔNG đủ để lộ bí mật hồ sơ khác — người đang
    nhìn bảng này thường không có quyền xem những hồ sơ đó. View này không nhận model, không truy
    vấn, và không được phép làm cả hai việc đó.
--}}
<div
    @class([
        'rounded-xl border p-4 space-y-3',
        'border-danger-300 bg-danger-50 dark:border-danger-700 dark:bg-danger-950/40' => $level === 'red',
        'border-warning-300 bg-warning-50 dark:border-warning-700 dark:bg-warning-950/40' => $level !== 'red' && $requiresAttention,
        'border-success-300 bg-success-50 dark:border-success-700 dark:bg-success-950/40' => $level !== 'red' && ! $requiresAttention,
    ])
>
    <p class="text-base font-bold text-gray-950 dark:text-white">
        @if($level === 'red')
            {{ __('matters.conflict.heading_red') }}
        @elseif($requiresAttention)
            {{ __('matters.conflict.heading_attention') }}
        @else
            {{ __('matters.conflict.heading_clear') }}
        @endif
    </p>

    @if(count($matches) > 0)
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('matters.conflict.intro') }}</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-gray-600 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pr-3 font-medium">{{ __('matters.conflict.column_matter_code') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('matters.conflict.column_matter_type') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('matters.conflict.column_party_role') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('matters.conflict.column_party_name') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('matters.conflict.column_tier') }}</th>
                        <th class="py-1 font-medium">{{ __('matters.conflict.column_level') }}</th>
                    </tr>
                </thead>
                <tbody class="text-gray-900 dark:text-gray-100">
                    @foreach($matches as $match)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1 pr-3 font-semibold">{{ $match['matter_code'] }}</td>
                            <td class="py-1 pr-3">{{ $match['matter_type_name'] }}</td>
                            <td class="py-1 pr-3">{{ $match['party_role'] }}</td>
                            <td class="py-1 pr-3">{{ $match['party_name'] }}</td>
                            <td class="py-1 pr-3">{{ $match['tier'] }}</td>
                            <td class="py-1 font-semibold">{{ $match['level'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('matters.conflict.boundary_note') }}</p>
    @else
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('matters.conflict.no_matches') }}</p>
    @endif

    @if(count($incompleteParties) > 0)
        <p class="text-sm font-medium text-warning-700 dark:text-warning-400">
            {{ __('matters.conflict.incomplete', ['names' => implode(', ', $incompleteParties)]) }}
        </p>
    @endif
</div>
