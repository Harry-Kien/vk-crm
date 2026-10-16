<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Filament\Admin\Concerns\IsSettingsPage;
use App\Models\SystemHealth;
use App\Support\Security\ContentSecurityPolicy;
use App\Support\Settings\LoginSecurityStatus;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Trang "Bảo mật đăng nhập" (làn st, mục 4 của brief) — trạng thái bảo mật ĐANG CHẠY, đọc-chỉ. Mỗi dòng
 * một giá trị thật ({@see LoginSecurityStatus} đọc từ chính nơi mã thi hành nó) và một câu "đặt ở đâu".
 * Không ô nào sửa được: 2FA bắt buộc là luật của hệ thống (SPEC §10), còn giới hạn IP, HTTPS/HSTS, CSP,
 * ClamAV, sao lưu là cấu hình của máy chủ (`docs/CAI-DAT.md`) — một nút bật/tắt chúng trên giao diện sẽ
 * là một cửa hậu cho chính người cần bị chặn.
 *
 * Giới hạn IP chỉ được ĐẾM: dải IP được phép không in ra màn hình.
 *
 * Cổng: {@see IsSettingsPage} (chỉ `settings.manage`, 404 ở mount và mọi request cập nhật).
 */
class LoginSecurityPage extends Page
{
    use IsSettingsPage;

    protected string $view = 'filament.admin.pages.settings.security';

    protected static ?string $slug = 'settings/security';

    public function getTitle(): string
    {
        return __('settings.security.title');
    }

    /**
     * Các dòng của trang: `label`, `value` (câu trạng thái), `tone` (huy hiệu), `where` (đặt ở đâu), và
     * `names` (chỉ dòng 2FA: tên nhân sự chưa cài).
     *
     * @return list<array{key: string, label: string, value: string, tone: string, where: string, names?: list<string>}>
     */
    public function rows(): array
    {
        $twoFactor = LoginSecurityStatus::twoFactor();
        $ipCount = LoginSecurityStatus::ipAllowlistCount();
        $hsts = LoginSecurityStatus::hstsSeconds();
        $csp = LoginSecurityStatus::cspMode();
        $last = LoginSecurityStatus::lastOffsiteBackupAt();

        return [
            $this->row('two_factor', __('settings.security.rows.two_factor.value'), 'success'),
            [
                ...$this->row(
                    'two_factor_staff',
                    __('settings.security.rows.two_factor_staff.value', ['done' => $twoFactor['done'], 'missing' => count($twoFactor['missing'])]),
                    $twoFactor['missing'] === [] ? 'success' : 'warning',
                ),
                'names' => $twoFactor['missing'],
            ],
            $this->row('lockout', __('settings.security.rows.lockout.value', [
                'attempts' => LoginSecurityStatus::lockoutAttempts(),
                'minutes' => LoginSecurityStatus::lockoutMinutes(),
            ]), 'success'),
            $this->row(
                'ip_allowlist',
                $ipCount === 0 ? __('settings.security.rows.ip_allowlist.off') : __('settings.security.rows.ip_allowlist.on', ['count' => $ipCount]),
                $ipCount === 0 ? 'gray' : 'success',
            ),
            $this->row(
                'https',
                __(LoginSecurityStatus::forcesHttps() ? 'settings.security.rows.https.on' : 'settings.security.rows.https.off'),
                LoginSecurityStatus::forcesHttps() ? 'success' : 'warning',
            ),
            $this->row(
                'hsts',
                $hsts > 0 ? __('settings.security.rows.hsts.on', ['days' => intdiv($hsts, 86400)]) : __('settings.security.rows.hsts.off'),
                $hsts > 0 ? 'success' : 'warning',
            ),
            $this->row('csp', __('settings.security.rows.csp.'.$csp), match ($csp) {
                ContentSecurityPolicy::MODE_ENFORCE => 'success',
                ContentSecurityPolicy::MODE_REPORT => 'warning',
                default => 'danger',
            }),
            $this->row(
                'clamav',
                __(LoginSecurityStatus::scansForViruses() ? 'settings.security.rows.clamav.on' : 'settings.security.rows.clamav.off'),
                LoginSecurityStatus::scansForViruses() ? 'success' : 'warning',
            ),
            $this->backupRow($last),
        ];
    }

    /**
     * Dòng "Sao lưu ngoài máy chủ". "Quá hạn" là {@see LoginSecurityStatus::offsiteBackupIsStale()} — tức
     * {@see SystemHealth::offsiteBackupIsStale()}, cùng luật với thẻ "Sao lưu" của trang tổng
     * (`SettingsCatalog::backupStatus()`): một bản đã xác minh nhưng cũ hơn ngưỡng là đỏ ở CẢ HAI nơi, không
     * xanh ở đây và đỏ ở kia (rà soát cuối I2).
     *
     * @return array{key: string, label: string, value: string, tone: string, where: string}
     */
    private function backupRow(?Carbon $last): array
    {
        if (! LoginSecurityStatus::offsiteBackupConfigured()) {
            return $this->row('backup', __('settings.security.rows.backup.not_configured'), 'warning');
        }

        if ($last === null) {
            return $this->row('backup', __('settings.security.rows.backup.never'), 'danger');
        }

        $at = $last->timezone(config('app.timezone'))->format('d/m/Y H:i');

        return LoginSecurityStatus::offsiteBackupIsStale()
            ? $this->row('backup', __('settings.security.rows.backup.stale', ['at' => $at, 'hours' => SystemHealth::offsiteBackupMaxAgeHours()]), 'danger')
            : $this->row('backup', __('settings.security.rows.backup.last', ['at' => $at]), 'success');
    }

    /** @return array{key: string, label: string, value: string, tone: string, where: string} */
    private function row(string $key, string $value, string $tone): array
    {
        return [
            'key' => $key,
            'label' => __("settings.security.rows.{$key}.label"),
            'value' => $value,
            'tone' => $tone,
            'where' => __("settings.security.rows.{$key}.where"),
        ];
    }
}
