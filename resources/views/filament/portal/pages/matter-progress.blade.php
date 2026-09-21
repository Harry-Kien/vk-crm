{{--
    SPEC §8.3 — chi tiết hồ sơ, BẢY KHỐI DỌC, đúng thứ tự SPEC liệt kê. Luật nghiệp vụ và lý do
    của từng quyết định nằm ở docblock `App\Filament\Portal\Pages\MatterProgress`; tệp này chỉ
    vẽ ra. Đặc biệt: **không một dòng nào ở đây được nhắc tới `internal_note`** — cột đó chỉ được
    canh ở tầng serialize, nên `$log->internal_note` trong Blade trả về chuỗi thật (Task 2).

    KHÔNG CÓ BƯỚC DỰNG CSS trong dự án (CLAUDE.md): panel nạp `theme.css` đã biên dịch sẵn của
    Filament, và tệp đó chỉ chứa các lớp `fi-*` của chính Filament — một lớp tiện ích Tailwind
    viết tay ở đây (`p-4`, `text-sm`, `bg-gray-100`) KHÔNG TÔ GÌ CẢ. Đó không phải suy đoán: M3
    mất hai milestone với đúng lỗi này. Nên mọi kiểu dáng là `style=` nội tuyến, và mọi màu đi
    qua biến CSS của Filament (`var(--gray-500)`, `var(--danger-600)`, …), lấy độ trong suốt bằng
    `color-mix` để một luật phủ đúng cả nền sáng lẫn nền tối. `MatterProgressTest` đối chiếu từng
    biến phát ra với bảng màu panel THẬT SỰ đăng ký.

    375px: một cột dọc, không bảng, mọi thứ bấm được cao tối thiểu 44px (toolchain §4).
--}}

@php
    $stack = 'display:flex;flex-direction:column;gap:1.5rem;max-width:40rem;';
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $blockHeading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.75rem;';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $tap = 'min-height: 44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 0.875rem;border-radius:0.5rem;text-decoration:none;font-weight:600;';
@endphp

<x-filament-panels::page>
    <div data-portal-page="matter-progress" style="{{ $stack }}">

        {{-- 1. TÌNH TRẠNG HIỆN TẠI ------------------------------------------------------- --}}
        @php($stage = $this->currentStage())
        <section data-portal-block="1" style="{{ $card }}background-color:color-mix(in srgb, var(--primary-500) 10%, transparent);">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.status.heading') }}</h2>

            @if ($stage)
                <p style="font-size:1.5rem;font-weight:700;line-height:1.3;">{{ $stage->client_label }}</p>

                @if (filled($stage->client_description))
                    <p style="margin-top:0.5rem;font-size:1rem;{{ $muted }}">{{ $stage->client_description }}</p>
                @endif
            @else
                <p style="font-size:1rem;{{ $muted }}">{{ __('portal_progress.blocks.status.unknown') }}</p>
            @endif
        </section>

        {{-- 2. VIỆC ANH/CHỊ CẦN LÀM — chỉ hiện khi có (SPEC §8.3 mục 2) ------------------- --}}
        @if ($this->hasTodo())
            <section data-portal-block="2" style="{{ $card }}border-width:2px;border-color:var(--warning-600);background-color:color-mix(in srgb, var(--warning-500) 12%, transparent);">
                <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.todo.heading') }}</h2>

                @if (filled($this->latestClientAction()))
                    <p style="font-size:1.0625rem;line-height:1.5;white-space:pre-line;">{{ $this->latestClientAction() }}</p>
                @endif

                @if ($this->outstandingItems()->isNotEmpty())
                    <p style="margin-top:0.75rem;font-weight:600;">{{ __('portal_progress.blocks.todo.documents_lead') }}</p>
                    <ul style="margin-top:0.375rem;padding-left:1.25rem;list-style:disc;">
                        @foreach ($this->outstandingItems() as $item)
                            <li style="margin-top:0.25rem;">
                                {{ $item->name }}
                                <span style="{{ $muted }}">— {{ __('portal_progress.checklist.status.'.$item->status->value) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- 3. DIỄN BIẾN VỤ VIỆC ---------------------------------------------------------- --}}
        <section data-portal-block="3" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.timeline.heading') }}</h2>

            @forelse ($this->timeline() as $log)
                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-size:0.875rem;{{ $muted }}">
                        {{ __('portal_progress.timeline.occurred_at', ['date' => $log->occurred_at?->format('d/m/Y')]) }}
                    </p>

                    @if ($this->isStageChange($log) && filled($this->stageLabel($log->to_stage)))
                        <p style="margin-top:0.25rem;font-weight:700;color:var(--primary-600);">
                            {{ __('portal_progress.timeline.moved_to', ['stage' => $this->stageLabel($log->to_stage)]) }}
                        </p>
                    @endif

                    {{-- Bốn phần của SPEC §8.3 mục 3: chuyện gì đã xảy ra / tiếp theo là gì /
                         anh/chị cần làm gì / dự kiến có tin trước ngày nào. --}}
                    @if (filled($log->public_content))
                        <p style="margin-top:0.5rem;font-size:1.0625rem;line-height:1.5;white-space:pre-line;">{{ $log->public_content }}</p>
                    @endif

                    @if (filled($log->next_step))
                        <p style="margin-top:0.5rem;"><span style="font-weight:600;">{{ __('portal_progress.timeline.next_step') }}</span>
                            <span style="white-space:pre-line;">{{ $log->next_step }}</span></p>
                    @endif

                    @if (filled($log->client_action))
                        <p style="margin-top:0.5rem;color:var(--warning-600);"><span style="font-weight:700;">{{ __('portal_progress.timeline.client_action') }}</span>
                            <span style="white-space:pre-line;">{{ $log->client_action }}</span></p>
                    @endif

                    @if ($log->expected_next_update_at)
                        <p style="margin-top:0.5rem;font-size:0.9375rem;{{ $muted }}">
                            {{ __('portal_progress.timeline.expected', ['date' => $log->expected_next_update_at->format('d/m/Y')]) }}
                        </p>
                    @endif
                </article>
            @empty
                <p style="{{ $muted }}">{{ __('portal_progress.blocks.timeline.empty') }}</p>
            @endforelse
        </section>

        {{-- 4. HỒ SƠ GIẤY TỜ -------------------------------------------------------------- --}}
        <section data-portal-block="4" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.checklist.heading') }}</h2>

            @forelse ($this->checklistItems() as $item)
                @if ($loop->first)
                    <p style="margin-bottom:0.75rem;font-weight:600;">{{ __('portal_progress.checklist.progress', $this->checklistProgress()) }}</p>
                @endif

                @php($reason = $this->rejectionReason($item))
                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-weight:600;">{{ $item->name }}</p>

                    <p style="margin-top:0.25rem;font-size:0.9375rem;color:{{ match ($item->status) {
                        \App\Enums\ChecklistItemStatus::Accepted => 'var(--success-600)',
                        \App\Enums\ChecklistItemStatus::Rejected => 'var(--danger-600)',
                        \App\Enums\ChecklistItemStatus::Missing => 'var(--warning-600)',
                        default => 'color-mix(in srgb, var(--gray-500) 95%, transparent)',
                    } }};font-weight:600;">
                        {{ __('portal_progress.checklist.status.'.$item->status->value) }}
                    </p>

                    <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}">
                        {{ $item->is_required ? __('portal_progress.checklist.required') : __('portal_progress.checklist.optional') }}
                    </p>

                    @if (filled($item->description))
                        <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}white-space:pre-line;">{{ $item->description }}</p>
                    @endif

                    {{-- Lý do từ chối: ĐẦY ĐỦ, không cắt ngắn (SPEC §8.3 mục 4). Câu này được
                         viết ra để khách đọc và làm theo. --}}
                    @if (filled($reason))
                        <div style="margin-top:0.5rem;border-radius:0.5rem;padding:0.625rem;background-color:color-mix(in srgb, var(--danger-500) 12%, transparent);">
                            <p style="font-weight:700;">{{ __('portal_progress.checklist.rejection_lead') }}</p>
                            <p style="margin-top:0.25rem;white-space:pre-line;">{{ $reason }}</p>
                        </div>
                    @endif

                    {{-- Nút nộp — SPEC §8.3 mục 4, HAI trạng thái và không trạng thái nào khác:
                         `missing` có nút nộp, `rejected` có nút nộp LẠI (ngay dưới lý do đầy đủ,
                         vì lý do và việc phải làm là một câu chuyện). Khối này là danh sách "còn
                         thiếu gì", không phải một bảng thao tác — một cái nút trên một đầu mục
                         đã nhận đủ chỉ mời khách gửi lại thứ văn phòng đã có.

                         Màn hình nộp thì nhận cả những trạng thái khác (nó là đường sửa sai duy
                         nhất của khách — xem docblock `App\Filament\Portal\Pages\SubmitDocument`);
                         lối vào ở đây hẹp hơn một cách có chủ đích.

                         Chuỗi và URL đều thuộc về task 5: `portal_submit.entry.*` và
                         `SubmitDocument::urlForItem()` — trang kia sở hữu hình dạng URL của chính
                         nó, nên một ngày nó đổi thì lời gọi này đi theo. --}}
                    @if (in_array($item->status, [\App\Enums\ChecklistItemStatus::Missing, \App\Enums\ChecklistItemStatus::Rejected], true))
                        <a href="{{ \App\Filament\Portal\Pages\SubmitDocument::urlForItem($item) }}"
                           style="{{ $tap }}margin-top:0.5rem;background-color:var(--primary-600);color:var(--primary-50);">
                            {{ $item->status === \App\Enums\ChecklistItemStatus::Rejected
                                ? __('portal_submit.entry.resubmit')
                                : __('portal_submit.entry.submit') }}
                        </a>
                    @endif
                </article>
            @empty
                <p style="{{ $muted }}">{{ __('portal_progress.blocks.checklist.empty') }}</p>
            @endforelse
        </section>

        {{-- 5. TÀI LIỆU — hai cờ độc lập (SPEC §6.5 bước 3) ------------------------------- --}}
        <section data-portal-block="5" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.documents.heading') }}</h2>

            @forelse ($this->documents() as $document)
                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-weight:600;">{{ $document->title }}</p>

                    @if ($document->issued_at)
                        <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}">
                            {{ __('portal_progress.documents.issued_at', ['date' => $document->issued_at->format('d/m/Y')]) }}
                        </p>
                    @endif

                    @if ($this->canDownload($document))
                        <a href="{{ $this->downloadUrl($document) }}"
                           style="{{ $tap }}margin-top:0.5rem;background-color:var(--primary-600);color:var(--primary-50);">
                            {{ __('portal_progress.documents.download') }}
                        </a>
                    @else
                        <p style="margin-top:0.5rem;font-size:0.9375rem;{{ $muted }}">{{ __('portal_progress.documents.view_only') }}</p>
                    @endif
                </article>
            @empty
                <p style="{{ $muted }}">{{ __('portal_progress.blocks.documents.empty') }}</p>
            @endforelse
        </section>

        {{-- 6. MỐC THỜI HẠN SẮP TỚI — chỉ mốc đã công bố ---------------------------------- --}}
        <section data-portal-block="6" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.deadlines.heading') }}</h2>

            @forelse ($this->deadlines() as $deadline)
                @php($days = (int) today()->diffInDays($deadline->due_date, false))
                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-weight:600;">{{ $deadline->name }}</p>
                    <p style="margin-top:0.25rem;{{ $muted }}">
                        {{ __('portal_progress.deadlines.due', ['date' => $deadline->due_date->format('d/m/Y')]) }}
                    </p>
                    {{-- Màu KHÔNG phải kênh thông tin duy nhất (toolchain §4): mỗi màu đi kèm chữ. --}}
                    <p style="margin-top:0.25rem;font-weight:700;color:{{ $days < 0 ? 'var(--danger-600)' : ($days <= 3 ? 'var(--warning-600)' : 'color-mix(in srgb, var(--gray-500) 95%, transparent)') }};">
                        @if ($days < 0)
                            {{ __('portal_progress.deadlines.overdue') }}
                        @elseif ($days === 0)
                            {{ __('portal_progress.deadlines.today') }}
                        @else
                            {{ __('portal_progress.deadlines.in_days', ['count' => $days]) }}
                        @endif
                    </p>
                </article>
            @empty
                <p style="{{ $muted }}">{{ __('portal_progress.blocks.deadlines.empty') }}</p>
            @endforelse
        </section>

        {{-- 7. GỬI YÊU CẦU — lối vào Task 6; hôm nay là số điện thoại văn phòng ----------- --}}
        <section data-portal-block="7" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.requests.heading') }}</h2>
            <p>{{ __('portal_progress.blocks.requests.lead') }}</p>

            @if ($url = $this->requestEntryPoint())
                <a href="{{ $url }}" style="{{ $tap }}margin-top:0.5rem;background-color:var(--primary-600);color:var(--primary-50);">
                    {{ __('portal_progress.blocks.requests.open') }}
                </a>
            @else
                <p style="margin-top:0.5rem;{{ $muted }}">
                    {{ __('portal_progress.blocks.requests.call', ['hotline' => config('vkcrm.brand.hotline')]) }}
                </p>
            @endif
        </section>

        <div data-portal-end="matter-progress"></div>
    </div>
</x-filament-panels::page>
