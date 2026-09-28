<?php

use Illuminate\Support\Facades\Lang;

/**
 * Final review C-M3/C-M4 (còn hiệu lực cho `reject_heading` — nó không nhắc tới việc khôi phục
 * nào, chỉ giữ trong dataset vì lịch sử chung với hai khoá kia): câu tiếng Việt không được hứa
 * điều hệ thống chưa làm, và không màn hình nào khôi phục một tài liệu hay một hồ sơ đã xoá.
 */
it('does not promise a restore screen that does not exist', function (string $key) {
    expect(mb_strtolower(__($key)))->not->toContain('khôi phục');
})->with([
    'checklist.tab.actions.reject_heading',
    'documents.publish.trashed',
    'documents.publish.matter_unavailable',
    'documents.lifecycle.trashed',
    'documents.lifecycle.matter_unavailable',
    'checklist.review.matter_unavailable',
]);

/**
 * M6 Task 3: thư `client.document_rejected` giờ CÓ THẬT (`App\Mail\Client\DocumentRejected`,
 * `App\Listeners\SendChecklistItemRejectedNotification`). Câu chữ M6.5 hạ xuống vì thư chưa có
 * (final review C-M3/C-M4 — nói thẳng "Hệ thống chưa gửi email cho việc này") giờ SAI theo hướng
 * NGƯỢC LẠI: nó giấu một việc hệ thống ĐÃ làm. Hai khoá này phải nói đúng sự thật mới.
 */
it('no longer denies the rejection email that now exists, and says it plainly', function (string $key) {
    expect(mb_strtolower(__($key)))
        ->not->toContain('chưa gửi email')
        ->toContain('email');
})->with([
    'checklist.tab.actions.reject_success',
    'checklist.tab.fields.rejection_reason_help',
]);

/**
 * M6 Task 3: khi khách nộp giấy tờ mà văn phòng từ chối, khách giờ nhận được một email nêu lý do —
 * câu "cảm ơn đã gửi" (SPEC §8.4, hiện ngay sau khi nộp) nên nói đúng điều đó, thay vì chỉ hứa một
 * cách chung chung "văn phòng sẽ liên hệ khi cần" (câu cũ, không nói rõ bằng cách nào).
 */
it('tells the client an email will explain if something is wrong with what they submitted', function () {
    expect(mb_strtolower(__('portal_submit.done.body', ['name' => 'x'])))->toContain('email');
});

it('only claims the client sees the new stage when the matter is on the portal', function () {
    expect(__('matters.transition_form.preview_not_publishing_with_stage_change', ['stage' => 'X']))
        ->toContain('Nếu vụ việc đang bật công bố portal');
});

it('checks keys that actually exist, so a typo cannot make these tests pass on the raw key', function (string $key) {
    expect(Lang::has($key))->toBeTrue();
})->with([
    'checklist.tab.actions.reject_heading',
    'checklist.tab.actions.reject_success',
    'checklist.tab.fields.rejection_reason_help',
    'documents.publish.trashed',
    'documents.publish.matter_unavailable',
    'documents.lifecycle.trashed',
    'documents.lifecycle.matter_unavailable',
    'checklist.review.matter_unavailable',
    'portal_submit.done.body',
    'matters.transition_form.preview_not_publishing_with_stage_change',
]);
