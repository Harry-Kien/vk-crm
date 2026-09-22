{{--
    SPEC §8.3 — chi tiết hồ sơ, BẢY KHỐI DỌC, đúng thứ tự SPEC liệt kê, rồi lối quay lại danh
    sách. Luật nghiệp vụ và lý do của từng quyết định nằm ở docblock
    `App\Filament\Portal\Pages\MatterProgress`; tệp này chỉ vẽ ra.

    **Tệp này không chạm vào một model nào.** Mọi accessor của trang trả về MẢNG hẹp gồm đúng
    những gì vẽ ra ở đây — vì mọi phương thức công khai của một component Livewire đều gọi được
    từ trình duyệt và giá trị trả về của nó được serialize thẳng vào response. Hệ quả cho người
    sửa tệp này: cần thêm một trường thì thêm nó vào hình chiếu bên PHP, đừng đổi hình chiếu
    thành bản ghi. Đặc biệt: **không một dòng nào ở đây được nhắc tới `internal_note`** — cột đó
    chỉ được canh ở tầng serialize, nên `$log->internal_note` trong Blade trả về chuỗi thật
    (Task 2), và hình chiếu là tầng thứ hai chứ không phải tầng duy nhất.

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
    $primaryTap = $tap.'background-color:var(--primary-600);color:var(--primary-50);';
    $quietTap = $tap.'border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);color:var(--primary-600);';
    $hotline = config('vkcrm.brand.hotline');
@endphp

<x-filament-panels::page>
    <div data-portal-page="matter-progress" style="{{ $stack }}">

        {{-- 1. TÌNH TRẠNG HIỆN TẠI ------------------------------------------------------- --}}
        @php($stage = $this->currentStage())
        <section data-portal-block="1" style="{{ $card }}background-color:color-mix(in srgb, var(--primary-500) 10%, transparent);">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.status.heading') }}</h2>

            @if ($stage)
                <p style="font-size:1.5rem;font-weight:700;line-height:1.3;">{{ $stage['label'] }}</p>

                @if (filled($stage['description']))
                    <p style="margin-top:0.5rem;font-size:1rem;{{ $muted }}">{{ $stage['description'] }}</p>
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
                                {{ $item['name'] }}
                                <span style="{{ $muted }}">— {{ __('portal_progress.checklist.status.'.$item['status']->value) }}</span>
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
                        {{ __('portal_progress.timeline.occurred_at', ['date' => $log['date']]) }}
                    </p>

                    {{-- `moved_to` chỉ có giá trị khi dòng THẬT SỰ đổi giai đoạn VÀ giai đoạn đó
                         còn được loại vụ việc khai báo; hai điều kiện ấy đã gộp bên PHP. --}}
                    @if (filled($log['moved_to']))
                        <p style="margin-top:0.25rem;font-weight:700;color:var(--primary-600);">
                            {{ __('portal_progress.timeline.moved_to', ['stage' => $log['moved_to']]) }}
                        </p>
                    @endif

                    {{-- Bốn phần của SPEC §8.3 mục 3: chuyện gì đã xảy ra / tiếp theo là gì /
                         anh/chị cần làm gì / dự kiến có tin trước ngày nào. --}}
                    @if (filled($log['public_content']))
                        <p style="margin-top:0.5rem;font-size:1.0625rem;line-height:1.5;white-space:pre-line;">{{ $log['public_content'] }}</p>
                    @endif

                    @if (filled($log['next_step']))
                        <p style="margin-top:0.5rem;"><span style="font-weight:600;">{{ __('portal_progress.timeline.next_step') }}</span>
                            <span style="white-space:pre-line;">{{ $log['next_step'] }}</span></p>
                    @endif

                    @if (filled($log['client_action']))
                        <p style="margin-top:0.5rem;color:var(--warning-600);"><span style="font-weight:700;">{{ __('portal_progress.timeline.client_action') }}</span>
                            <span style="white-space:pre-line;">{{ $log['client_action'] }}</span></p>
                    @endif

                    @if (filled($log['expected_on']))
                        <p style="margin-top:0.5rem;font-size:0.9375rem;{{ $muted }}">
                            {{ __('portal_progress.timeline.expected', ['date' => $log['expected_on']]) }}
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

                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-weight:600;">{{ $item['name'] }}</p>

                    <p style="margin-top:0.25rem;font-size:0.9375rem;color:{{ match ($item['status']) {
                        \App\Enums\ChecklistItemStatus::Accepted => 'var(--success-600)',
                        \App\Enums\ChecklistItemStatus::Rejected => 'var(--danger-600)',
                        \App\Enums\ChecklistItemStatus::Missing => 'var(--warning-600)',
                        default => 'color-mix(in srgb, var(--gray-500) 95%, transparent)',
                    } }};font-weight:600;">
                        {{ __('portal_progress.checklist.status.'.$item['status']->value) }}
                    </p>

                    <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}">
                        {{ $item['is_required'] ? __('portal_progress.checklist.required') : __('portal_progress.checklist.optional') }}
                    </p>

                    @if (filled($item['description']))
                        <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}white-space:pre-line;">{{ $item['description'] }}</p>
                    @endif

                    {{-- Lý do từ chối: ĐẦY ĐỦ, không cắt ngắn (SPEC §8.3 mục 4). Câu này được
                         viết ra để khách đọc và làm theo. --}}
                    @if (filled($item['rejection_reason']))
                        <div style="margin-top:0.5rem;border-radius:0.5rem;padding:0.625rem;background-color:color-mix(in srgb, var(--danger-500) 12%, transparent);">
                            <p style="font-weight:700;">{{ __('portal_progress.checklist.rejection_lead') }}</p>
                            <p style="margin-top:0.25rem;white-space:pre-line;">{{ $item['rejection_reason'] }}</p>
                        </div>
                    @endif

                    {{-- Nút nộp — SPEC §8.3 mục 4, HAI trạng thái và không trạng thái nào khác:
                         `missing` có nút nộp, `rejected` có nút nộp LẠI (ngay dưới lý do đầy đủ,
                         vì lý do và việc phải làm là một câu chuyện). Điều kiện ấy ở bên PHP, nơi
                         nó dùng chung một hàm với khối 2; ở đây chỉ còn "có đường nộp hay không".
                         Chuỗi thuộc về task 5: `portal_submit.entry.*`. --}}
                    @if (filled($item['submit_url']))
                        <a href="{{ $item['submit_url'] }}" style="{{ $primaryTap }}margin-top:0.5rem;">
                            {{ $item['status'] === \App\Enums\ChecklistItemStatus::Rejected
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
                    <p style="font-weight:600;">{{ $document['title'] }}</p>

                    @if (filled($document['issued_on']))
                        <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}">
                            {{ __('portal_progress.documents.issued_at', ['date' => $document['issued_on']]) }}
                        </p>
                    @endif

                    {{-- Cờ thứ hai (`client_can_download`) đã thành "có đường tải hay không" ở
                         hình chiếu: URL đã ký của M4, ký cho đúng người đọc và sống 5 phút. --}}
                    @if (filled($document['download_url']))
                        <a href="{{ $document['download_url'] }}" style="{{ $primaryTap }}margin-top:0.5rem;">
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
                @php($days = $deadline['days_left'])
                <article style="padding:0.75rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}">
                    <p style="font-weight:600;">{{ $deadline['name'] }}</p>
                    <p style="margin-top:0.25rem;{{ $muted }}">
                        {{ __('portal_progress.deadlines.due', ['date' => $deadline['due_on']]) }}
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

        {{-- 7. GỬI YÊU CẦU — cái nút VÀ số điện thoại, không rẽ nhánh ---------------------
             Trước vòng này khối 7 là `@if ($url = …) nút @else số điện thoại @endif`, và từ lúc
             `requestEntryPoint()` thôi trả `null` thì nhánh `@else` không còn đường chạy tới: số
             điện thoại văn phòng lặng lẽ biến mất khỏi trang. Hai thứ ấy không thay thế nhau —
             một người đang lo lắng lúc chín giờ tối muốn gọi, không muốn điền biểu mẫu — nên
             chúng cùng hiện, và cái nút đứng trước vì nó là cách để lại dấu vết trong hồ sơ. --}}
        <section data-portal-block="7" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_progress.blocks.requests.heading') }}</h2>
            <p>{{ __('portal_progress.blocks.requests.lead') }}</p>

            <a href="{{ $this->requestEntryPoint() }}" style="{{ $primaryTap }}margin-top:0.5rem;">
                {{ __('portal_progress.blocks.requests.open') }}
            </a>

            <p style="margin-top:0.75rem;{{ $muted }}">{{ __('portal_progress.blocks.requests.call_lead') }}</p>

            <a href="tel:{{ $hotline }}" style="{{ $quietTap }}margin-top:0.25rem;">
                {{ __('portal_progress.blocks.requests.call', ['hotline' => $hotline]) }}
            </a>
        </section>

        {{-- LỐI QUAY LẠI — `getAllUrl()`, không `/portal` trần: với một khách có đúng một hồ sơ
             thì `/portal` chuyển hướng ngược về chính trang này. --}}
        <nav>
            <a href="{{ $this->backToListUrl() }}" style="{{ $quietTap }}">
                {{ __('portal_progress.back_to_list') }}
            </a>
        </nav>

        <div data-portal-end="matter-progress"></div>
    </div>
</x-filament-panels::page>
