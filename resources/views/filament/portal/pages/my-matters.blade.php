{{--
    SPEC §8.2 — danh sách hồ sơ. Một cột dọc gồm các THẺ, không một dòng bảng nào: một bảng cuộn
    ngang trên màn hình 375px là một bảng không dùng được, và đây là màn hình đầu tiên khách hàng
    của văn phòng nhìn thấy sau khi đăng nhập.

    Không có bước dựng CSS trong dự án (CLAUDE.md: máy dev chỉ có PHP trong Docker), nên panel
    dùng `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn và một lớp tiện ích Tailwind
    viết tay ở đây sẽ KHÔNG tô gì cả — M4 đo được điều đó sau khi hai tính năng đã lên. Vì vậy
    mọi kích thước và màu ở dưới là style nội tuyến trên biến CSS của Filament, đúng thành ngữ
    `ChecklistRelationManager::progressBar()` và `StageLogsRelationManager::renderInternalNote()`.

    `$cards` là một mảng GIÁ TRỊ, không phải model — xem docblock của `MyMatters`. Đó là thứ làm
    cho câu "không màn hình cổng nào nhắc tới `internal_note`" đúng về mặt cấu trúc: ở đây không
    có `Matter` nào để một cột nội bộ bám vào.
--}}
<x-filament-panels::page>
    @php
        $cards = $this->getCards();
        $hotline = config('vkcrm.brand.hotline');
        $zalo = config('vkcrm.brand.zalo');
    @endphp

    @if ($cards === [])
        {{--
            Tài liệu bộ công cụ §4: "không hiện bảng rỗng; trạng thái trống phải kèm hướng dẫn
            bước tiếp theo". Một khách vừa đăng nhập lần đầu mà gặp một trang trắng sẽ nghĩ mình
            làm sai điều gì đó, nên khối này vừa nói chuyện gì đang xảy ra, vừa đưa ra một con
            đường KHÔNG đi qua màn hình này — hai cách liên hệ thật với văn phòng.
        --}}
        <div style="display:flex;flex-direction:column;gap:1rem;max-width:34rem;">
            <p style="font-size:1.125rem;font-weight:600;line-height:1.5;">
                {{ __('portal_matters.empty.heading') }}
            </p>

            <p style="font-size:1rem;line-height:1.65;">
                {{ __('portal_matters.empty.body') }}
            </p>

            <a
                href="tel:{{ $hotline }}"
                style="display:flex;align-items:center;justify-content:center;gap:0.5rem;min-height:44px;padding:0.5rem 1rem;border-radius:0.5rem;font-size:1rem;font-weight:600;text-decoration:none;color:#fff;background-color:var(--primary-600);"
            >
                {{ __('portal_matters.empty.hotline', ['phone' => $hotline]) }}
            </a>

            <a
                href="{{ $zalo }}"
                rel="noopener noreferrer"
                style="display:flex;align-items:center;justify-content:center;gap:0.5rem;min-height:44px;padding:0.5rem 1rem;border-radius:0.5rem;font-size:1rem;font-weight:600;text-decoration:none;color:var(--primary-600);border:1px solid var(--primary-600);"
            >
                {{ __('portal_matters.empty.zalo') }}
            </a>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:1rem;">
            @foreach ($cards as $card)
                {{--
                    Cả thẻ là một lối vào, không phải một chữ "Xem chi tiết" nhỏ ở góc: trên điện
                    thoại, vùng chạm càng lớn càng dễ trúng, và mỗi thẻ ở đây cao hơn 44px rất
                    nhiều. `text-decoration:none` và `color:inherit` để nó vẫn đọc như một tấm
                    thẻ chứ không như một đường link xanh gạch chân.
                --}}
                <a
                    href="{{ $card['url'] }}"
                    data-portal-matter-card
                    style="display:flex;flex-direction:column;gap:0.75rem;padding:1rem;border-radius:0.75rem;border:1px solid color-mix(in srgb, currentColor 15%, transparent);overflow-wrap:anywhere;text-decoration:none;color:inherit;"
                >
                    <div style="display:flex;flex-direction:column;gap:0.25rem;">
                        <span style="font-size:0.875rem;font-weight:600;opacity:0.7;">
                            {{ $card['code'] }}
                        </span>

                        <h2 style="font-size:1.125rem;font-weight:700;line-height:1.4;">
                            {{ $card['title'] }}
                        </h2>
                    </div>

                    {{--
                        M6 Task 4 (`requests/REQ-4`, đính chính SPEC §9 2026-09-27) — huy hiệu
                        "có trả lời mới". Đứng NGAY dưới tiêu đề, trước cả tóm tắt: đây là tin cần
                        khách hàng chú ý trước nhất trên thẻ, và nó không phải một trong ba màu
                        tiến độ ở cuối thẻ (một hồ sơ "đã đủ giấy tờ" vẫn có thể vừa có trả lời
                        mới). Màu không phải kênh thông tin duy nhất (tài liệu bộ công cụ §4): câu
                        chữ đứng cạnh và mang toàn bộ nghĩa.
                    --}}
                    @if ($card['has_new_reply'])
                        <p
                            data-portal-card-new-reply
                            style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:600;line-height:1.4;color:var(--primary-600);"
                        >
                            <span
                                aria-hidden="true"
                                style="flex:none;height:0.5rem;width:0.5rem;border-radius:999px;background-color:currentColor;"
                            ></span>
                            {{ __('portal_matters.card.new_reply') }}
                        </p>
                    @endif

                    {{-- `summary_for_client` (portal/portal-2, M6.5 Task 5). Chỉ vẽ khi có nội
                         dung — thẻ không có chỗ cho một dòng trống. --}}
                    @if ($card['summary'])
                        <p data-portal-card-summary style="font-size:0.9375rem;line-height:1.5;opacity:0.85;">
                            {{ $card['summary'] }}
                        </p>
                    @endif

                    {{-- SPEC §8.2: nhãn giai đoạn DỄ HIỂU, tức `client_label`, không bao giờ `label`. --}}
                    <p style="font-size:1rem;line-height:1.5;">
                        {{ $card['stage_label'] ?? __('portal_matters.card.stage_unknown') }}
                    </p>

                    <p style="font-size:0.875rem;opacity:0.7;line-height:1.5;">
                        @if ($card['updated_at'])
                            {{ __('portal_matters.card.updated_at', ['date' => $card['updated_at']]) }}
                        @else
                            {{ __('portal_matters.card.never_updated') }}
                        @endif
                    </p>

                    {{--
                        Thanh tiến độ `X/Y` của SPEC §4.10. Mẫu số bằng 0 có CÂU RIÊNG chứ không
                        hiện "0/0" kèm một thanh rỗng: một hồ sơ chưa có gì để theo dõi và một hồ
                        sơ khách chưa nộp gì là hai tình huống khác hẳn nhau. Phép chia cũng không
                        bao giờ chạm vào 0 ở nhánh này.
                    --}}
                    @if ($card['total'] > 0)
                        <div style="display:flex;flex-direction:column;gap:0.25rem;">
                            <span style="font-size:0.875rem;font-weight:600;">
                                {{ __('portal_matters.card.progress', ['submitted' => $card['submitted'], 'total' => $card['total']]) }}
                            </span>

                            <div
                                role="presentation"
                                style="height:0.5rem;border-radius:999px;overflow:hidden;background-color:color-mix(in srgb, currentColor 15%, transparent);"
                            >
                                <div style="height:100%;width:{{ $card['percent'] }}%;background-color:var(--primary-500);"></div>
                            </div>
                        </div>
                    @else
                        <p style="font-size:0.875rem;opacity:0.7;line-height:1.5;">
                            {{ __('portal_matters.card.progress_empty') }}
                        </p>
                    @endif

                    {{--
                        Huy hiệu ba màu. Câu chữ đứng ngay cạnh màu và mang toàn bộ nghĩa: màu
                        không bao giờ là kênh thông tin duy nhất (tài liệu bộ công cụ §4). Đỏ ở
                        đây là "còn giấy tờ cần nộp" theo đúng chữ của SPEC §8.2 — lý do đầy đủ
                        nằm trong docblock của `MyMatters::toneColour()`.
                    --}}
                    @if ($card['status'])
                        <p style="display:flex;align-items:center;gap:0.5rem;font-size:0.9375rem;font-weight:600;line-height:1.5;color:{{ \App\Filament\Portal\Pages\MyMatters::toneColour($card['tone']) }};">
                            <span
                                aria-hidden="true"
                                style="flex:none;height:0.625rem;width:0.625rem;border-radius:999px;background-color:currentColor;"
                            ></span>
                            {{ $card['status'] }}
                        </p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
