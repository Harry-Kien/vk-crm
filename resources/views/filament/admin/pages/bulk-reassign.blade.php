{{--
    Trang "Bàn giao hàng loạt" (M7 Task 2) — luật nghiệp vụ ở docblock
    `App\Filament\Admin\Pages\BulkReassign`; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament,
    cùng thành ngữ `activity-log-properties.blade.php`/`submit-document.blade.php`.
--}}
@php
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);margin-top:1rem;';
    $rowOk = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--success-500) 12%, transparent);';
    $rowFail = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--danger-500) 12%, transparent);';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
@endphp

<x-filament-panels::page>
    <form wire:submit="reassignSelected">
        {{ $this->form }}

        <button type="submit" style="{{ $button }}margin-top:1.5rem;">
            {{ __('reassign.bulk.submit') }}
        </button>
    </form>

    @if ($this->results !== null)
        <section style="{{ $card }}">
            <h2 style="font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;">
                {{ __('reassign.bulk.results_heading') }}
            </h2>

            @foreach ($this->results as $result)
                <div style="{{ $result['success'] ? $rowOk : $rowFail }}">
                    @if ($result['matterCode'] !== null)
                        <a href="{{ \App\Filament\Admin\Resources\Matters\Pages\ViewMatter::getUrl(['record' => $result['matterId']], panel: 'admin') }}"
                           style="font-weight:700;text-decoration:none;color:inherit;">
                            {{ $result['matterCode'] }} — {{ $result['matterTitle'] }}
                        </a>
                    @else
                        <span style="font-weight:700;">#{{ $result['matterId'] }}</span>
                    @endif

                    <p style="margin-top:0.25rem;">{{ $result['message'] }}</p>

                    @if ($result['success'] && $result['suggestIntroduction'])
                        <p style="margin-top:0.25rem;{{ $muted }}">{{ __('reassign.bulk.suggest_introduction') }}</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
</x-filament-panels::page>
