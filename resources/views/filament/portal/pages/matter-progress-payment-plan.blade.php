{{--
    Làn nt Task 1 — phần "Lộ trình thanh toán" bên trong khối "Hợp đồng và thanh toán" của trang hồ sơ
    trên cổng, chỉ khi công tắc "Nhắc lịch thanh toán cho khách" bật. Dữ liệu là hình chiếu hẹp của
    `MatterProgress::paymentPlan()` (`App\Support\Billing\ClientPaymentPlan`) — chỉ chuỗi đã định dạng,
    không bản ghi nào; tệp này chỉ vẽ ra.

    Không có bước dựng CSS (CLAUDE.md): style nội tuyến trên biến CSS của Filament. Một cột, không bảng
    (375px). Mã QR nền trắng cố định để ứng dụng ngân hàng quét được cả khi cổng ở nền tối. "Quá hạn"
    luôn có CHỮ đi kèm màu.
--}}
@php
    $divider = 'border-top:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);';
    $subHeading = 'margin-top:1rem;font-size:1rem;font-weight:700;';
    $noteStyle = 'margin-top:0.25rem;font-weight:700;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;letter-spacing:0.02em;';
@endphp

<div data-portal-section="payment-plan" style="margin-top:1.25rem;padding-top:1rem;{{ $divider }}">
    <h3 style="font-size:1.0625rem;font-weight:700;">{{ __('client_payments.portal.heading') }}</h3>

    <p style="margin-top:0.5rem;">{{ __('client_payments.portal.contract_value', ['amount' => $plan['contract_value']]) }}</p>
    <p style="margin-top:0.25rem;">{{ __('client_payments.portal.collected', ['amount' => $plan['collected']]) }}</p>

    @if (filled($plan['waived']))
        <p style="margin-top:0.25rem;">{{ __('client_payments.portal.waived', ['amount' => $plan['waived']]) }}</p>
    @endif

    <p style="margin-top:0.25rem;font-size:1.125rem;font-weight:700;">{{ __('client_payments.portal.outstanding', ['amount' => $plan['outstanding']]) }}</p>

    @if ($plan['settled'])
        <p style="margin-top:0.5rem;font-weight:600;color:var(--success-600);">{{ __('client_payments.portal.settled') }}</p>
    @elseif ($plan['payables'] === [])
        <p style="margin-top:0.5rem;{{ $muted }}">{{ __('client_payments.portal.no_payables') }}</p>
    @else
        @if ($plan['transfer'] !== null)
            <h4 style="{{ $subHeading }}">{{ __('client_payments.portal.transfer_heading') }}</h4>
            <p style="margin-top:0.25rem;">{{ __('client_payments.portal.bank', ['name' => $plan['transfer']['bank']]) }}</p>
            <p style="margin-top:0.25rem;font-weight:600;">{{ __('client_payments.portal.account_number', ['number' => $plan['transfer']['account_number']]) }}</p>
            <p style="margin-top:0.25rem;">{{ __('client_payments.portal.account_holder', ['holder' => $plan['transfer']['account_holder']]) }}</p>
            @if (filled($plan['transfer']['bank_branch']))
                <p style="margin-top:0.25rem;">{{ __('client_payments.portal.bank_branch', ['branch' => $plan['transfer']['bank_branch']]) }}</p>
            @endif
        @endif

        <h4 style="{{ $subHeading }}">{{ __('client_payments.portal.payables_heading') }}</h4>

        @foreach ($plan['payables'] as $payable)
            <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                <p style="font-weight:600;">{{ $payable['name'] }}</p>
                <p style="margin-top:0.25rem;">{{ __('client_payments.portal.payable_amount', ['amount' => $payable['amount']]) }}</p>
                <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}">{{ $payable['due'] }}</p>
                <p style="margin-top:0.25rem;font-weight:700;color:{{ $payable['state'] === 'overdue' ? 'var(--danger-600)' : 'var(--warning-600)' }};">{{ $payable['state_label'] }}</p>
                <p style="{{ $noteStyle }}">{{ __('client_payments.portal.transfer_note', ['note' => $payable['note']]) }}</p>

                @if ($payable['qr'] !== null)
                    <img src="{{ $payable['qr'] }}"
                         alt="{{ __('client_payments.portal.qr_alt', ['amount' => $payable['amount'], 'name' => $payable['name']]) }}"
                         style="display:block;margin-top:0.5rem;width:11rem;height:11rem;background-color:#ffffff;border-radius:0.5rem;">
                    <p style="margin-top:0.25rem;font-size:0.875rem;{{ $muted }}">{{ __('client_payments.portal.qr_hint') }}</p>
                @endif
            </article>
        @endforeach

        <p style="margin-top:0.5rem;font-size:0.9375rem;{{ $muted }}">{{ __('client_payments.portal.note_hint') }}</p>
    @endif
</div>
