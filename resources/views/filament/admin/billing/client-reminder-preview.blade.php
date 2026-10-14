{{--
    Làn nt Task 2 — nội dung hộp thoại "Gửi thư nhắc" trên dòng đợt quá hạn của tab "Hợp đồng và thanh
    toán" (`BillingRelationManager::remindClientAction()`, dữ liệu từ `remindClientPreview()`). Xem trước
    ĐÚNG thư sẽ gửi: người nhận (tài khoản cổng đang hoạt động, đã kích hoạt, cổng còn cho xem vụ), tiêu đề
    và bản chữ của thư (`PaymentReminder::previewText()` — cùng view văn bản thuần với thư thật; lời chào
    mang tên người nhận đầu tiên, mỗi người nhận thư mang tên của chính họ).

    Không có bước dựng CSS (CLAUDE.md): style nội tuyến trên biến CSS của Filament.
--}}
@php
    $muted = 'color:var(--gray-500);';
    $box = 'margin-top:0.5rem;padding:0.75rem;border-radius:0.5rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
@endphp

<div data-billing-preview="client-reminder" style="display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
    @if ($alreadySent !== null)
        <p role="alert" style="font-weight:600;color:var(--danger-600);">{{ $alreadySent }}</p>
    @endif

    <p>{{ __('client_payments.remind_action.intro') }}</p>

    <p style="font-weight:600;">{{ $instalmentLine }}</p>

    <div>
        <p style="font-weight:600;">{{ __('client_payments.remind_action.recipients') }}</p>
        @if ($recipients === [])
            <p style="margin-top:0.25rem;font-weight:600;color:var(--danger-600);">{{ __('client_payments.remind_action.no_recipients') }}</p>
        @else
            <ul style="margin-top:0.25rem;padding-left:1.25rem;list-style:disc;">
                @foreach ($recipients as $recipient)
                    <li>{{ $recipient }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($subject !== null)
        <div>
            <p style="font-weight:600;">{{ __('client_payments.remind_action.subject') }}</p>
            <p style="margin-top:0.25rem;">{{ $subject }}</p>
        </div>

        <div>
            <p style="font-weight:600;">{{ __('client_payments.remind_action.body') }}</p>
            <div style="{{ $box }}white-space:pre-wrap;word-break:break-word;">{{ $body }}</div>
            @if ($withQr)
                <p style="margin-top:0.25rem;{{ $muted }}">{{ __('client_payments.remind_action.with_qr') }}</p>
            @endif
        </div>
    @endif
</div>
