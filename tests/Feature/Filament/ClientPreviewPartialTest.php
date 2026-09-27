<?php

/**
 * `resources/views/filament/client-preview.blade.php` — bản xem trước "đúng như khách sẽ thấy"
 * (SPEC §7.3), dùng chung bởi `TransitionStageAction` và `AddUpdateAction`
 * (`BuildsStageUpdateSchema::previewField()`).
 *
 * `stage/stage-07` (ux, M6.5 Task 10): view này dùng lớp Tailwind (`rounded-xl`, `text-amber-600`,
 * …) không có trong `theme.css` được phục vụ (không có bước dựng CSS, CLAUDE.md) — khung không có
 * viền, cảnh báo "Sẽ KHÔNG công bố" không có màu. Sửa bằng `style=` nội tuyến, cùng thành ngữ
 * `StageLogsRelationManager::renderInternalNote()`/`renderPublicContent()` — test này dùng lại
 * đúng các helper `classTokens()`/`colourVariablesIn()`/`unregisteredColourVariables()` của
 * `tests/Pest.php` mà `StageLogPaintingTest` đã dựng cho đúng mục đích này.
 *
 * `stage/stage-02` (disputed, xử bằng cách sửa lời trên bản xem trước): tắt công bố KHÔNG giấu
 * được nhãn giai đoạn mới trên cổng khách — `matters.stage` vẫn ghi vô điều kiện (SPEC §6.2 bước
 * 5), chỉ dòng CẬP NHẬT trên dòng thời gian là bị giữ lại. Câu cũ ("Sẽ KHÔNG công bố cho khách với
 * lựa chọn hiện tại.") không nói điều đó, nên luật sư có thể tưởng nhầm là giấu được CẢ giai đoạn.
 * Câu MỚI xuất hiện khi bản xem trước đang thật sự đổi giai đoạn (`$showStageLabel` và có
 * `$stageLabel`): "Dòng này không công bố. Khách vẫn thấy giai đoạn mới: …".
 *
 * `stage-07` nửa còn lại: bản xem trước của "Thêm cập nhật" (to_stage luôn bằng giai đoạn hiện
 * tại — không có "giai đoạn MỚI" nào cả) từng vẽ nhãn giai đoạn vô điều kiện, trong khi cổng khách
 * (`MatterProgress::presentLog()`) không vẽ nhãn cho một dòng không đổi giai đoạn. `$showStageLabel`
 * (do action gọi quyết định, không phải view tự đoán) tắt hẳn khối nhãn cho trường hợp đó.
 */
function renderClientPreview(array $data): string
{
    return view('filament.client-preview', [
        'stageLabel' => null,
        'showStageLabel' => true,
        'publicContent' => null,
        'nextStep' => null,
        'clientAction' => null,
        'expectedNextUpdateAt' => null,
        'willPublish' => true,
        ...$data,
    ])->render();
}

/**
 * Không dùng lại `classTokens()` của `StageLogPaintingTest` — hàm đó khai báo CỤC BỘ trong chính
 * tệp test kia, không phải trong `tests/Pest.php` (chỉ `colourVariablesIn()`/
 * `unregisteredColourVariables()` mới là hàm dùng chung toàn cục). `class="` không xuất hiện MỘT
 * LẦN NÀO là đủ để khẳng định "không phát ra lớp CSS nào" cho view này.
 */
it('emits no CSS class at all, and paints with registered colour variables only', function () {
    $html = renderClientPreview(['willPublish' => false, 'stageLabel' => 'Đang thu thập giấy tờ']);

    expect($html)->not->toContain('class="')
        ->and(colourVariablesIn($html))->not->toBeEmpty()
        ->and(unregisteredColourVariables($html))->toBe([]);
});

it('shows the new sentence naming the new stage when not publishing a real stage change', function () {
    $html = renderClientPreview([
        'willPublish' => false,
        'showStageLabel' => true,
        'stageLabel' => 'Đang thu thập giấy tờ',
    ]);

    expect($html)->toContain(__('matters.transition_form.preview_not_publishing_with_stage_change', [
        'stage' => 'Đang thu thập giấy tờ',
    ]))
        ->and($html)->not->toContain(__('matters.transition_form.preview_not_publishing'))
        ->and($html)->toContain('style=')
        ->and($html)->toContain('var(--warning-600)');
});

/** Chưa chọn giai đoạn đích (mount ban đầu của TransitionStageAction) — không có gì để đặt tên, giữ câu chung. */
it('falls back to the generic sentence when no target stage has been picked yet', function () {
    $html = renderClientPreview(['willPublish' => false, 'showStageLabel' => true, 'stageLabel' => null]);

    expect($html)->toContain(__('matters.transition_form.preview_not_publishing'))
        ->and($html)->not->toContain('giai đoạn mới:');
});

/** AddUpdateAction: to_stage == giai đoạn hiện tại luôn — không có "giai đoạn mới" nào để cảnh báo. */
it('uses the generic sentence, never the stage-change one, when the action never changes stage', function () {
    $html = renderClientPreview([
        'willPublish' => false,
        'showStageLabel' => false,
        'stageLabel' => null,
    ]);

    expect($html)->toContain(__('matters.transition_form.preview_not_publishing'))
        ->and($html)->not->toContain('giai đoạn mới:');
});

it('shows no warning sentence at all when publishing', function () {
    $html = renderClientPreview(['willPublish' => true, 'showStageLabel' => true, 'stageLabel' => 'Đang thu thập giấy tờ']);

    expect($html)->not->toContain(__('matters.transition_form.preview_not_publishing'))
        ->and($html)->not->toContain('giai đoạn mới:');
});

it('draws the stage label block when showStageLabel is true', function () {
    $html = renderClientPreview(['showStageLabel' => true, 'stageLabel' => 'Đang thu thập giấy tờ']);

    expect($html)->toContain('Đang thu thập giấy tờ');
});

/**
 * `stage/stage-07`: "Thêm cập nhật" không đổi giai đoạn — bản xem trước của nó không được vẽ nhãn
 * giai đoạn nào cả, kể cả nhãn "Chưa chọn giai đoạn" (đó là placeholder RIÊNG của
 * TransitionStageAction lúc chưa chọn xong, không áp dụng ở đây).
 */
it('draws no stage label block at all when showStageLabel is false', function () {
    $html = renderClientPreview(['showStageLabel' => false, 'stageLabel' => null]);

    expect($html)->not->toContain(__('matters.transition_form.preview_no_stage'));
});

it('still shows the no-stage-chosen placeholder when showStageLabel is true and nothing is chosen', function () {
    $html = renderClientPreview(['showStageLabel' => true, 'stageLabel' => null]);

    expect($html)->toContain(__('matters.transition_form.preview_no_stage'));
});
