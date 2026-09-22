{{--
    SPEC §8.4 — nộp giấy tờ, BỐN BƯỚC DỌC: chọn đầu mục → chụp ảnh hoặc chọn tệp → xem trước →
    gửi. Luật nghiệp vụ và lý do của từng quyết định nằm ở docblock
    `App\Filament\Portal\Pages\SubmitDocument`; tệp này chỉ vẽ ra.

    **Không một dòng nào ở đây được nhắc tới `internal_note`** — cột đó chỉ được canh ở tầng
    serialize, nên `$x->internal_note` trong Blade trả về chuỗi thật (Task 2).

    KHÔNG CÓ BƯỚC DỰNG CSS trong dự án (CLAUDE.md): panel nạp `theme.css` đã biên dịch sẵn của
    Filament, và tệp đó chỉ chứa các lớp `fi-*` của chính Filament — một lớp tiện ích Tailwind
    viết tay ở đây (`p-4`, `text-sm`) KHÔNG TÔ GÌ CẢ. Nên mọi kiểu dáng là `style=` nội tuyến và
    mọi màu đi qua biến CSS của Filament, lấy độ trong suốt bằng `color-mix` để một luật phủ đúng
    cả nền sáng lẫn nền tối. Cùng thành ngữ `matter-progress.blade.php`.

    375px: một cột dọc, không bảng, mọi thứ bấm được cao tối thiểu 44px (toolchain §4).
--}}

@php
    $stack = 'display:flex;flex-direction:column;gap:1.5rem;max-width:40rem;';
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $blockHeading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.75rem;';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $tap = 'min-height: 44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 0.875rem;border-radius:0.5rem;text-decoration:none;font-weight:600;';
    $choice = 'min-height: 44px;display:flex;flex-direction:column;align-items:flex-start;gap:0.125rem;width:100%;text-align:left;padding:0.75rem;border-radius:0.5rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);background-color:transparent;';
    $chosen = $this->checklistItem();
@endphp

<x-filament-panels::page>
    <div data-portal-page="submit-document" style="{{ $stack }}">

        {{-- 1. CHỌN ĐẦU MỤC ------------------------------------------------------------- --}}
        <section data-portal-block="1" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_submit.steps.item.heading') }}</h2>

            @if ($chosen)
                <p style="{{ $muted }}">{{ __('portal_submit.steps.item.chosen') }}</p>
                <p style="margin-top:0.25rem;font-size:1.25rem;font-weight:700;line-height:1.3;">{{ $chosen->name }}</p>

                @if (filled($chosen->description))
                    <p style="margin-top:0.25rem;font-size:0.9375rem;{{ $muted }}white-space:pre-line;">{{ $chosen->description }}</p>
                @endif

                {{-- Lý do từ chối, ĐẦY ĐỦ (SPEC §8.3 mục 4): khách đang đứng trước màn hình để
                     sửa đúng cái việc câu này nói ra, nên nó phải ở ngay đây chứ không chỉ ở
                     trang trước. --}}
                @if ($chosen->status === \App\Enums\ChecklistItemStatus::Rejected && filled($chosen->rejection_reason))
                    <div style="margin-top:0.75rem;border-radius:0.5rem;padding:0.625rem;background-color:color-mix(in srgb, var(--danger-500) 12%, transparent);">
                        <p style="font-weight:700;">{{ __('portal_progress.checklist.rejection_lead') }}</p>
                        <p style="margin-top:0.25rem;white-space:pre-line;">{{ $chosen->rejection_reason }}</p>
                    </div>
                @endif

                <button type="button" wire:click="clearItem"
                        style="{{ $tap }}margin-top:0.75rem;border:1px solid var(--primary-600);color:var(--primary-600);background-color:transparent;">
                    {{ __('portal_submit.steps.item.change') }}
                </button>
            @else
                <p style="{{ $muted }}">{{ __('portal_submit.steps.item.lead') }}</p>

                @forelse ($this->choosableItems() as $item)
                    <button type="button" wire:click="chooseItem({{ $item->getKey() }})"
                            style="{{ $choice }}margin-top:0.5rem;{{ $this->isOutstanding($item) ? 'border-color:var(--warning-600);border-width:2px;' : '' }}">
                        <span style="font-weight:600;">{{ $item->name }}</span>
                        <span style="font-size:0.9375rem;{{ $muted }}">{{ $this->statusLabel($item) }}</span>
                    </button>
                @empty
                    <p style="margin-top:0.5rem;{{ $muted }}">{{ __('portal_submit.steps.item.empty') }}</p>
                @endforelse
            @endif

            @error('item')
                <p style="margin-top:0.5rem;color:var(--danger-600);font-weight:600;">{{ $message }}</p>
            @enderror
        </section>

        {{-- 2. CHỤP ẢNH HOẶC CHỌN TỆP ---------------------------------------------------- --}}
        <section data-portal-block="2" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_submit.steps.file.heading') }}</h2>

            @if ($chosen)
                <div data-portal-field="file">
                    {{ $this->form }}
                </div>
            @else
                <p style="{{ $muted }}">{{ __('portal_submit.steps.file.choose_item_first') }}</p>
            @endif
        </section>

        {{-- 3. XEM TRƯỚC — SPEC §8.4 đặt bước này TRƯỚC bước gửi ------------------------- --}}
        @php($pending = $this->pendingFile())
        <section data-portal-block="3" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_submit.steps.preview.heading') }}</h2>

            @if ($pending)
                <p>{{ __('portal_submit.steps.preview.lead') }}</p>
                <p style="margin-top:0.5rem;font-weight:600;word-break:break-all;">
                    {{ __('portal_submit.steps.preview.file', ['name' => $pending['name'], 'size' => $pending['size']]) }}
                </p>
            @else
                <p style="{{ $muted }}">{{ __('portal_submit.steps.preview.none') }}</p>
            @endif
        </section>

        {{-- 4. GỬI ----------------------------------------------------------------------- --}}
        <section data-portal-block="4" style="{{ $card }}">
            <h2 style="{{ $blockHeading }}">{{ __('portal_submit.steps.send.heading') }}</h2>

            @if ($this->submitted)
                {{-- SPEC §8.4 nguyên văn: sau khi gửi hiện "Đang chờ văn phòng kiểm tra". --}}
                <div style="border-radius:0.5rem;padding:0.75rem;background-color:color-mix(in srgb, var(--success-500) 14%, transparent);">
                    <p style="font-size:1.125rem;font-weight:700;">{{ __('portal_submit.done.heading') }}</p>
                    <p style="margin-top:0.25rem;font-size:1.0625rem;font-weight:600;color:var(--success-600);">
                        {{ __('portal_submit.done.status') }}
                    </p>
                    <p style="margin-top:0.5rem;line-height:1.5;">
                        {{ __('portal_submit.done.body', ['name' => $this->submittedItemName]) }}
                    </p>
                </div>

                <button type="button" wire:click="clearItem"
                        style="{{ $tap }}margin-top:0.75rem;border:1px solid var(--primary-600);color:var(--primary-600);background-color:transparent;">
                    {{ __('portal_submit.done.another') }}
                </button>
            @elseif (! $this->canSubmit())
                {{-- SPEC §10.9: một tài khoản đang tạm ngưng cần một con đường KHÔNG đi qua tài
                     khoản, nên câu này chỉ tới điện thoại văn phòng chứ không tới một cái nút. --}}
                <p style="{{ $muted }}">
                    {{ __('portal_submit.steps.send.locked', ['hotline' => config('vkcrm.brand.hotline')]) }}
                </p>
            @else
                <button type="button" wire:click="submit" data-portal-action="send"
                        wire:loading.attr="disabled" wire:target="submit"
                        style="{{ $tap }}width:100%;justify-content:center;font-size:1.0625rem;background-color:var(--primary-600);color:var(--primary-50);border:none;">
                    {{ __('portal_submit.steps.send.button') }}
                </button>
            @endif
        </section>

        <a href="{{ $this->matterUrl() }}" style="{{ $tap }}justify-content:center;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);">
            {{ __('portal_submit.back') }}
        </a>

        <div data-portal-end="submit-document"></div>
    </div>
</x-filament-panels::page>
