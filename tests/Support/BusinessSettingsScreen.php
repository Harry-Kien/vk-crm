<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\BusinessSettingsPage;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Làn st — đổi "Cài đặt nghiệp vụ" qua ĐÚNG màn hình (Livewire, như quản trị viên bấm "Lưu"), không
 * gọi thẳng Action, rồi trả panel hiện hành và người đăng nhập `web` về như trước, để test đo tiếp
 * hành vi ở nơi khác (cổng khách, tác vụ lịch, job thư).
 */
final class BusinessSettingsScreen
{
    /** @param  array<string, mixed>  $values  tên trường của form → giá trị */
    public static function save(array $values, ?User $admin = null): User
    {
        $admin ??= User::factory()->withRole(Role::Admin)->create();
        $previousPanel = Filament::getCurrentPanel()?->getId();
        $previousUser = auth('web')->user();

        Filament::setCurrentPanel('admin');
        test()->actingAs($admin, 'web');

        Livewire::test(BusinessSettingsPage::class)
            ->fillForm($values)
            ->call('save')
            ->assertHasNoFormErrors();

        if ($previousPanel !== null) {
            Filament::setCurrentPanel($previousPanel);
        }

        if ($previousUser !== null) {
            test()->actingAs($previousUser, 'web');
        } else {
            auth()->guard('web')->forgetUser();
        }

        return $admin;
    }
}
