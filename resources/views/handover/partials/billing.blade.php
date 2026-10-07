{{--
    MUC-LUC.pdf — khối 5: "Bảng kê thanh toán" (M9 Task 10, P1). `$billing` là ĐÚNG hình chiếu mà
    khối "Hợp đồng và thanh toán" của cổng khách vẽ (`App\Support\Billing\ClientBillingStatement`),
    do `RenderHandoverIndex::billingStatement()` dựng từ những cột khách được thấy — không ghi chú,
    không lý do miễn/huỷ/phụ lục, không người ghi, không biên lai. `null` thì không có mục này.

    Chữ của từng dòng (đến hạn, trạng thái, cách trả) đã được hình chiếu dịch sẵn, nên cổng và mục
    lục nói cùng một câu; tiêu đề cột ở `handover.pdf.billing`. Bảng ở đây hợp lệ (khổ A4) — trang
    cổng thì không dùng bảng (375px).

    Dòng "tính đến ngày lập gói" (làn fu3, SPEC §6.12 bổ sung 2026-10-04): bảng kê là ẢNH CHỤP lúc lập
    gói, còn khách tải gói về và cất giữ. Ngày là `$generatedAt` của `handover/index.blade.php` — đúng
    biến của dòng "Lập ngày" đầu mục lục, do `RenderHandoverIndex::handle()` tính MỘT lần — nên hai ngày
    không bao giờ lệch nhau.
--}}
@if ($billing !== null)
    <h2>{{ __('handover.pdf.billing.heading') }}</h2>
    <p class="muted">{{ __('handover.pdf.billing.as_of', ['date' => $generatedAt]) }}</p>

    <table class="info">
        <tr>
            <td class="label">{{ __('handover.pdf.billing.contract_code') }}</td>
            <td>{{ $billing['code'] }}</td>
        </tr>
        @if (filled($billing['signed_on']))
            <tr>
                <td class="label">{{ __('handover.pdf.billing.signed_on') }}</td>
                <td>{{ $billing['signed_on'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">{{ __('handover.pdf.billing.total') }}</td>
            <td>{{ $billing['total'] }}</td>
        </tr>
        @if (filled($billing['vat']))
            <tr>
                <td class="label">{{ __('handover.pdf.billing.vat') }}</td>
                <td>{{ $billing['vat'] }}</td>
            </tr>
        @endif
        @if (filled($billing['completed_on']))
            <tr>
                <td class="label">{{ __('handover.pdf.billing.completed_on') }}</td>
                <td>{{ $billing['completed_on'] }}</td>
            </tr>
        @endif
    </table>

    <p class="muted" style="margin-top: 10px;">{{ __('handover.pdf.billing.instalments_heading') }}</p>
    <table>
        <thead>
            <tr>
                <th style="width: 24%;">{{ __('handover.pdf.billing.columns.name') }}</th>
                <th style="width: 13%;">{{ __('handover.pdf.billing.columns.amount') }}</th>
                <th style="width: 22%;">{{ __('handover.pdf.billing.columns.due') }}</th>
                <th style="width: 13%;">{{ __('handover.pdf.billing.columns.collected') }}</th>
                <th style="width: 13%;">{{ __('handover.pdf.billing.columns.outstanding') }}</th>
                <th style="width: 15%;">{{ __('handover.pdf.billing.columns.state') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($billing['instalments'] as $instalment)
                <tr>
                    <td>{{ $instalment['name'] }}</td>
                    <td>{{ $instalment['amount'] }}</td>
                    <td>{{ $instalment['due'] }}</td>
                    <td>{{ $instalment['collected'] }}</td>
                    <td>{{ $instalment['outstanding'] }}</td>
                    <td>{{ $instalment['state_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="muted" style="margin-top: 10px;">{{ __('handover.pdf.billing.payments_heading') }}</p>
    @if ($billing['payments'] === [])
        <p class="muted">{{ __('handover.pdf.billing.payments_empty') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 25%;">{{ __('handover.pdf.billing.columns.paid_on') }}</th>
                    <th style="width: 30%;">{{ __('handover.pdf.billing.columns.amount') }}</th>
                    <th style="width: 45%;">{{ __('handover.pdf.billing.columns.method') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($billing['payments'] as $payment)
                    <tr>
                        <td>{{ $payment['paid_on'] }}</td>
                        <td>{{ $payment['amount'] }}</td>
                        <td>{{ $payment['method'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
