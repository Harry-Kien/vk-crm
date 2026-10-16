<?php

namespace App\Support\Settings;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\AiConnections;
use App\Filament\Admin\Pages\ClientPaymentSettingsPage;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Pages\DocumentStorePage;
use App\Filament\Admin\Pages\OfficeProfilePage;
use App\Filament\Admin\Pages\Settings\BusinessSettingsPage;
use App\Filament\Admin\Pages\Settings\LoginSecurityPage;
use App\Filament\Admin\Pages\Settings\MailSettingsPage;
use App\Filament\Admin\Pages\Settings\RolePermissionsPage;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\BusinessHours;
use App\Support\Mcp\McpSwitches;
use App\Support\OfficeProfile;
use App\Support\Storage\DocumentStore;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Danh mục thẻ của trang tổng "Cài đặt" (làn st, `App\Filament\Admin\Pages\Settings\SettingsHub`) — bảy
 * nhóm theo kiểu trang Settings của các CRM lớn, mỗi thẻ một trạng thái THẬT đọc từ chính nơi mã dùng
 * nó (công tắc trong `settings`, cấu hình đang chạy, số đếm trong CSDL), không trạng thái "trang trí".
 *
 * Các trang đích là trang SẴN CÓ (Thông tin văn phòng, Loại vụ việc, Nhân sự, Nhắc thanh toán, Kho tài
 * liệu, Kết nối AI, Nhật ký) — trang tổng chỉ là cửa vào chung, chúng vẫn mở được như cũ — và bốn trang
 * con mới của làn st. Một thẻ chỉ hiện khi người xem mở được trang đích của nó (`canAccess()`): hôm nay
 * mọi trang đích đều là của quản trị viên, nhưng "Nhật ký hệ thống" đi theo `auditLog.view`, không theo
 * `settings.manage`.
 *
 * "Sao lưu" không có trang trên giao diện (đặt trên máy chủ, `docs/SAO-LUU-KHOI-PHUC.md`): thẻ nói trạng thái và
 * dẫn tới "Bảo mật đăng nhập", nơi có dòng sao lưu.
 *
 * Gộp integ-4 (mục C): thẻ "Giờ làm việc & nhắc việc nội bộ" (làn tk) — giờ làm việc chỉ đặt trong
 * `config/vkcrm.php` ({@see BusinessHours::fromConfig()}) và lịch `tasks.remind` không có công tắc, nên thẻ
 * chỉ ĐỌC và dẫn tới mục đọc-chỉ `#fixed` của "Cài đặt nghiệp vụ" (không công tắc nào được bịa ra); thẻ
 * "Kế toán nhập hợp đồng" (làn kt, trang {@see ContractEntry}) đếm người đang hoạt động có `contract.enter`;
 * thẻ "Trang Tài liệu" (làn tl, trang {@see DocumentLibrary}) đếm người đang hoạt động mở được trang, qua
 * chính `DocumentLibrary::canBeOpenedBy()`.
 */
final class SettingsCatalog
{
    /** @return array<string, list<SettingsCard>> khoá nhóm (`settings.hub.groups.*`) → thẻ */
    public static function groups(): array
    {
        $business = BusinessSettings::current();
        $twoFactor = LoginSecurityStatus::twoFactor();

        $groups = [
            'office' => [
                self::card('office_profile', Heroicon::OutlinedBuildingLibrary, OfficeProfilePage::canAccess() ? OfficeProfilePage::getUrl() : null, ...self::officeStatus()),
                self::card('matter_types', Heroicon::OutlinedRectangleStack, MatterTypeResource::canAccess() ? MatterTypeResource::getUrl() : null,
                    __('settings.hub.cards.matter_types.status', ['count' => MatterType::query()->count()]), 'gray'),
            ],
            'people' => [
                self::card('staff', Heroicon::OutlinedUsers, UserResource::canAccess() ? UserResource::getUrl() : null,
                    __('settings.hub.cards.staff.status', ['count' => User::query()->where('is_active', true)->count()]), 'gray'),
                self::card('roles', Heroicon::OutlinedKey, RolePermissionsPage::canAccess() ? RolePermissionsPage::getUrl() : null,
                    __('settings.hub.cards.roles.status', ['roles' => count(Role::cases()), 'permissions' => count(Permission::cases())]), 'gray'),
                self::card('security', Heroicon::OutlinedShieldCheck, LoginSecurityPage::canAccess() ? LoginSecurityPage::getUrl() : null,
                    __('settings.hub.cards.security.status', ['done' => $twoFactor['done'], 'total' => $twoFactor['total']]),
                    $twoFactor['missing'] === [] ? 'success' : 'warning'),
            ],
            'matters' => [
                self::card('matter_rules', Heroicon::OutlinedBriefcase, self::businessUrl('matters'),
                    __('settings.hub.cards.matter_rules.status', [
                        'days' => $business->number('client_access_days'),
                        'mail' => self::onOff($business->isOn('stale_matter_mail')),
                    ]), 'gray'),
                self::card('intake_rules', Heroicon::OutlinedPhoneArrowDownLeft, self::businessUrl('intake'),
                    __('settings.hub.cards.intake_rules.status', ['hours' => $business->number('intake_response_hours')]), 'gray'),
                self::card('working_hours', Heroicon::OutlinedClock, self::businessUrl('fixed'), self::workingHoursStatus(), 'gray'),
            ],
            'portal' => [
                self::card('portal_rules', Heroicon::OutlinedGlobeAlt, self::businessUrl('portal'),
                    __('settings.hub.cards.portal_rules.status', [
                        'requests' => self::onOff($business->isOn('client_requests')),
                        'mb' => $business->number('upload_max_mb'),
                    ]), 'gray'),
                self::card('client_notifications', Heroicon::OutlinedBellAlert, self::businessUrl('client-notifications'),
                    __('settings.hub.cards.client_notifications.status', ['state' => self::onOff($business->isOn('missing_documents_mail'))]),
                    $business->isOn('missing_documents_mail') ? 'success' : 'gray'),
                self::card('mail', Heroicon::OutlinedEnvelope, MailSettingsPage::canAccess() ? MailSettingsPage::getUrl() : null,
                    MailStatus::transportLabel(), MailStatus::delivers() ? 'success' : 'warning'),
            ],
            'finance' => [
                self::card('client_payments', Heroicon::OutlinedBanknotes, ClientPaymentSettingsPage::canAccess() ? ClientPaymentSettingsPage::getUrl() : null,
                    __('settings.hub.cards.client_payments.status', ['state' => self::onOff(ClientPaymentSettings::enabled())]),
                    ClientPaymentSettings::enabled() ? 'success' : 'gray'),
                self::card('contract_entry', Heroicon::OutlinedClipboardDocumentList, ContractEntry::canAccess() ? ContractEntry::getUrl() : null,
                    __('settings.hub.cards.contract_entry.status', ['count' => self::activeStaffWho(
                        fn (User $user): bool => $user->can(Permission::ContractEnter->value),
                    )]), 'gray'),
            ],
            'documents' => [
                self::card('document_store', Heroicon::OutlinedCircleStack, DocumentStorePage::canAccess() ? DocumentStorePage::getUrl() : null,
                    __(DocumentStore::usesRemote() ? 'settings.hub.cards.document_store.status_remote' : 'settings.hub.cards.document_store.status_local'),
                    DocumentStore::usesRemote() ? 'success' : 'gray'),
                self::card('document_library', Heroicon::OutlinedDocumentText, DocumentLibrary::canAccess() ? DocumentLibrary::getUrl() : null,
                    __('settings.hub.cards.document_library.status', ['count' => self::activeStaffWho(
                        fn (User $user): bool => DocumentLibrary::canBeOpenedBy($user),
                    )]), 'gray'),
                self::card('backup', Heroicon::OutlinedArchiveBox, LoginSecurityPage::canAccess() ? LoginSecurityPage::getUrl() : null, ...self::backupStatus()),
                self::card('activity_log', Heroicon::OutlinedClipboardDocumentList, ActivityLogPage::canAccess() ? ActivityLogPage::getUrl() : null, null, 'gray'),
            ],
            'ai' => [
                self::card('ai', Heroicon::OutlinedSparkles, AiConnections::canAccess() ? AiConnections::getUrl() : null,
                    __('settings.hub.cards.ai.status', ['state' => self::onOff(McpSwitches::enabled())]),
                    McpSwitches::enabled() ? 'success' : 'gray'),
            ],
        ];

        // Chỉ thẻ người xem mở được (docblock lớp) — "Sao lưu" không có trang riêng, đi theo trang đích.
        return array_map(
            fn (array $cards): array => array_values(array_filter($cards, fn (SettingsCard $card): bool => $card->url !== null)),
            $groups,
        );
    }

    /**
     * Ngày làm việc đọc được: một dải liền ("Thứ Hai–Thứ Sáu") hay liệt kê ("Thứ Hai, Thứ Tư, Thứ Sáu") —
     * dùng chung cho thẻ và cho dòng đọc-chỉ của "Cài đặt nghiệp vụ".
     */
    public static function workingDays(BusinessHours $hours): string
    {
        $days = $hours->days;
        $isRun = count($days) > 2 && max($days) - min($days) === count($days) - 1;

        return $isRun
            ? __('settings.days.'.min($days)).'–'.__('settings.days.'.max($days))
            : implode(', ', array_map(fn (int $day): string => __("settings.days.{$day}"), $days));
    }

    /** Chuỗi để so ô tìm: chữ thường, bỏ dấu tiếng Việt (`đ` → `d`), gộp khoảng trắng. */
    public static function fold(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))));
    }

    private static function card(string $key, Heroicon $icon, ?string $url, ?string $status, string $tone): SettingsCard
    {
        return new SettingsCard(
            key: $key,
            icon: $icon,
            title: __("settings.hub.cards.{$key}.title"),
            description: __("settings.hub.cards.{$key}.description"),
            status: $status,
            tone: $tone,
            url: $url,
        );
    }

    private static function businessUrl(string $section): ?string
    {
        return BusinessSettingsPage::canAccess() ? BusinessSettingsPage::getUrl().'#'.$section : null;
    }

    private static function workingHoursStatus(): string
    {
        $hours = BusinessHours::fromConfig();

        return __('settings.hub.cards.working_hours.status', [
            'days' => self::workingDays($hours),
            'opens' => $hours->opensAt,
            'closes' => $hours->closesAt,
        ]);
    }

    /** Số nhân sự đang hoạt động thoả `$test` (một truy vấn, rồi hỏi Gate từng người — văn phòng nhỏ). */
    private static function activeStaffWho(callable $test): int
    {
        return User::query()->where('is_active', true)->get()->filter($test)->count();
    }

    private static function onOff(bool $on): string
    {
        return __($on ? 'settings.state.on' : 'settings.state.off');
    }

    /** @return array{0: string, 1: string} trạng thái + sắc thái của thẻ "Thông tin văn phòng" */
    private static function officeStatus(): array
    {
        $office = OfficeProfile::current();
        $legal = ['tax_code', 'bar_association', 'licence_number', 'office_address'];
        $filled = count(array_filter($legal, fn (string $field): bool => filled($office->value($field))));

        return [
            __('settings.hub.cards.office_profile.status', ['filled' => $filled, 'total' => count($legal)]),
            $filled === count($legal) ? 'success' : 'warning',
        ];
    }

    /**
     * "Quá hạn" là {@see LoginSecurityStatus::offsiteBackupIsStale()} (tức `SystemHealth::offsiteBackupIsStale()`),
     * cùng luật với dòng sao lưu của trang "Bảo mật đăng nhập" — không chép lại phép so ngưỡng (rà soát cuối I2).
     *
     * @return array{0: string, 1: string}
     */
    private static function backupStatus(): array
    {
        if (! LoginSecurityStatus::offsiteBackupConfigured()) {
            return [__('settings.hub.cards.backup.status_none'), 'warning'];
        }

        $last = LoginSecurityStatus::lastOffsiteBackupAt();

        if ($last === null) {
            return [__('settings.hub.cards.backup.status_never'), 'danger'];
        }

        $at = $last->timezone(config('app.timezone'))->format('d/m/Y H:i');

        return LoginSecurityStatus::offsiteBackupIsStale()
            ? [__('settings.hub.cards.backup.status_stale', ['at' => $at]), 'danger']
            : [__('settings.hub.cards.backup.status_last', ['at' => $at]), 'success'];
    }
}
