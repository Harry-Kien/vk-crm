{{--
    M12 R8/R14 — trang "Thông báo trên điện thoại", chung cho hai panel
    (`App\Filament\Admin\Pages\PushDevices`, `App\Filament\Portal\Pages\PushDevices`). Luật và lý do ở
    docblock `App\Filament\Concerns\ManagesOwnPushDevices`; tệp này chỉ vẽ ra.

    - Không model nào của bảng đăng ký ở đây: `$this->devices()` trả mảng chuỗi đã chọn sẵn (id, nhãn,
      hai ngày, cờ "máy đang dùng") — không endpoint, không khoá.
    - Khối "Máy này" (`data-vk-push-device`) do `public/pwa/register.js` điều khiển: mọi câu trạng thái
      được in SẴN, ẩn (`hidden`), script chỉ chọn câu nào hiện — tệp JS không mang chữ tiếng Việt
      nào (R5). Câu hiện mặc định là "chưa nhận được" (`unsupported`): trình duyệt không chạy script
      hay không có service worker thì đó là sự thật. `wire:ignore`: Livewire vẽ lại danh sách sau
      khi gỡ một máy mà không đặt lại trạng thái script đã chọn.
    - Nút Bật là `<button data-vk-push-enable>` thường: script gọi `Notification.requestPermission()`
      NGAY trong trình xử lý cú bấm (iOS đòi thao tác của người dùng). Không bao giờ hỏi quyền lúc
      tải trang.
    - Thiếu khoá VAPID (R7): không nút Bật, không khối trạng thái — một câu "chưa bật".
    - KHÔNG CÓ BƯỚC DỰNG CSS (CLAUDE.md): mọi kiểu dáng là `style=` nội tuyến trên biến CSS của
      Filament; 375px một cột, mọi thứ bấm được cao tối thiểu 44px.
--}}

@php
    $panel = $this->panelId();
    $app = __("pwa.{$panel}.short_name", ['firm' => config('vkcrm.brand.short_name')]);
    $configured = $this->pushConfigured();
    $devices = $this->devices();

    $stack = 'display:flex;flex-direction:column;gap:1.5rem;max-width:40rem;';
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);display:flex;flex-direction:column;gap:0.75rem;';
    $blockHeading = 'font-size:1.125rem;font-weight:700;';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:0.375rem;padding:0.625rem 1rem;border-radius:0.5rem;font-weight:600;border:0;cursor:pointer;font-size:1rem;';
    $primary = $tap.'background-color:var(--primary-600);color:var(--primary-50);';
    $quiet = $tap.'background-color:transparent;color:var(--danger-600);border:1px solid color-mix(in srgb, var(--danger-600) 45%, transparent);';
    $stateWithButton = 'display:flex;flex-direction:column;gap:0.75rem;align-items:flex-start;';
    $row = 'display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.75rem 0;border-top:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);';
@endphp

<x-filament-panels::page>
    <div data-vk-push-page style="{{ $stack }}">
        <p style="{{ $muted }}">{{ __("push.devices.lead.{$panel}") }}</p>

        {{-- MÁY NÀY ----------------------------------------------------------------------- --}}
        <section data-vk-push-device wire:ignore style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('push.devices.this_device') }}</h2>

            @if ($configured)
                <p data-vk-push-state="unsupported">{{ __('push.devices.state.unsupported') }}</p>

                <div data-vk-push-state="ios-install" hidden>
                    <p>{{ __('push.devices.state.ios_install', ['app' => $app]) }}</p>
                    <p style="{{ $muted }}">{{ __('push.devices.state.ios_install_note') }}</p>
                </div>

                <p data-vk-push-state="denied" hidden>{{ __('push.devices.state.denied', ['app' => $app]) }}</p>

                {{-- `display` ở thẻ CON: `style="display:…"` trên chính thẻ `hidden` thắng luật
                     `[hidden] { display: none }` của trình duyệt và khối không bao giờ ẩn. --}}
                <div data-vk-push-state="ready" hidden>
                    <div style="{{ $stateWithButton }}">
                        <p>{{ __('push.devices.state.ready') }}</p>
                        <button type="button" data-vk-push-enable style="{{ $primary }}">{{ __('push.devices.enable') }}</button>
                    </div>
                </div>

                <p data-vk-push-state="enabled" hidden>{{ __('push.devices.state.enabled') }}</p>

                <div data-vk-push-state="failed" hidden>
                    <div style="{{ $stateWithButton }}">
                        <p>{{ __('push.devices.state.failed') }}</p>
                        <button type="button" data-vk-push-enable style="{{ $primary }}">{{ __('push.devices.retry') }}</button>
                    </div>
                </div>
            @else
                <p data-vk-push-off>{{ __('push.devices.off') }}</p>
            @endif

            <p style="{{ $muted }}">{{ __('push.devices.lock_screen') }}</p>
            <p style="{{ $muted }}">{{ __('push.devices.logout_note') }}</p>
        </section>

        {{-- CÁC MÁY ĐANG NHẬN ------------------------------------------------------------- --}}
        <section data-vk-push-list style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('push.devices.list_heading') }}</h2>

            @forelse ($devices as $device)
                <div data-vk-push-row="{{ $device['id'] }}" wire:key="push-device-{{ $device['id'] }}" style="{{ $row }}">
                    <div style="display:flex;flex-direction:column;gap:0.25rem;">
                        <p style="font-weight:600;">
                            {{ $device['label'] }}
                            @if ($device['current'])
                                <span data-vk-push-current style="{{ $muted }}font-weight:400;">· {{ __('push.devices.current') }}</span>
                            @endif
                        </p>
                        <p style="{{ $muted }}">
                            {{ __('push.devices.enabled_at', ['date' => $device['enabled_at']]) }}
                            @if ($device['last_seen_at'] !== null)
                                <br>{{ __('push.devices.last_seen', ['date' => $device['last_seen_at']]) }}
                            @endif
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="removeDevice({{ $device['id'] }})"
                        wire:confirm="{{ __('push.devices.remove_confirm') }}"
                        style="{{ $quiet }}"
                    >{{ __('push.devices.remove') }}</button>
                </div>
            @empty
                <p data-vk-push-empty style="{{ $muted }}">{{ __('push.devices.empty') }}</p>
            @endforelse

            @if (count($devices) > 1)
                <div>
                    <button
                        type="button"
                        wire:click="removeAllDevices"
                        wire:confirm="{{ __('push.devices.remove_all_confirm') }}"
                        style="{{ $quiet }}"
                    >{{ __('push.devices.remove_all') }}</button>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
