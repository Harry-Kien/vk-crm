{{--
    Bản xem trước đúng như khách sẽ thấy (SPEC §7.3). Bốn phần đúng theo SPEC §8.3 mục 3 (dòng
    thời gian trên portal): chuyện gì đã xảy ra (nhãn giai đoạn + nội dung công bố), tiếp theo là
    gì, anh/chị cần làm gì, dự kiến có tin tiếp theo khi nào. KHÔNG bao giờ nhận/hiển thị
    internal_note — xem docblock BuildsStageUpdateSchema::previewField().

    Kiểu dáng viết thẳng bằng `style=`, không bằng lớp Tailwind — bắt buộc trong dự án này (`stage/
    stage-07`, M6.5 Task 10). Không có bước dựng CSS (CLAUDE.md); panel nạp
    `vendor/filament/filament/dist/theme.css` biên dịch sẵn, chỉ chứa các lớp `fi-*` của chính
    Filament — không một lớp tiện ích Tailwind nào. Bản trước của view này dùng `rounded-xl`,
    `border-gray-200`, `text-amber-600`… nên khung không có viền, và câu cảnh báo "Sẽ KHÔNG công
    bố" không có màu — cùng lỗi mà `StageLogsRelationManager::renderInternalNote()`/
    `renderPublicContent()` đã sửa ở Task 7 (xem docblock hai hàm đó). Màu lấy qua biến CSS thật
    của Filament (`var(--gray-500)`, `var(--warning-600)`), không mã màu cứng — cùng thành ngữ.

    `$showStageLabel` (bool, do ACTION gọi quyết định — không phải view tự đoán từ $stageLabel):
    khối nhãn giai đoạn CHỈ vẽ khi action đang thật sự CHUYỂN giai đoạn (TransitionStageAction).
    `AddUpdateAction` luôn gọi với to_stage == giai đoạn hiện tại (SPEC §6.3: "thêm cập nhật" không
    đổi giai đoạn), nên không có "giai đoạn MỚI" nào để vẽ — và cổng khách
    (`MatterProgress::presentLog()`) cũng không vẽ nhãn cho một dòng không đổi giai đoạn
    (`matter-progress.blade.php`, `@if (filled($log['moved_to']))`). Bản trước của view này vẽ nhãn
    vô điều kiện ở cả hai Action, lệch khỏi "đúng như khách sẽ thấy" mà SPEC §7.3 đòi.

    `stage/stage-02` (disputed, xử bằng cách sửa lời trên bản xem trước): tắt công bố KHÔNG giấu
    được nhãn giai đoạn mới — `TransitionMatterStage` vẫn ghi `matters.stage` vô điều kiện (SPEC
    §6.2 bước 5), chỉ dòng CẬP NHẬT trên timeline (`stage_logs.is_published`) là phụ thuộc `publish`.
    Câu cũ ("Sẽ KHÔNG công bố cho khách với lựa chọn hiện tại.") chỉ nói về dòng cập nhật, không
    nói khách vẫn đọc được nhãn giai đoạn mới ở khối "Tình trạng hiện tại" và trên thẻ hồ sơ ngay
    lập tức — dễ khiến luật sư tưởng nhầm là giấu được CẢ việc chuyển giai đoạn. Khi bản xem trước
    đang thật sự đổi giai đoạn ($showStageLabel && có $stageLabel), câu MỚI nêu đích danh giai đoạn
    đó; nếu không (chưa chọn xong, hoặc "Thêm cập nhật" không đổi giai đoạn) thì giữ câu chung.
--}}
@php
    $formattedExpectedDate = filled($expectedNextUpdateAt)
        ? \Illuminate\Support\Carbon::parse($expectedNextUpdateAt)->format('d/m/Y')
        : null;

    $borderStyle = 'border:1px solid color-mix(in srgb, var(--gray-500) 35%, transparent)';
@endphp

<div style="border-radius:0.75rem;{{ $borderStyle }};padding:1rem;display:flex;flex-direction:column;gap:0.75rem">
    <p style="font-size:0.875rem;font-weight:600;color:color-mix(in srgb, var(--gray-500) 90%, transparent)">
        {{ __('matters.transition_form.preview_heading') }}
    </p>

    @unless($willPublish)
        <p style="font-size:0.875rem;font-weight:500;color:var(--warning-600)">
            @if($showStageLabel && filled($stageLabel))
                {{ __('matters.transition_form.preview_not_publishing_with_stage_change', ['stage' => $stageLabel]) }}
            @else
                {{ __('matters.transition_form.preview_not_publishing') }}
            @endif
        </p>
    @endunless

    <div style="border-radius:0.5rem;{{ $borderStyle }};padding:0.75rem;font-size:0.875rem;display:flex;flex-direction:column;gap:0.5rem">
        @if($showStageLabel)
            <p style="font-size:1rem;font-weight:600">
                {{ $stageLabel ?? __('matters.transition_form.preview_no_stage') }}
            </p>
        @endif

        <p style="white-space:pre-line">
            {{ filled($publicContent) ? $publicContent : __('matters.transition_form.preview_empty_public_content') }}
        </p>

        @if(filled($nextStep))
            <p>
                <span style="font-weight:600">{{ __('matters.transition_form.preview_next_step') }}:</span>
                {{ $nextStep }}
            </p>
        @endif

        <p>
            <span style="font-weight:600">{{ __('matters.transition_form.preview_client_action') }}:</span>
            {{ filled($clientAction) ? $clientAction : __('matters.transition_form.preview_no_client_action') }}
        </p>

        @if($formattedExpectedDate)
            <p>
                <span style="font-weight:600">{{ __('matters.transition_form.preview_expected_next_update') }}:</span>
                {{ $formattedExpectedDate }}
            </p>
        @endif
    </div>
</div>
