{{--
    Khối kết quả của trang "Tìm kiếm" (M7 Task 9). Nhận từ `Search::getViewData()`: `$results`
    (`MatterSearchResults` hoặc null khi chưa tìm), `$tooShort`, `$minLength`, `$limit`.

    Không lớp CSS nào (không có bước dựng CSS) — chỉ `style=` trên biến màu Filament. Không có con số
    nào được vẽ ở đây ngoài hằng `$limit`: không đếm kết quả (R7). "Không tìm thấy" là MỘT câu cho cả
    "không có gì khớp" lẫn "có khớp nhưng không được xem".

    Liên kết chỉ có khi `matterId` khác null — `SearchMatters` để null cho người không mở được trang
    vụ việc (kế toán), và tệp này không tự dựng lại liên kết từ một nguồn khác.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $card = 'border-radius:0.75rem;padding:0.75rem 1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
@endphp

<section id="vk-search-results" aria-live="polite" style="margin-top:1rem;">
    @if ($tooShort)
        <p style="{{ $muted }}">{{ __('search.too_short', ['min' => $minLength]) }}</p>
    @elseif ($results !== null && $results->isEmpty())
        <p style="{{ $muted }}">{{ __('search.no_results') }}</p>
    @elseif ($results !== null)
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.5rem;">
            @foreach ($results->matters as $result)
                <li wire:key="vk-search-{{ $result->code }}" style="{{ $card }}">
                    <p style="margin:0;">
                        @if ($result->matterId !== null)
                            <a href="{{ \App\Filament\Admin\Resources\Matters\Pages\ViewMatter::getUrl(['record' => $result->matterId], panel: 'admin') }}"
                               style="font-weight:700;color:inherit;text-decoration:underline;">{{ $result->code }}</a>
                        @else
                            <span style="font-weight:700;">{{ $result->code }}</span>
                        @endif
                        @if ($result->title !== null)
                            <span>— {{ $result->title }}</span>
                        @endif
                    </p>

                    @php($meta = array_filter([$result->clientName, $result->matterTypeName], fn ($value) => filled($value)))
                    @if ($meta !== [])
                        <p style="margin:0.25rem 0 0;{{ $muted }}">{{ implode(' · ', $meta) }}</p>
                    @endif

                    @if ($result->hits !== [])
                        <p style="margin:0.25rem 0 0;font-size:0.875rem;">
                            <span style="{{ $muted }}">{{ __('search.matched_in') }}:</span>
                            @foreach ($result->hits as $hit)
                                <span>{{ $hit->source->label() }} “{{ $hit->text }}”</span>@if (! $loop->last)<span style="{{ $muted }}">;</span>@endif
                            @endforeach
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($results->truncated)
            <p style="margin-top:0.75rem;{{ $muted }}">{{ __('search.truncated', ['limit' => $limit]) }}</p>
        @endif
    @endif
</section>
