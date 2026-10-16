{{--
    Trang "Vai trò & quyền" (làn st) — ma trận đọc-chỉ, dựng từ `App\Enums\Role::permissions()`; luật ở docblock
    `App\Filament\Admin\Pages\Settings\RolePermissionsPage`. Tệp này chỉ vẽ ra.

    Không có bước dựng CSS (CLAUDE.md) — style nội tuyến trên biến CSS của Filament. Bảng rộng hơn màn
    hình điện thoại nên nằm trong một khung cuộn ngang riêng; không tô nền đặc (chế độ tối không có biến màu nền tự đổi).
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $border = 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);';
    $roles = $this->roles();
    $headcount = $this->headcount();
    $staffUrl = $this->staffUrl();
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:56rem;">
        <p>{{ __('settings.roles.intro') }}</p>
        <p style="{{ $muted }}">
            {{ __('settings.roles.read_only') }}
            @if ($staffUrl !== null)
                <a href="{{ $staffUrl }}" style="color:var(--primary-600);font-weight:600;text-decoration:underline;">{{ __('settings.roles.staff_link') }}</a>
            @endif
        </p>
    </div>

    <div style="overflow-x:auto;border-radius:0.75rem;border:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);">
        <table style="width:100%;min-width:52rem;border-collapse:collapse;font-size:0.875rem;">
            <thead>
                <tr>
                    <th scope="col" style="text-align:left;padding:0.75rem;background-color:color-mix(in srgb, var(--gray-500) 8%, transparent);{{ $border }}">{{ __('settings.roles.permission_column') }}</th>
                    @foreach ($roles as $role)
                        <th scope="col" style="text-align:center;padding:0.75rem 0.5rem;vertical-align:bottom;background-color:color-mix(in srgb, var(--gray-500) 8%, transparent);{{ $border }}">
                            <span style="display:block;font-weight:700;">{{ $role->label() }}</span>
                            <span style="display:block;font-weight:400;font-size:0.75rem;{{ $muted }}" data-role-count="{{ $role->value }}">{{ __('settings.roles.people', ['count' => $headcount[$role->value] ?? 0]) }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($this->groups() as $group => $permissions)
                    <tr>
                        <th scope="colgroup" colspan="{{ count($roles) + 1 }}" style="text-align:left;padding:0.625rem 0.75rem;font-weight:700;background-color:color-mix(in srgb, var(--primary-500) 8%, transparent);{{ $border }}">
                            {{ __("settings.roles.groups.{$group}") }}
                        </th>
                    </tr>
                    @foreach ($permissions as $permission)
                        <tr>
                            <th scope="row" style="text-align:left;font-weight:400;padding:0.5rem 0.75rem;background-color:color-mix(in srgb, var(--gray-500) 8%, transparent);{{ $border }}">{{ $permission->label() }}</th>
                            @foreach ($roles as $role)
                                @php($granted = $this->grants($role, $permission))
                                <td data-role="{{ $role->value }}" data-permission="{{ $permission->value }}" data-granted="{{ $granted ? '1' : '0' }}"
                                    style="text-align:center;padding:0.5rem;{{ $border }}{{ $granted ? 'color:var(--success-600);font-weight:700;' : $muted }}">
                                    <span aria-hidden="true">{{ $granted ? '✓' : '—' }}</span>
                                    <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">{{ $granted ? __('settings.roles.granted') : __('settings.roles.not_granted') }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
