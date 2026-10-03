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
 * - không còn access token, refresh token (qua access token của nó) hay mã uỷ quyền nào CÒN SỐNG.
 *   "Còn sống" = chưa thu hồi (`revoked = false`) và chưa hết hạn (`expires_at` rỗng hoặc sau lúc
 *   chạy). Refresh token sống 30 ngày, lâu hơn access token 1 giờ, nên một kết nối đang dùng (Claude
 *   làm mới trước hạn) luôn giữ client của nó; mã uỷ quyền còn sống nghĩa là có người đang ở giữa
 *   màn hình đồng ý.
 *
 * Cùng lần đó, mọi dòng token chết của client bị xoá (access token, refresh token của chúng, mã uỷ
 * quyền) — các bảng của Passport không có khoá ngoại nên không gì tự dọn chúng.
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

    private const CHUNK = 100;

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
