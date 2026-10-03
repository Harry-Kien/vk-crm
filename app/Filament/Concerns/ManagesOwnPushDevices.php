<?php

namespace App\Filament\Concerns;

use App\Actions\Push\ForgetPushDevice;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Push\PushSession;
use App\Support\Push\VapidKeys;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

/**
 * M12 R8/R14 — trang "Thông báo trên điện thoại", chung cho hai panel
 * (`App\Filament\Admin\Pages\PushDevices`, `App\Filament\Portal\Pages\PushDevices`; mỗi lớp chỉ
 * nói ai được vào bằng `canAccess()`). Cổng khách không có trang hồ sơ cá nhân, có chủ đích, nên
 * đây là một trang riêng mở từ user menu ({@see self::userMenuItem()}), không phải một tab hồ sơ.
 *
 * # Chỉ máy của CHÍNH người đang xem
 *
 * Mọi truy vấn đi qua `$viewer->pushSubscriptions()` của người đang đăng nhập — không model nào của
 * bảng đăng ký đi vào Livewire hay Blade. Model `PushSubscription` của gói không có `$hidden`, nên
 * `toArray()` của nó in `endpoint` (URL mang quyền gửi), `public_key`, `auth_token` (rà soát Task 4,
 * Minor 4). {@see self::devices()} vì vậy chọn đúng bốn cột (id, nhãn, ngày bật, lần cuối thấy) và
 * trả MẢNG chuỗi đã định dạng; "máy đang dùng" so endpoint ở MÁY CHỦ (khoá phiên
 * {@see PushSession::endpointKey()}), chỉ ra một id.
 *
 * Trait không thêm thuộc tính public nào: không gì từ trình duyệt định danh một máy ngoài tham số id
 * của {@see self::removeDevice()}, và id đó được tìm lại trong máy của chính người bấm — id của
 * người khác (kể cả cùng khách hàng) là 404, dòng của họ đứng nguyên.
 *
 * # Cổng
 *
 * `canAccess()` của lớp dùng trait hỏi KIỂU người đang đăng nhập (hai panel chung cookie phiên), và
 * {@see self::mountCanAuthorizeAccess()} / {@see self::hydrateCanAuthorizeAccess()} `abort(404)` khi
 * không qua (SPEC §10.10: không có quyền và không tồn tại cùng một câu trả lời). Mỗi lời gọi
 * Livewire hỏi lại người đang đăng nhập ({@see self::viewer()}), không giữ người dùng trong trạng
 * thái component.
 *
 * Khối "Máy này" (nút Bật, hướng dẫn cài trên iPhone…) do `public/pwa/register.js` điều khiển; nó
 * nằm trong `wire:ignore` để một lần vẽ lại của Livewire (sau khi gỡ một máy) không đặt lại trạng
 * thái script đã chọn. Script báo "đã bật" bằng sự kiện `vk-push-devices-changed`
 * ({@see self::refreshDevices()}). Thiếu khoá VAPID (R7): không nút Bật, chỉ một câu "chưa bật";
 * danh sách và nút Gỡ vẫn còn.
 */
trait ManagesOwnPushDevices
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** Mục user menu của panel — ẩn khi máy chủ chưa có khoá VAPID (push tắt êm, R7). */
    public static function userMenuItem(): Action
    {
        return Action::make('push-devices')
            ->label(fn (): string => __('push.devices.menu'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->url(fn (): string => static::getUrl())
            ->visible(fn (): bool => VapidKeys::configured());
    }

    /**
     * Thay `Filament\Pages\Concerns\CanAuthorizeAccess` (403) bằng 404, ở cả lúc mount lẫn MỌI lần
     * hydrate: `AnswerDeniedPanelRequestsWithNotFound` chỉ đổi 403 → 404 cho lần tải trang, không cho
     * request cập nhật Livewire (docblock của middleware đó).
     */
    public function mountCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function getTitle(): string|Htmlable
    {
        return __('push.devices.title');
    }

    /** `register.js` vừa bật máy này: vẽ lại danh sách (thân rỗng — Livewire vẽ lại sau mọi lời gọi). */
    #[On('vk-push-devices-changed')]
    public function refreshDevices(): void {}

    public function pushConfigured(): bool
    {
        return VapidKeys::configured();
    }

    public function panelId(): string
    {
        return Filament::getCurrentPanel()?->getId() ?? 'portal';
    }

    /**
     * Máy của người đang xem, mới bật trước. Không cột `endpoint`/khoá nào được đọc ra.
     *
     * @return list<array{id: int, label: string, enabled_at: string, last_seen_at: ?string, current: bool}>
     */
    public function devices(): array
    {
        $viewer = $this->viewer();
        $currentId = $this->currentDeviceId($viewer);

        return $viewer->pushSubscriptions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'device_label', 'created_at', 'last_seen_at'])
            ->map(fn ($device): array => [
                'id' => (int) $device->id,
                'label' => (string) ($device->device_label ?: __('push.devices.unknown_device')),
                'enabled_at' => $this->formatDate($device->created_at),
                'last_seen_at' => $device->last_seen_at !== null ? $this->formatDate($device->last_seen_at) : null,
                'current' => $currentId !== null && (int) $device->id === $currentId,
            ])
            ->values()
            ->all();
    }

    public function removeDevice(int $device): void
    {
        $viewer = $this->viewer();
        $wasCurrent = $this->currentDeviceId($viewer) === $device;

        abort_unless(app(ForgetPushDevice::class)->byId($viewer, $device), 404);

        if ($wasCurrent) {
            session()->forget(PushSession::endpointKey(Filament::getAuthGuard()));
        }

        Notification::make()->title(__('push.devices.removed'))->success()->send();
    }

    public function removeAllDevices(): void
    {
        $count = app(ForgetPushDevice::class)->all($this->viewer());

        session()->forget(PushSession::endpointKey(Filament::getAuthGuard()));

        Notification::make()->title(__('push.devices.removed_all', ['count' => $count]))->success()->send();
    }

    private function viewer(): User|ClientUser
    {
        $viewer = Filament::auth()->user();

        abort_unless(static::canAccess() && ($viewer instanceof User || $viewer instanceof ClientUser), 404);

        return $viewer;
    }

    /** Id của máy mà endpoint trong phiên (guard này) trỏ tới, nếu máy đó còn là của người xem. */
    private function currentDeviceId(User|ClientUser $viewer): ?int
    {
        $endpoint = session(PushSession::endpointKey(Filament::getAuthGuard()));

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $id = $viewer->pushSubscriptions()->where('endpoint', $endpoint)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function formatDate(mixed $value): string
    {
        // Model của gói không cast `last_seen_at` (đọc ra là chuỗi); `created_at` là Carbon.
        return Carbon::parse($value)->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }
}
