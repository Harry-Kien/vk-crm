{{--
    Khối "Nháp từ AI (n)" / "Nháp trả lời từ AI (n)" trên trang vụ việc (M11 Task 12): mỗi nháp đang
    chờ là một thẻ — người soạn qua AI và lúc soạn, các trường nháp (ô "Ghi chú nội bộ" nền xám, như
    ghi chú nội bộ trên dòng thời gian), rồi hai nút "Mở nháp" / "Bỏ nháp".

    Thẻ do relation manager dựng sẵn (`StageLogsRelationManager::stageLogDraftCards()`,
    `ClientRequestsRelationManager::replyDraftCards()`); view chỉ hỏi thêm `isVisible()` của từng nút.
    Mọi giá trị in bằng `{{ }}` (đã thoát HTML): tiêu đề yêu cầu là chữ KHÁCH viết, nội dung nháp là
    chữ AI viết.

    Hai nút là action CỦA COMPONENT (`useStageLogDraft`, `discardStageLogDraft`, …), gọi với đối số
    `draft` — xem docblock `UseStageLogDraftAction`. Mỗi nút chỉ vẽ khi `isVisible()` với chính đối số
    đó (quyền + nháp thuộc vụ của trang). `$action([...])` trả một BẢN SAO mang đối số, nên bản
    action dùng chung của component không bị đổi giữa hai nút.

    Kiểu dáng bằng `style=` trên biến CSS của Filament, không lớp Tailwind (không có bước dựng CSS —
    xem docblock `StageLogsRelationManager::renderInternalNote()`).
--}}
@php
    $livewire = $getLivewire();
@endphp
<div style="display:flex;flex-direction:column;gap:0.75rem">
    @foreach ($cards as $card)
        <div
            style="border-radius:0.5rem;padding:0.75rem;font-size:0.875rem;border:1px dashed color-mix(in srgb, var(--warning-500) 60%, transparent);background-color:color-mix(in srgb, var(--warning-500) 6%, transparent)"
        >
            @if (filled($card['title']))
                <p style="font-weight:600;white-space:pre-line">{{ $card['title'] }}</p>
            @endif

            <p style="font-size:0.75rem;color:color-mix(in srgb, var(--gray-500) 90%, transparent)">{{ $card['meta'] }}</p>

            @foreach ($card['fields'] as $field)
                <div
                    style="margin-top:0.5rem;border-radius:0.375rem;{{ $field['internal'] ? 'padding:0.5rem;background-color:color-mix(in srgb, var(--gray-500) 18%, transparent)' : '' }}"
                >
                    <span style="font-weight:600">{{ $field['label'] }}</span>
                    <p style="margin-top:0.125rem;white-space:pre-line">{{ $field['value'] }}</p>
                </div>
            @endforeach

            @if (filled($card['note']))
                <p style="margin-top:0.5rem;color:var(--warning-600)">{{ $card['note'] }}</p>
            @endif

            <div style="margin-top:0.75rem;display:flex;flex-wrap:wrap;gap:0.5rem">
                @foreach ($actions as $actionName)
                    @php
                        $cached = $livewire->getAction($actionName, isMounting: false);
                        $action = $cached === null ? null : $cached(['draft' => $card['id']]);
                    @endphp

                    @if ($action?->isVisible())
                        {{ $action }}
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</div>
