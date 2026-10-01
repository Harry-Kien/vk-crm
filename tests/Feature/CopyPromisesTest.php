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
 *
 * Câu này hiện VÔ ĐIỀU KIỆN sau mỗi lần nộp, kể cả trên vụ đã đóng còn công bố trên cổng (rà soát
 * cuối làn, I1). Test này chỉ ghim câu chữ; lời hứa được đo bằng đường đi thật — nộp trên trang
 * nộp, từ chối trên màn hình duyệt, thư tới khách — ở `tests/Feature/Portal/SubmitDocumentTest.php`.
 */
it('tells the client an email will explain if something is wrong with what they submitted', function () {
    expect(mb_strtolower(__('portal_submit.done.body', ['name' => 'x'])))->toContain('email');
});

it('only claims the client sees the new stage when the matter is on the portal', function () {
    expect(__('matters.transition_form.preview_not_publishing_with_stage_change', ['stage' => 'X']))
        ->toContain('Nếu vụ việc đang bật công bố portal');
});

/**
 * Fix round 1 (finding Important 2): `reject_success`/`rejection_reason_help` hứa một email chỉ
 * đúng khi khách THẬT SỰ có ai đó để nhận (tài khoản portal đủ điều kiện, R12) VÀ vụ việc còn bật
 * công tắc portal (`is_published_to_portal`). Vụ ĐÃ ĐÓNG còn công bố trên cổng KHÔNG nằm ngoài lời
 * hứa đó (rà soát cuối làn, I1 — xem test kế tiếp).
 * `ChecklistRelationManager::rejectionNoticeCopy()` chọn giữa cặp khoá này và HAI cặp "không
 * email" bên dưới ("_no_notice": lý do vẫn hiện trên cổng; "_portal_hidden": vụ ẩn khỏi cổng).
 * Trước fix round 1, cặp "email đã được gửi" hiện VÔ ĐIỀU KIỆN — đúng lớp lời hứa sai mà bài test
 * này tồn tại để chặn (xem `it('no longer denies the rejection email…')` ở trên, cho nửa NGƯỢC LẠI
 * của cùng một lỗi).
 */
it('does not promise an email when nobody is eligible to receive one', function (string $key) {
    expect(mb_strtolower(__($key)))
        ->toContain('không')
        ->not->toContain('sẽ được gửi báo khách')
        ->not->toContain('email gửi cho khách,');
})->with([
    'checklist.tab.actions.reject_success_no_notice',
    'checklist.tab.fields.rejection_reason_help_no_notice',
    'checklist.tab.actions.reject_success_portal_hidden',
    'checklist.tab.fields.rejection_reason_help_portal_hidden',
]);

/**
 * Rà soát cuối làn (I1). Fix round 2 của Task 3 cho cặp "_no_notice" nói "vụ việc đã đóng" là một
 * lý do không có thư, vì `NotifyClientOfChecklistItemRejected` khi đó đòi `Matter::open()`. Nhưng
 * khách VẪN nộp được trên vụ đã đóng còn công bố trên cổng (`DocumentPolicy::create` không hỏi
 * `closed_at` — b0f98aa đo đúng điều đó cho thư nội bộ), và trang nộp hứa với họ
 * (`portal_submit.done.body`) rằng có gì chưa ổn sẽ có email. Thư từ chối giờ đi cho vụ đã đóng
 * còn trên cổng, nên câu "không email" không được đổ cho việc vụ đã đóng nữa.
 */
it('never blames a closed matter for a rejection email that does not go out', function (string $key) {
    expect(mb_strtolower(__($key)))->not->toContain('đã đóng');
})->with([
    'checklist.tab.actions.reject_success_no_notice',
    'checklist.tab.fields.rejection_reason_help_no_notice',
]);

/**
 * Fix round 2 (finding 2): cặp "_portal_hidden" hiện ĐÚNG khi vụ việc ẩn khỏi cổng (tắt công bố
 * portal, hoặc khách hàng đã xoá) — khi đó `MatterChecklistItem::applyClientPortalConstraints()`
 * (qua `whereHas('matter')`) giấu luôn đầu mục lẫn lý do. Câu không được nói lý do hiện trên cổng
 * (luật sư sẽ tin khách đã được báo và không gọi), và phải bảo người duyệt tự liên hệ khách.
 */
it('never claims the reason shows on the portal when the matter is hidden from it', function (string $key) {
    expect(mb_strtolower(__($key)))
        ->not->toContain('trên cổng khách hàng')
        ->not->toContain('hiện nguyên văn')
        ->toContain('liên hệ trực tiếp');
})->with([
    'checklist.tab.actions.reject_success_portal_hidden',
    'checklist.tab.fields.rejection_reason_help_portal_hidden',
]);

/**
 * Fix round 1 (finding Important 2): "gửi ngay cho khách" hứa một tốc độ hệ thống không có — thư
 * đi qua hàng đợi (R2), chạy khi cron gọi `queue:work --stop-when-empty`, không rời máy chủ ngay
 * lúc bấm "Từ chối".
 */
it('never claims the rejection email leaves the server instantly', function () {
    expect(mb_strtolower(__('checklist.tab.fields.rejection_reason_help')))->not->toContain('gửi ngay');
});

it('checks keys that actually exist, so a typo cannot make these tests pass on the raw key', function (string $key) {
    expect(Lang::has($key))->toBeTrue();
})->with([
    'checklist.tab.actions.reject_heading',
    'checklist.tab.actions.reject_success',
    'checklist.tab.actions.reject_success_no_notice',
    'checklist.tab.fields.rejection_reason_help',
    'checklist.tab.fields.rejection_reason_help_no_notice',
    'checklist.tab.actions.reject_success_portal_hidden',
    'checklist.tab.fields.rejection_reason_help_portal_hidden',
    'documents.publish.trashed',
    'documents.publish.matter_unavailable',
    'documents.lifecycle.trashed',
    'documents.lifecycle.matter_unavailable',
    'checklist.review.matter_unavailable',
    'portal_submit.done.body',
    'matters.transition_form.preview_not_publishing_with_stage_change',
]);
