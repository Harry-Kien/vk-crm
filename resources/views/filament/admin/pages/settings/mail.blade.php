{{--
    Trang "Thư điện tử" (làn st) — luật ở docblock `App\Filament\Admin\Pages\Settings\MailSettingsPage` và
    `App\Actions\Settings\SendTestMailToSelf`; tệp này chỉ vẽ ra. Không in mật khẩu hay tên đăng nhập SMTP.

    Không có bước dựng CSS (CLAUDE.md) — style nội tuyến trên biến CSS của Filament; huy hiệu dùng
    `settings.partials.pill` (xuống dòng được, không cắt chữ).
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $card = 'border-radius:0.75rem;padding:1rem 1.25rem;border:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);max-width:56rem;';
    $row = 'display:flex;flex-wrap:wrap;gap:0.25rem 1rem;justify-content:space-between;padding:0.5rem 0;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 15%, transparent);';
    $status = $this->status();
    $recent = $this->recentTestMails();
    $outboundUrl = $this->outboundUrl();
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:56rem;">
        <p>{{ __('settings.mail.intro') }}</p>
        @unless ($status['delivers'])
            <div style="display:flex;">
                @include('filament.admin.pages.settings.partials.pill', ['tone' => 'warning', 'text' => __('settings.mail.not_configured')])
            </div>
        @endunless
    </div>

    <section data-settings="mail-status" style="{{ $card }}">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:0.25rem;">{{ __('settings.mail.status_heading') }}</h2>
        <dl style="margin:0;">
            <div style="{{ $row }}"><dt style="font-weight:600;">{{ __('settings.mail.fields.transport') }}</dt><dd style="margin:0;">{{ $status['transport'] }}</dd></div>
            <div style="{{ $row }}"><dt style="font-weight:600;">{{ __('settings.mail.fields.from_address') }}</dt><dd style="margin:0;">{{ $status['from_address'] ?? __('settings.mail.unset') }}</dd></div>
            <div style="{{ $row }}"><dt style="font-weight:600;">{{ __('settings.mail.fields.from_name') }}</dt><dd style="margin:0;">{{ $status['from_name'] ?? __('settings.mail.unset') }}</dd></div>
            <div style="{{ $row }}"><dt style="font-weight:600;">{{ __('settings.mail.fields.reply_to') }}</dt><dd style="margin:0;">{{ $status['reply_to'] ?? __('settings.mail.unset') }}</dd></div>
            <div style="{{ $row }}border-bottom:none;"><dt style="font-weight:600;">{{ __('settings.mail.fields.queue') }}</dt><dd style="margin:0;">{{ __('settings.mail.queue', ['queue' => $status['queue']]) }}</dd></div>
        </dl>
        <p style="margin-top:0.75rem;{{ $muted }}">{{ __('settings.mail.where') }}</p>
    </section>

    <section data-settings="mail-recent" style="{{ $card }}">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:0.25rem;">{{ __('settings.mail.recent.heading') }}</h2>
        <p style="margin-bottom:0.5rem;{{ $muted }}">{{ __('settings.mail.recent.hint') }}</p>

        @forelse ($recent as $message)
            <div style="{{ $row }}">
                <span>{{ $message->recipient }} · {{ $message->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                @include('filament.admin.pages.settings.partials.pill', ['tone' => $this->statusColor($message), 'text' => $message->status->label()])
            </div>
        @empty
            <p style="{{ $muted }}">{{ __('settings.mail.recent.empty') }}</p>
        @endforelse

        @if ($outboundUrl !== null)
            <p style="margin-top:0.75rem;">
                <a href="{{ $outboundUrl }}" style="color:var(--primary-600);font-weight:600;text-decoration:underline;">{{ __('settings.mail.recent.open_ledger') }}</a>
            </p>
        @endif
    </section>
</x-filament-panels::page>
