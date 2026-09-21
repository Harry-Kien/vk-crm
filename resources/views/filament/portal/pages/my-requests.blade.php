{{--
    SPEC §8.3 mục 7 — gửi yêu cầu và xem lại lịch sử trao đổi. Luật nghiệp vụ và lý do của từng
    quyết định nằm ở docblock `App\Filament\Portal\Pages\MyRequests`; tệp này chỉ vẽ ra.

    Hai luật của tệp này, cả hai đã có tiền sử trong dự án:

    1. **Không một model nào được cầm ở đây.** `$this->threadEntries($thread)` trả về một mảng
       chuỗi đã chọn sẵn, nên không có gì để `->internal_note`, `->email` hay `->bar_number` bám
       vào. `$reply->author` là một `MorphTo` KHÔNG scope trả về nguyên hàng `users` — một trong
       năm bề mặt mà `PortalIsolationSweepTest` nêu tên. `$thread` bản thân nó vẫn là một model,
       nhưng nó chỉ được dùng qua ba phương thức của trang (`statusLine`, `threadEntries`,
       `canReplyTo`) và qua khoá chính.

    2. **KHÔNG CÓ BƯỚC DỰNG CSS trong dự án** (CLAUDE.md): panel nạp `theme.css` đã biên dịch sẵn
       của Filament, và tệp đó chỉ chứa các lớp `fi-*` của chính Filament — một lớp tiện ích
       Tailwind viết tay ở đây (`p-4`, `text-sm`, `bg-gray-100`) KHÔNG TÔ GÌ CẢ. M3 mất hai
       milestone với đúng lỗi này. Nên mọi kiểu dáng là `style=` nội tuyến, mọi màu đi qua biến
       CSS của Filament, và độ trong suốt lấy bằng `color-mix` để một luật phủ đúng cả nền sáng
       lẫn nền tối.

    375px: một cột dọc, không bảng, mọi thứ bấm được cao tối thiểu 44px (toolchain §4).
--}}

@php
    $stack = 'display:flex;flex-direction:column;gap:1.5rem;max-width:40rem;';
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $blockHeading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.75rem;';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:0.375rem;padding:0.625rem 1rem;border-radius:0.5rem;text-decoration:none;font-weight:600;border:0;cursor:pointer;font-size:1rem;';
    $field = 'width:100%;min-height:44px;padding:0.625rem 0.75rem;border-radius:0.5rem;font-size:1rem;line-height:1.5;background-color:transparent;border:1px solid color-mix(in srgb, var(--gray-500) 45%, transparent);';
    $label = 'display:block;font-weight:600;margin-bottom:0.375rem;';
    $error = 'margin-top:0.375rem;color:var(--danger-600);font-weight:600;';
@endphp

<x-filament-panels::page>
    <div data-portal-page="my-requests" style="{{ $stack }}">

        {{-- GỬI MỘT YÊU CẦU MỚI ---------------------------------------------------------- --}}
        <section data-portal-block="new-request" style="{{ $card }}background-color:color-mix(in srgb, var(--primary-500) 10%, transparent);">
            <h2 style="{{ $blockHeading }}">{{ __('requests.portal.new.heading') }}</h2>
            <p style="margin-bottom:0.75rem;{{ $muted }}">{{ __('requests.portal.new.lead') }}</p>

            <form wire:submit="submitRequest" style="display:flex;flex-direction:column;gap:0.75rem;">
                <div>
                    <label for="request-subject" style="{{ $label }}">{{ __('requests.portal.new.subject') }}</label>
                    <input
                        id="request-subject"
                        type="text"
                        wire:model="subject"
                        placeholder="{{ __('requests.portal.new.subject_placeholder') }}"
                        style="{{ $field }}"
                    >
                    @error('subject')
                        <p data-portal-error="subject" style="{{ $error }}">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="request-content" style="{{ $label }}">{{ __('requests.portal.new.content') }}</label>
                    <textarea
                        id="request-content"
                        rows="5"
                        wire:model="content"
                        placeholder="{{ __('requests.portal.new.content_placeholder') }}"
                        style="{{ $field }}"
                    ></textarea>
                    @error('content')
                        <p data-portal-error="content" style="{{ $error }}">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" style="{{ $tap }}background-color:var(--primary-600);color:var(--primary-50);">
                    {{ __('requests.portal.new.submit') }}
                </button>
            </form>
        </section>

        {{-- LỊCH SỬ TRAO ĐỔI ------------------------------------------------------------- --}}
        <section data-portal-block="history" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('requests.portal.history.heading') }}</h2>

            @forelse ($this->threads() as $thread)
                @php($entries = $this->threadEntries($thread))
                <article
                    data-portal-thread="{{ $thread->getKey() }}"
                    style="padding:1rem 0;{{ ! $loop->last ? 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);' : '' }}"
                >
                    <h3 style="font-size:1.0625rem;font-weight:700;line-height:1.4;">{{ $thread->subject }}</h3>

                    {{-- Trạng thái bằng TIẾNG NGƯỜI, không bao giờ bằng tên enum. Màu không phải
                         kênh thông tin duy nhất (toolchain §4): câu chữ nói đủ một mình. --}}
                    <p data-portal-status="{{ $thread->getKey() }}" style="margin-top:0.25rem;font-weight:600;color:var(--primary-600);">
                        {{ $this->statusLine($thread) }}
                    </p>

                    <div style="margin-top:0.75rem;display:flex;flex-direction:column;gap:0.625rem;">
                        @foreach ($entries as $entry)
                            <div style="border-radius:0.5rem;padding:0.625rem 0.75rem;{{ $entry['role'] === 'office'
                                ? 'background-color:color-mix(in srgb, var(--primary-500) 12%, transparent);'
                                : 'border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);' }}">
                                <p style="font-size:0.9375rem;font-weight:600;">
                                    {{ $entry['role'] === 'office'
                                        ? __('requests.portal.history.from_office')
                                        : __('requests.portal.history.from_client') }}
                                    — {{ $entry['author'] }}
                                </p>
                                <p style="margin-top:0.125rem;font-size:0.875rem;{{ $muted }}">{{ $entry['at'] }}</p>
                                <p style="margin-top:0.375rem;line-height:1.5;white-space:pre-line;">{{ $entry['content'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->canReplyTo($thread))
                        <form wire:submit="submitReply({{ $thread->getKey() }})" style="margin-top:0.75rem;">
                            <label for="reply-{{ $thread->getKey() }}" style="{{ $label }}">{{ __('requests.portal.reply.label') }}</label>
                            <textarea
                                id="reply-{{ $thread->getKey() }}"
                                rows="3"
                                wire:model="replies.{{ $thread->getKey() }}"
                                placeholder="{{ __('requests.portal.reply.placeholder') }}"
                                style="{{ $field }}"
                            ></textarea>
                            @error('replies.'.$thread->getKey())
                                <p data-portal-error="reply-{{ $thread->getKey() }}" style="{{ $error }}">{{ $message }}</p>
                            @enderror
                            <button type="submit" style="{{ $tap }}margin-top:0.5rem;background-color:var(--primary-600);color:var(--primary-50);">
                                {{ __('requests.portal.reply.submit') }}
                            </button>
                        </form>
                    @else
                        {{-- Cuộc trao đổi đã kết thúc: nói ra việc tiếp theo, không để một khoảng
                             trắng. Ô viết tiếp BIẾN MẤT thay vì hiện ra rồi từ chối khi bấm. --}}
                        <p data-portal-closed="{{ $thread->getKey() }}" style="margin-top:0.75rem;{{ $muted }}">
                            {{ __('requests.portal.closed_notice') }}
                        </p>
                    @endif
                </article>
            @empty
                {{-- Trạng thái rỗng KÈM hướng dẫn bước tiếp theo (toolchain §4): không bao giờ
                     một khoảng trắng, và không bao giờ một bảng rỗng. --}}
                <p style="{{ $muted }}">{{ __('requests.portal.history.empty') }}</p>
            @endforelse
        </section>

        <a href="{{ $this->backUrl() }}" style="{{ $tap }}align-self:flex-start;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);">
            {{ __('requests.portal.back_to_matter') }}
        </a>

        <div data-portal-end="my-requests"></div>
    </div>
</x-filament-panels::page>
