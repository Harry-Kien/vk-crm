<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages\Concerns;

use App\Actions\Client\IssuePortalAccess;
use App\Actions\Client\IssuePortalAccessResult;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

/**
 * Việc sau gộp M6 (làn fu, mục 6 — N2 của rà soát cuối làn m6): hộp xác nhận ĐỊA CHỈ trước mọi
 * lần lưu sẽ gửi mật khẩu tạm, và thông báo SAU lần lưu nói thư đi đâu (hoặc vì sao chưa đi). Dùng
 * chung cho {@see CreateClientUser} và {@see EditClientUser}.
 *
 * Vì sao cần: thư `client.activation` mang mật khẩu tạm, và mọi mã OTP đăng nhập sau đó cũng về
 * ĐÚNG địa chỉ ấy — gõ nhầm một ký tự lúc nghe điện thoại là đưa trọn quyền vào cổng cho người lạ.
 * Lớp bảo vệ "chưa xác minh" của R12 (`activated_at`) dừng ngay ở thư kích hoạt, nên địa chỉ phải
 * được nhìn lại TRƯỚC khi lưu.
 *
 * # Cả hai đường lưu của form đi qua hộp xác nhận
 *
 * Nút lưu của Filament mặc định là nút `submit` của thẻ `<form wire:submit="create|save">`: bấm
 * nút hay nhấn Enter trong một ô đều gọi THẲNG `create()`/`save()`, không qua action nào. Trang
 * dùng trait này nên (1) đổi nút thành một action có `->action()` (bỏ `->submit()`), và (2) đổi
 * `getSubmitFormLivewireMethodName()` thành {@see self::formActionMountHandler()} của nút đó, để
 * Enter cũng mở đúng action ấy. Một lời gọi Livewire thẳng tới `create()`/`save()` (không qua
 * giao diện) vẫn chạy — hộp xác nhận là chỗ chặn lỗi GÕ NHẦM của người dùng, không phải một cổng
 * quyền; quyền vẫn ở `ClientUserPolicy` và các hook `mutateFormData*` của trang.
 *
 * # Form sai thì không hỏi
 *
 * `->mountUsing()` kiểm form TRƯỚC khi hộp mở: ô sai báo lỗi ngay tại chỗ, không có hộp xác nhận
 * nào nêu một địa chỉ chưa hợp lệ (hay đã có người dùng).
 */
trait ConfirmsPortalAccessIssue
{
    /**
     * @param  'create'|'edit'  $context  Chọn bộ câu `client_users.issue_confirmation.<context>_*`.
     * @param  Closure(): bool  $confirmWhen  Lần lưu này có gửi mật khẩu tạm không — chỉ khi đó hộp
     *                                        mới mở; không thì action chạy thẳng như nút lưu cũ.
     * @param  Closure(): void  $submit  Đường lưu thật của trang (`create()`, `createAnother()`,
     *                                   `save()`).
     */
    protected function confirmPortalAccessIssue(Action $action, string $context, Closure $confirmWhen, Closure $submit): Action
    {
        return $action
            ->submit(null)
            ->mountUsing(function (): void {
                $this->form->validate();
            })
            ->modal($confirmWhen)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __($this->issueConfirmationKey($context, 'heading')))
            ->modalDescription(fn (): HtmlString => new HtmlString(__(
                $this->issueConfirmationKey($context, 'description'),
                // Câu chữ ở lang/vi là của dự án (không HTML); CHỈ địa chỉ do người dùng gõ, nên
                // chỉ nó được `e()` trước khi in đậm.
                ['email' => '<strong>'.e((string) ($this->data['email'] ?? '')).'</strong>'],
            )))
            ->modalSubmitActionLabel(__("client_users.issue_confirmation.{$context}_submit"))
            ->action($submit);
    }

    /**
     * Chuỗi `wire:submit` của thẻ form: mở ĐÚNG action `$action` của khối nút dưới form, với cùng
     * ngữ cảnh mà chính nút Filament vẽ ra — `recordKey` khi trang đang sửa một bản ghi, rồi
     * `schemaComponent` = `content.form-actions` (`content` là schema nội dung của trang,
     * `form-actions` là khoá Filament đặt cho khối nút ở `getFormActionsContentComponent()`). Không
     * có ngữ cảnh khối nút, `mountAction()` không tìm ra nút — nút nằm trong schema, không trên
     * trang — và Enter im lặng không làm gì. Test "routes the form submit..."
     * (`ClientUserResourceTest`) so chuỗi này với chuỗi của chính nút trong HTML, nên một lần nâng
     * Filament đổi cách dựng ngữ cảnh làm test đỏ.
     */
    protected function formActionMountHandler(string $action): string
    {
        $context = [];

        if (($record = $this->getRecord()) !== null) {
            $context['recordKey'] = (string) $record->getKey();
        }

        $context['schemaComponent'] = 'content.form-actions';

        return "mountAction('{$action}', {}, ".Js::from($context).')';
    }

    /**
     * Thông báo sau lần lưu đã gọi {@see IssuePortalAccess}: đọc kết quả THẬT (`issued`, và trạng
     * thái tài khoản dưới khoá dòng lúc đó), không đoán. `issued = false` chỉ có hai nguyên nhân —
     * đúng hai vế của {@see IssuePortalAccess::isEligible()}: tài khoản đang tắt, hoặc khách hàng sở
     * hữu đã bị xoá mềm.
     */
    protected function notifyPortalAccessIssue(IssuePortalAccessResult $result): void
    {
        $account = $result->account;

        [$key, $status] = match (true) {
            $result->issued => ['client_users.issue_notice.queued', 'success'],
            ! $account->is_active => ['client_users.issue_notice.not_sent_inactive', 'warning'],
            default => ['client_users.issue_notice.not_sent_client_deleted', 'danger'],
        };

        Notification::make()
            ->title(__($key, ['email' => $account->email]))
            ->status($status)
            ->send();
    }

    /**
     * `*_inactive` khi ô "Đang hoạt động" của form đang TẮT — tài khoản sẽ tắt sau lần lưu này nên
     * chưa thư nào đi lúc đó (`IssuePortalAccess::isEligible()` từ chối tài khoản tắt).
     */
    private function issueConfirmationKey(string $context, string $part): string
    {
        $suffix = (bool) ($this->data['is_active'] ?? false) ? '' : '_inactive';

        return "client_users.issue_confirmation.{$context}_{$part}{$suffix}";
    }
}
