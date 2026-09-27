<?php

use Illuminate\Support\Facades\Lang;

/**
 * Final review C-M3/C-M4: câu tiếng Việt không được hứa điều hệ thống chưa làm. Hôm nay: không có
 * thư nào gửi khi từ chối một giấy tờ (thư đến ở M6 Task 3), và không màn hình nào khôi phục một
 * tài liệu hay một hồ sơ đã xoá. Mỗi khoá dưới đây từng hứa một trong hai điều đó.
 */
it('does not promise an email that the rejection flow does not send', function (string $key) {
    expect(mb_strtolower(__($key)))
        ->not->toContain('được gửi kèm email')
        ->not->toContain('đã gửi yêu cầu')
        ->not->toContain('báo cho khách biết');
})->with([
    'checklist.tab.actions.reject_heading',
    'checklist.tab.actions.reject_success',
    'checklist.tab.fields.rejection_reason_help',
]);

it('does not promise a restore screen that does not exist', function (string $key) {
    expect(mb_strtolower(__($key)))->not->toContain('khôi phục');
})->with([
    'documents.publish.trashed',
    'documents.publish.matter_unavailable',
    'documents.lifecycle.trashed',
    'documents.lifecycle.matter_unavailable',
    'checklist.review.matter_unavailable',
]);

it('does not tell the client they will be notified by the office when nothing notifies them', function () {
    expect(__('portal_submit.done.body', ['name' => 'x']))->not->toContain('báo anh/chị');
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
