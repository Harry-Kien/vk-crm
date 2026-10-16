{{--
    Trang "Kết nối AI của tôi" (M11 Task 15; làn ai 2026-10-15) — luật ở docblock
    `App\Filament\Admin\Pages\MyAiConnections` và các Action nó gọi; tệp này chỉ vẽ ra. Mọi thứ ở đây
    là của CHÍNH người đang đăng nhập.

    Làn ai: dòng trạng thái lớn, "Các bước kết nối" ✓/○, nút sao chép địa chỉ máy chủ, thẻ theo ứng
    dụng (Claude / ChatGPT / ứng dụng khác). Các bước trong ứng dụng AI lấy từ `docs/KET-NOI-AI.md`
    (khoá `ai_connections.guide.*`), không bịa giao diện của bên thứ ba.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament.
    Không `<script>` nội tuyến (CSP của M8): nút sao chép dùng biểu thức Alpine của Filament (CSP cho
    phép `unsafe-eval` đúng vì Alpine, `App\Support\Security\ContentSecurityPolicy`); thẻ ứng dụng vẽ ở
    máy chủ (`wire:click="showApp(…)"`).
--}}
@php
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $heading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $secondaryButton = 'min-height:44px;display:inline-flex;align-items:center;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);background:transparent;color:inherit;cursor:pointer;';
    $ok = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--success-500) 12%, transparent);';
    $warn = 'border-radius:0.5rem;padding:0.625rem 0.75rem;margin-top:0.5rem;background-color:color-mix(in srgb, var(--warning-500) 12%, transparent);';
    $table = 'width:100%;border-collapse:collapse;font-size:0.875rem;';
    $th = 'text-align:left;padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);font-weight:600;';
    $td = 'padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 15%, transparent);vertical-align:top;';

    $tone = match ($status) {
        \App\Enums\MyAiConnectionStatus::Connected => 'success',
        \App\Enums\MyAiConnectionStatus::NotConnected => 'primary',
        \App\Enums\MyAiConnectionStatus::NotAcknowledged => 'warning',
        default => 'gray',
    };
    $statusCard = 'border-radius:0.75rem;padding:1.25rem;border:1px solid color-mix(in srgb, var(--'.$tone.'-500) 45%, transparent);background-color:color-mix(in srgb, var(--'.$tone.'-500) 10%, transparent);';
    $stepNames = array_keys($steps);
@endphp

<x-filament-panels::page>
    <p style="max-width:48rem;">{{ __('ai_connections.mine.intro') }}</p>

    {{-- Làn ai — dòng trạng thái lớn. --}}
    <section style="{{ $statusCard }}" data-my-ai-status="{{ $status->value }}">
        <p style="{{ $muted }}font-size:0.875rem;font-weight:600;margin:0 0 0.25rem;">{{ __('ai_connections.guide.status_heading') }}</p>
        <h2 style="font-size:1.25rem;font-weight:700;margin:0;">{{ $status->label() }}</h2>
        @if ($connections !== [])
            <p style="margin:0.25rem 0 0;">{{ __('ai_connections.guide.connected_count', ['count' => count($connections)]) }}</p>
        @endif
    </section>

    {{-- Làn ai — các bước kết nối, ✓/○ theo trạng thái thật. --}}
    <section style="{{ $card }}" data-my-ai-steps>
        <h2 style="{{ $heading }}">{{ __('ai_connections.guide.steps_heading') }}</h2>

        <ol style="margin:0;padding:0;list-style:none;display:grid;gap:0.75rem;">
            @foreach ($steps as $step => $done)
                <li data-my-ai-step="{{ $step }}" data-done="{{ $done ? '1' : '0' }}"
                    style="display:flex;gap:0.75rem;align-items:flex-start;padding:0.75rem;border-radius:0.5rem;background-color:color-mix(in srgb, var(--{{ $done ? 'success' : 'gray' }}-500) {{ $done ? '12' : '6' }}%, transparent);">
                    <span aria-hidden="true" style="flex:0 0 1.75rem;height:1.75rem;border-radius:9999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;{{ $done ? 'background-color:var(--success-600);color:white;' : 'border:2px solid color-mix(in srgb, var(--gray-500) 60%, transparent);' }}">{{ $done ? '✓' : array_search($step, $stepNames, true) + 1 }}</span>

                    <div style="flex:1 1 auto;min-width:0;">
                        <p style="font-weight:600;margin:0;">{{ __('ai_connections.guide.steps.'.$step) }}</p>

                        @if ($step === 'policy')
                            <p style="{{ $muted }}font-size:0.875rem;margin:0.25rem 0 0;">{{ __('ai_connections.guide.policy_hint') }}</p>
                        @elseif ($step === 'url')
                            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin-top:0.5rem;">
                                <code style="user-select:all;word-break:break-all;font-size:1rem;padding:0.375rem 0.5rem;border-radius:0.375rem;background-color:color-mix(in srgb, var(--gray-500) 12%, transparent);">{{ $mcpUrl }}</code>
                                <button type="button" style="{{ $secondaryButton }}" data-ai-copy-url data-url="{{ $mcpUrl }}"
                                        x-data="{ copied: false }"
                                        x-on:click="navigator.clipboard.writeText($el.dataset.url).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                    <span x-show="! copied">{{ __('ai_connections.guide.copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('ai_connections.guide.copied') }}</span>
                                </button>
                            </div>
                            <p style="{{ $muted }}font-size:0.875rem;margin:0.5rem 0 0;">{{ __('ai_connections.mine.url_hint') }}</p>
                        @elseif ($step === 'app')
                            {{-- Thẻ vẽ ở máy chủ: chỉ thẻ đang mở có mặt trong trang (docblock `MyAiConnections::$appTab`). --}}
                            <div style="margin-top:0.5rem;">
                                <div role="tablist" style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                                    @foreach (\App\Filament\Admin\Pages\MyAiConnections::APP_TABS as $tab)
                                        <button type="button" role="tab" data-ai-app-tab="{{ $tab }}"
                                                wire:click="showApp('{{ $tab }}')"
                                                aria-selected="{{ $appTab === $tab ? 'true' : 'false' }}"
                                                style="{{ $appTab === $tab ? $button : $secondaryButton }}">
                                            {{ __('ai_connections.guide.tabs.'.$tab) }}
                                        </button>
                                    @endforeach
                                </div>

                                <div role="tabpanel" data-ai-app-panel="{{ $appTab }}" style="margin-top:0.75rem;">
                                    @if ($appTab === 'other')
                                        <p>{{ __('ai_connections.guide.other') }}</p>
                                    @else
                                        <ol style="margin:0 0 0 1.25rem;list-style:decimal;display:grid;gap:0.25rem;">
                                            @foreach (__('ai_connections.guide.'.$appTab.'.steps') as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ol>
                                        <p style="{{ $muted }}font-size:0.875rem;margin:0.5rem 0 0;">{{ __('ai_connections.guide.'.$appTab.'.note') }}</p>
                                        @if ($appTab === 'chatgpt')
                                            <p style="{{ $warn }}font-size:0.875rem;">{{ __('ai_connections.guide.chatgpt.write_note') }}</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <p style="margin:0.5rem 0 0;">{{ __('ai_connections.mine.guide') }}</p>
                        @else
                            <p style="{{ $muted }}font-size:0.875rem;margin:0.25rem 0 0;">{{ __('ai_connections.guide.consent_hint') }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

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
    <section id="chinh-sach" style="{{ $card }}" data-ai-policy>
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

    {{-- 3. Kết nối của tôi, kèm nút tự thu hồi. --}}
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
                                <td style="{{ $td }}">
                                    @if ($connection->lastUsedAt === null)
                                        {{ __('ai_connections.connections.never_used') }}
                                    @else
                                        {{ $connection->lastUsedAt->diffForHumans() }}
                                        <span style="display:block;{{ $muted }}font-size:0.75rem;">{{ $connection->lastUsedAt->format('d/m/Y H:i') }}</span>
                                    @endif
                                </td>
                                <td style="{{ $td }}text-align:right;">{{ ($this->revokeConnectionAction)(['client' => $connection->clientId]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
