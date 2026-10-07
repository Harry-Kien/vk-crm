{{--
    Trang "Kết nối AI của tôi" (M11 Task 15) — luật ở docblock `App\Filament\Admin\Pages\MyAiConnections`
    và các Action nó gọi; tệp này chỉ vẽ ra. Mọi thứ ở đây là của CHÍNH người đang đăng nhập.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament.
    Không script nội tuyến (CSP của M8).
--}}
@php
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $heading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $ok = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--success-500) 12%, transparent);';
    $warn = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--warning-500) 12%, transparent);';
    $table = 'width:100%;border-collapse:collapse;font-size:0.875rem;';
    $th = 'text-align:left;padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);font-weight:600;';
    $td = 'padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 15%, transparent);vertical-align:top;';
@endphp

<x-filament-panels::page>
    <p style="max-width:48rem;">{{ __('ai_connections.mine.intro') }}</p>

    {{-- 1. Chế độ của tôi. --}}
    <section style="{{ $card }}">
        <h2 style="{{ $heading }}">{{ __('ai_connections.mine.mode_heading') }}</h2>
        <p>{{ __('ai_connections.mine.mode', ['mode' => $user->ai_access->label()]) }}</p>

        @if ($refusal === null)
            <p style="{{ $ok }}">{{ __('ai_connections.mine.status_ready') }}</p>
        @else
            <p style="{{ $warn }}">{{ __('ai_connections.mine.status_refused', ['reason' => $refusal->label()]) }}</p>
        @endif

        <p style="{{ $muted }}margin-top:0.5rem;">{{ __('ai_connections.mine.mode_hint') }}</p>
    </section>

    {{-- 2. Chính sách dùng AI và ô cam kết (R12 mục 1). --}}
    <section style="{{ $card }}" data-ai-policy>
        @if ($policyVersion === null)
            <p style="{{ $warn }}">{{ __('ai_connections.policy.unavailable') }}</p>
        @else
            <h2 style="{{ $heading }}">{{ __('ai_connections.policy.heading', ['version' => $policyVersion]) }}</h2>
            <p style="{{ $muted }}">{{ __('ai_connections.policy.intro') }}</p>

            <ol style="margin:0.5rem 0 0.5rem 1.25rem;list-style:decimal;">
                @foreach (__('ai_connections.policy.items') as $item)
                    <li style="margin-top:0.25rem;">{{ $item }}</li>
                @endforeach
            </ol>

            @if ($currentAcknowledgement !== null)
                <p style="{{ $ok }}">{{ __('ai_connections.policy.acknowledged', [
                    'version' => $currentAcknowledgement->policy_version,
                    'date' => $currentAcknowledgement->accepted_at->format('d/m/Y H:i'),
                ]) }}</p>
            @else
                <form wire:submit="acknowledge" style="margin-top:0.75rem;">
                    {{ $this->acknowledgementForm }}

                    <button type="submit" style="{{ $button }}margin-top:0.75rem;" wire:loading.attr="disabled" wire:target="acknowledge">
                        {{ __('ai_connections.policy.submit') }}
                    </button>
                </form>
            @endif
        @endif
    </section>

    {{-- 3. URL MCP và hướng dẫn. --}}
    <section style="{{ $card }}">
        <h2 style="{{ $heading }}">{{ __('ai_connections.mine.url_heading') }}</h2>
        <p><code style="user-select:all;word-break:break-all;font-size:1rem;">{{ $mcpUrl }}</code></p>
        <p style="{{ $muted }}margin-top:0.5rem;">{{ __('ai_connections.mine.url_hint') }}</p>
        <p style="margin-top:0.5rem;">{{ __('ai_connections.mine.guide') }}</p>
    </section>

    {{-- 4. Kết nối của tôi, kèm nút tự thu hồi. --}}
    <section style="{{ $card }}">
        <h2 style="{{ $heading }}">{{ __('ai_connections.mine.connections_heading') }}</h2>

        @if ($connections === [])
            <p style="{{ $muted }}">{{ __('ai_connections.mine.connections_empty') }}</p>
        @else
            <div style="overflow-x:auto;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">{{ __('ai_connections.connections.platform') }}</th>
                            <th style="{{ $th }}">{{ __('ai_connections.connections.host') }}</th>
                            <th style="{{ $th }}">{{ __('ai_connections.connections.connected_at') }}</th>
                            <th style="{{ $th }}">{{ __('ai_connections.connections.last_used_at') }}</th>
                            <th style="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($connections as $connection)
                            <tr wire:key="connection-{{ $connection->clientId }}" data-connection="{{ $connection->clientId }}">
                                <td style="{{ $td }}">{{ $connection->platform->label() }}</td>
                                <td style="{{ $td }}"><code>{{ $connection->host }}</code></td>
                                <td style="{{ $td }}">{{ $connection->connectedAt->format('d/m/Y H:i') }}</td>
                                <td style="{{ $td }}">{{ $connection->lastUsedAt?->format('d/m/Y H:i') ?? __('ai_connections.connections.never_used') }}</td>
                                <td style="{{ $td }}text-align:right;">{{ ($this->revokeConnectionAction)(['client' => $connection->clientId]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
