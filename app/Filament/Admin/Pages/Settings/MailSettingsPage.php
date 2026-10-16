<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Actions\Settings\SendTestMailToSelf;
use App\Filament\Admin\Concerns\IsSettingsPage;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Mail\Staff\TestMail;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Settings\MailStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Trang "Thư điện tử" (làn st, mục 5 của brief) — trạng thái gửi thư của máy chủ ({@see MailStatus}:
 * đường thư, địa chỉ và tên gửi đi, Reply-To; không bao giờ mật khẩu hay tên đăng nhập SMTP), nút
 * "Gửi thư thử" tới email của chính người bấm ({@see SendTestMailToSelf}: hàng đợi sau commit, tối đa
 * ba lần mỗi 10 phút, có nhật ký), năm thư thử gần nhất trong sổ thư, và đường tới "Thư đã gửi".
 *
 * Cấu hình máy chủ thư (`MAIL_*` trong `.env`) không sửa được ở đây: đó là bí mật của máy chủ
 * (`docs/CAI-DAT.md`); "Email liên hệ" (Reply-To) sửa ở trang Thông tin văn phòng.
 *
 * Cổng: {@see IsSettingsPage} (chỉ `settings.manage`, 404 ở mount và mọi request cập nhật); hành động
 * "Gửi thư thử" hỏi lại; Action hỏi lần nữa.
 */
class MailSettingsPage extends Page
{
    use IsSettingsPage;

    protected string $view = 'filament.admin.pages.settings.mail';

    protected static ?string $slug = 'settings/mail';

    /** Số thư thử gần nhất hiện trên trang. */
    public const RECENT_LIMIT = 5;

    public function getTitle(): string
    {
        return __('settings.mail.title');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestMail')
                ->label(__('settings.mail.test.label'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalHeading(__('settings.mail.test.heading'))
                ->modalDescription(fn (): string => __('settings.mail.test.body', ['email' => (string) Auth::user()?->email]))
                ->modalSubmitActionLabel(__('settings.mail.test.confirm'))
                ->action(function (): void {
                    abort_unless(static::canAccess(), 404);

                    /** @var User $actor */
                    $actor = Auth::user();

                    try {
                        $email = app(SendTestMailToSelf::class)->handle($actor);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title(__('actions.failed_title'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title(__('settings.mail.test.queued', ['email' => $email]))->success()->send();
                }),
        ];
    }

    /** @return array{transport: string, delivers: bool, from_address: ?string, from_name: ?string, reply_to: ?string, queue: string} */
    public function status(): array
    {
        return [
            'transport' => MailStatus::transportLabel(),
            'delivers' => MailStatus::delivers(),
            'from_address' => MailStatus::fromAddress(),
            'from_name' => MailStatus::fromName(),
            'reply_to' => MailStatus::replyTo(),
            'queue' => (string) config('queue.default'),
        ];
    }

    /** @return Collection<int, OutboundMessage> thư thử gần nhất (của mọi quản trị viên), mới nhất trước */
    public function recentTestMails(): Collection
    {
        return OutboundMessage::query()
            ->where('template', TestMail::TEMPLATE)
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'recipient', 'status', 'created_at', 'sent_at']);
    }

    /** Màu huy hiệu của một trạng thái sổ thư. */
    public function statusColor(OutboundMessage $message): string
    {
        return match ($message->status->value) {
            'sent' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public function outboundUrl(): ?string
    {
        return OutboundMessageResource::canAccess() ? OutboundMessageResource::getUrl() : null;
    }
}
