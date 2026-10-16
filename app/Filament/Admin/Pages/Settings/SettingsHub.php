<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Enums\Permission;
use App\Support\Settings\SettingsCard;
use App\Support\Settings\SettingsCatalog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Trang tổng "Cài đặt" (làn st, chủ văn phòng 2026-10-15: "thêm phần cài đặt trong admin… như các hệ
 * thống CRM xịn khác") — một cửa vào chung kiểu trang Settings của HubSpot / Salesforce Setup / Zoho:
 * ô tìm, các nhóm thẻ, mỗi thẻ một biểu tượng + tên + một dòng mô tả + trạng thái THẬT, bấm vào là tới
 * trang cài đặt của nó. Danh mục và trạng thái ở {@see SettingsCatalog}; trang chỉ lọc theo ô tìm và
 * vẽ ra. Các trang cài đặt sẵn có vẫn ở chỗ cũ trên menu; trang này không thay chúng.
 *
 * # Cổng: `settings.manage` (chỉ quản trị viên), hỏi ở MỌI request
 *
 * {@see self::canAccess()} hỏi `Gate::forUser()`; {@see self::boot()} trả 404 ở đầu cả lần mount lẫn
 * mọi request cập nhật Livewire (gõ vào ô tìm là một request cập nhật) — cùng khuôn `OfficeProfilePage`.
 */
class SettingsHub extends Page
{
    protected string $view = 'filament.admin.pages.settings.hub';

    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /** Cuối menu, như mục "Cài đặt" của các CRM. */
    protected static ?int $navigationSort = 1000;

    /** Ô tìm — lọc thẻ theo tên, mô tả, trạng thái ({@see SettingsCard::matches()}). */
    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('settings.hub.navigation_label');
    }

    public function getTitle(): string
    {
        return __('settings.hub.title');
    }

    public static function canAccess(): bool
    {
        return Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng". */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /**
     * Các nhóm còn thẻ khớp ô tìm; nhóm không còn thẻ nào thì bỏ.
     *
     * @return array<string, list<SettingsCard>>
     */
    public function groups(): array
    {
        abort_unless(static::canAccess(), 404);

        return array_filter(
            array_map(
                fn (array $cards): array => array_values(array_filter($cards, fn (SettingsCard $card): bool => $card->matches($this->search))),
                SettingsCatalog::groups(),
            ),
            fn (array $cards): bool => $cards !== [],
        );
    }
}
