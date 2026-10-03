<?php

namespace App\Actions\Mcp;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * M11 R7 (Task 3) — dọn client OAuth do đăng ký động tạo ra (`oauth_clients.is_mcp`). DCR tạo một
 * client mới ở MỖI lần kết nối [PL:67], [PL:103]; không dọn thì bảng `oauth_clients` phình vô hạn và
 * trang "Kết nối AI" (Task 15) liệt kê hàng chục dòng rác cho một người.
 *
 * Một client bị xoá khi đủ CẢ BA:
 * - mang cờ `is_mcp` — client tạo bằng `passport:client` (hay đường nào khác) không bao giờ bị đụng;
 * - tạo cách đây hơn {@see self::DAYS_WITHOUT_LIVE_TOKEN} ngày;
 * - không còn access token, refresh token hay mã uỷ quyền nào CÒN SỐNG. "Còn sống" = chưa thu hồi
 *   (`revoked = false`) và chưa hết hạn (`expires_at` rỗng hoặc sau lúc chạy). Mã uỷ quyền còn sống
 *   nghĩa là có người đang ở giữa màn hình đồng ý.
 *
 * Refresh token chỉ nối được về client QUA dòng access token cấp cùng nó (`oauth_refresh_tokens` không
 * có `client_id`). Vì vậy lịch `passport:purge` (03:00, `routes/console.php`) chạy với `--hours=`
 * {@see self::tokenPurgeHours()} (hạn refresh token + 7 ngày), KHÔNG với mặc định 168 giờ của Passport:
 * dòng access token hết hạn chỉ bị purge xoá khi refresh token cấp cùng nó đã chết từ 169 giờ trước.
 * Với mặc định, purge xoá dòng access token của một kết nối mà nhân sự chỉ nghỉ hơn 7 ngày, lượt dọn
 * này không còn thấy refresh token đang sống, xoá client, và lần làm mới kế tiếp của Claude nhận
 * `invalid_client` (rà soát Task 3, I1). Nhờ đó một kết nối có refresh token còn sống (nhân sự quay
 * lại trong 30 ngày) luôn giữ client của nó, với hai giả định:
 * - dòng access token chỉ bị THU HỒI cùng refresh token của nó (league thu hồi cả hai khi làm mới,
 *   `AuthorizedAccessTokenController::destroy()` của Passport cũng vậy) — purge xoá dòng đã thu hồi
 *   ngay, không chờ hạn; màn hình ngắt kết nối sau này (Task 15) phải thu hồi cả refresh token;
 * - hạn refresh token không bị RÚT NGẮN sau khi token đã cấp (purge tính giờ giữ theo hạn hiện tại).
 *
 * Cùng lần đó, dòng token chết của client bị xoá: access token, refresh token nối qua chúng, mã uỷ
 * quyền — các bảng của Passport không có khoá ngoại nên không gì tự dọn chúng. Một refresh token đã
 * chết mà purge xoá mất dòng access token trước (client còn được token khác giữ lại, hay lượt dọn này
 * không chạy, quá 169 giờ sau khi nó chết) không còn nối về client nào; purge xoá nó khi chính nó hết
 * hạn quá {@see self::tokenPurgeHours()} giờ.
 *
 * Điều kiện được kiểm LẠI ngay trong câu `DELETE` (không chỉ lúc chọn): một token cấp cho client
 * giữa lúc chọn và lúc xoá giữ client lại. Token chỉ bị xoá theo client đã thật sự biến mất.
 *
 * Gọi từ lệnh `vkcrm:mcp-prune-clients` (`App\Console\Commands\PruneMcpClients`), lịch 03:15 hằng
 * ngày (`routes/console.php`), sau `passport:purge` lúc 03:00.
 */
class PruneStaleMcpClients
{
    public const DAYS_WITHOUT_LIVE_TOKEN = 30;

    /** Giờ `passport:purge` giữ token đã hết hạn theo mặc định của Passport (`--hours=168`). */
    public const PURGE_GRACE_HOURS = 168;

    private const CHUNK = 100;

    /**
     * Giá trị `--hours` của lịch `passport:purge` (`mcp.tokens.purge`): số giờ purge còn giữ token và
     * mã ĐÃ HẾT HẠN. Bằng hạn refresh token (`Passport::refreshTokensExpireIn()`, 30 ngày = 720 giờ)
     * cộng {@see self::PURGE_GRACE_HOURS} = 888. Hạn được làm tròn LÊN để không bao giờ ngắn hơn hạn
     * thật: tháng tính 31 ngày, năm 366 ngày, phần dưới một giờ thành một giờ. Access token hết hạn 1
     * giờ sau khi cấp, nên dòng của nó còn tới 169 giờ sau khi refresh token cấp cùng nó hết hạn.
     */
    public static function tokenPurgeHours(): int
    {
        $refreshTtl = Passport::refreshTokensExpireIn();

        return $refreshTtl->y * 366 * 24
            + $refreshTtl->m * 31 * 24
            + $refreshTtl->d * 24
            + $refreshTtl->h
            + (int) ceil(($refreshTtl->i * 60 + $refreshTtl->s + $refreshTtl->f) / 3600)
            + self::PURGE_GRACE_HOURS;
    }

    /** @return int số client đã xoá */
    public function handle(): int
    {
        $now = now();
        $deleted = 0;

        $ids = self::staleClients($now)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += Passport::client()->getConnection()->transaction(function () use ($chunk, $now): int {
                $count = self::staleClients($now)->whereKey($chunk)->delete();

                $gone = array_values(array_diff(
                    $chunk,
                    Passport::client()->newQuery()->whereKey($chunk)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
                ));

                if ($gone !== []) {
                    Passport::refreshToken()->newQuery()
                        ->whereIn('access_token_id', Passport::token()->newQuery()->select('id')->whereIn('client_id', $gone))
                        ->delete();
                    Passport::token()->newQuery()->whereIn('client_id', $gone)->delete();
                    Passport::authCode()->newQuery()->whereIn('client_id', $gone)->delete();
                }

                return $count;
            });
        }

        return $deleted;
    }

    /** @return Builder<Client> */
    private static function staleClients(CarbonInterface $now): Builder
    {
        $clients = Passport::client()->getTable();
        $tokens = Passport::token()->getTable();
        $refreshTokens = Passport::refreshToken()->getTable();
        $authCodes = Passport::authCode()->getTable();

        return Passport::client()->newQuery()
            ->where("{$clients}.is_mcp", true)
            ->where("{$clients}.created_at", '<', $now->copy()->subDays(self::DAYS_WITHOUT_LIVE_TOKEN))
            ->whereNotExists(fn (QueryBuilder $query) => self::alive($query->selectRaw('1')->from($tokens)
                ->whereColumn("{$tokens}.client_id", "{$clients}.id"), $tokens, $now))
            ->whereNotExists(fn (QueryBuilder $query) => self::alive($query->selectRaw('1')->from($refreshTokens)
                ->join($tokens, "{$tokens}.id", '=', "{$refreshTokens}.access_token_id")
                ->whereColumn("{$tokens}.client_id", "{$clients}.id"), $refreshTokens, $now))
            ->whereNotExists(fn (QueryBuilder $query) => self::alive($query->selectRaw('1')->from($authCodes)
                ->whereColumn("{$authCodes}.client_id", "{$clients}.id"), $authCodes, $now));
    }

    /** Dòng của `$table` chưa thu hồi và chưa hết hạn (`expires_at` rỗng hoặc sau `$now`). */
    private static function alive(QueryBuilder $query, string $table, CarbonInterface $now): QueryBuilder
    {
        return $query
            ->where("{$table}.revoked", false)
            ->where(fn (QueryBuilder $expiry) => $expiry
                ->whereNull("{$table}.expires_at")
                ->orWhere("{$table}.expires_at", '>', $now));
    }
}
