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

/**
 * Việc sau gộp M6 (làn fu, mục 1): câu từ chối gửi lại `staff.deadline_reminder` từng hứa "tự được
 * thử lại ở lần kiểm tra hạn kế tiếp, gửi tay sẽ khiến thư đi hai lần" — sai từ final review wave 2,
 * I-2: bậc hỏng hẳn KHÔNG được xếp lại trong ngày, chỉ lượt kiểm tra hạn đầu tiên của ngày hôm sau
 * (đo bằng đường đi thật ở `tests/Feature/Schedule/CheckDeadlinesTest.php`, "dispatches the tier
 * again on the first CheckDeadlines run of the next day…"), và chuông báo lỗi đã tới người phụ
 * trách. Tên mẫu có dấu chấm nên đọc cả mảng lý do rồi tra theo tên — khoá dạng chấm sẽ trượt.
 */
it('tells the admin a failed deadline reminder comes back at the next day\'s first check, not at the next check', function () {
    $reason = mb_strtolower(((array) __('outbound.resend.refused.template_reasons'))['staff.deadline_reminder']);

    expect($reason)
        ->toContain('ngày hôm sau')
        ->toContain('chuông')
        ->not->toContain('kế tiếp')
        ->not->toContain('hai lần');
});

/**
 * M9 Task 13 (minor m5 rà soát Task 10): M9 không gửi thư tiền nào cho khách (P1), nên dòng "chưa
 * có ngày đến hạn" trên khối tiền của cổng không được hứa rằng văn phòng "sẽ báo" — không có lời
 * báo nào đi. Câu trung tính nói đúng trạng thái.
 */
it('does not promise the client a due-date notice that no mail ever sends', function () {
    expect(mb_strtolower(__('portal_progress.billing.due.unscheduled')))
        ->not->toContain('báo')
        ->toContain('chưa có ngày đến hạn');
});

/**
 * Việc sau gộp M9 + M10 (làn fu3, Task 1 mục E): modal "Xoá dữ liệu theo yêu cầu" từng hứa SĐT, email
 * và CCCD "xoá vĩnh viễn — KHÔNG khôi phục được". Sổ tra khách nay được làm sạch cùng (đo bằng đường đi
 * thật ở `tests/Feature/Filament/EraseIntakeDataTest.php`, "erases the identifier hashes…"), nhưng bản
 * sao lưu đêm (giữ 30 bản — `config/backup.php`, rclone) vẫn mang dòng cũ tới khi xoay vòng. Admin đọc
 * câu này rồi trả lời người yêu cầu xoá, nên câu phải nói ra điều đó và không hứa "vĩnh viễn".
 *
 * Làn fu3, Task 2 (minor m1, m4 của rà soát Task 1): "giữ khoảng 30 ngày" đếm 30 BẢN, không phải 30
 * ngày — đêm lỡ kéo dài nó ra, và bản bị dọn trên Google Drive (rclone `deletefile`) nằm trong Thùng rác
 * thêm khoảng 30 ngày (`docs/SAO-LUU-KHOI-PHUC.md`). "KHÔNG khôi phục được" hứa quá: khôi phục một bản sao
 * lưu trong khoảng đó đưa bản ghi về — điều đúng là thao tác không HOÀN TÁC được trong hệ thống. Và sổ
 * tra khách chỉ mất dấu băm của số đang ghi TRÊN BẢN GHI NÀY (số đã bị thay trước đó thì không — PROGRESS,
 * "Còn sót, đã biết"). Số bản đọc từ cấu hình, không chép tay.
 */
it('tells the admin that old backups keep the erased data until they expire', function () {
    $copy = __('intake.anonymise.modal_description');
    $keep = config('vkcrm.backup.rclone.keep');

    expect($keep)->toBe(30)
        ->and($copy)->toContain("Riêng các bản sao lưu cũ vẫn còn dữ liệu cho tới khi bị dọn: hệ thống giữ {$keep} bản sao lưu đêm gần nhất")
        ->toContain('Thùng rác thêm khoảng 30 ngày')
        ->toContain('lâu hơn nếu có đêm sao lưu bị lỡ')
        ->toContain('KHÔNG hoàn tác được')
        ->toContain('dấu mã hoá số điện thoại, số căn cước đang ghi trên bản ghi này trong nhật ký tra khách')
        ->not->toContain('khoảng 30 ngày)')
        ->and(mb_strtolower($copy))->not->toContain('vĩnh viễn')
        ->not->toContain('khôi phục');
});
