{{--
    Bản xem trước đúng như khách sẽ thấy (SPEC §7.3). Bốn phần đúng theo SPEC §8.3 mục 3 (dòng
    thời gian trên portal): chuyện gì đã xảy ra (nhãn giai đoạn + nội dung công bố), tiếp theo là
    gì, anh/chị cần làm gì, dự kiến có tin tiếp theo khi nào. KHÔNG bao giờ nhận/hiển thị
    internal_note — xem docblock BuildsStageUpdateSchema::previewField().
--}}
@php
    $formattedExpectedDate = filled($expectedNextUpdateAt)
        ? \Illuminate\Support\Carbon::parse($expectedNextUpdateAt)->format('d/m/Y')
        : null;
@endphp

<div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
        {{ __('matters.transition_form.preview_heading') }}
    </p>

    @unless($willPublish)
        <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
            {{ __('matters.transition_form.preview_not_publishing') }}
        </p>
    @endunless

    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 text-sm space-y-2">
        <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ $stageLabel ?? __('matters.transition_form.preview_no_stage') }}
        </p>

        <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">
            {{ filled($publicContent) ? $publicContent : __('matters.transition_form.preview_empty_public_content') }}
        </p>

        @if(filled($nextStep))
            <p>
                <span class="font-medium">{{ __('matters.transition_form.preview_next_step') }}:</span>
                {{ $nextStep }}
            </p>
        @endif

        <p>
            <span class="font-medium">{{ __('matters.transition_form.preview_client_action') }}:</span>
            {{ filled($clientAction) ? $clientAction : __('matters.transition_form.preview_no_client_action') }}
        </p>

        @if($formattedExpectedDate)
            <p>
                <span class="font-medium">{{ __('matters.transition_form.preview_expected_next_update') }}:</span>
                {{ $formattedExpectedDate }}
            </p>
        @endif
    </div>
</div>
