<?php

namespace App\Actions\Mcp\Write\Concerns;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Mcp\Write\CreateAiDeadline;
use App\Actions\Mcp\Write\LogAiCommunication;
use App\Exceptions\McpDryRunCompleted;
use App\Models\Matter;
use App\Models\McpConfirmation;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hai bước của hai tool ghi nội bộ (M11 R6): {@see CreateAiDeadline} và {@see LogAiCommunication}.
 *
 * **Lần một — {@see self::dryRun()}.** Action nghiệp vụ thật (`AddMatterDeadline`,
 * `LogCommunication`) chạy ĐỦ mọi lần kiểm và lần ghi của nó bên trong một transaction, rồi
 * transaction đó bị rollback. Cách này cho bản xem trước đúng những gì lần ghi thật sẽ làm — người
 * phụ trách mặc định, người liên lạc mặc định, mọi lỗi kiểm tra — mà không chép lại luật nào của hai
 * Action đó. Không gì được commit: bản ghi, dòng audit của Action, khoá vụ đều mất cùng lần rollback;
 * chỉ còn khoảng trống ở bộ đếm tự tăng. Một callback `DB::afterCommit()` đăng ký trong lần chạy thử bị
 * bỏ cùng transaction, nên không thư hay sự kiện "sau commit" nào chạy. Hai Action ấy không gửi gì
 * trong transaction (luật `ArchitectureTest`), nên không có gì khác để chặn.
 *
 * **Lần hai — {@see self::confirmOnce()}.** Một transaction, theo thứ tự khoá chung — dòng `matters`
 * TRƯỚC (câu đầu tiên), rồi mới tới mọi thứ khác:
 *  1. khoá vụ;
 *  2. `jti` của mã đã có dòng `mcp_confirmations` → trả đúng bản ghi đã tạo, không tạo bản thứ hai
 *     (gọi lại cùng mã là an toàn — `idempotentHint: true` trung thực);
 *  3. chưa có → Action nghiệp vụ ghi (transaction lồng; nó khoá lại đúng dòng vụ đã giữ), rồi dòng
 *     `mcp_confirmations` trỏ tới bản ghi đó, CÙNG transaction: không có bản ghi nào sinh từ một mã
 *     mà mã vẫn "chưa dùng", và không có dòng mã nào trỏ tới một bản ghi đã rollback.
 *
 * Hai lần gọi thứ hai chạy song song với cùng mã: khoá vụ ở bước 1 xếp chúng nối đuôi nhau (MariaDB),
 * nên lần sau thấy dòng mã của lần trước ở bước 2. Phòng thêm khi khoá không có tác dụng (SQLite) hay
 * hai vụ khác nhau (không thể: băm tham số gắn vụ): `jti` unique làm lần chèn thứ hai vỡ, transaction
 * của nó rollback cả bản ghi vừa tạo, và lần gọi đó trả bản ghi của lần thắng.
 */
trait ConfirmsInTwoSteps
{
    use ReadsWithoutPortalScope;

    /**
     * Chạy `$attempt` trong một transaction rồi rollback, trả kết quả của nó.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $attempt
     * @return TResult
     */
    protected function dryRun(Closure $attempt): mixed
    {
        try {
            DB::transaction(function () use ($attempt): never {
                throw new McpDryRunCompleted($attempt());
            });
        } catch (McpDryRunCompleted $completed) {
            return $completed->result;
        }

        throw new LogicException('Lần chạy thử không trả kết quả.');
    }

    /**
     * Lần gọi thứ hai với mã hợp lệ: bản ghi (mới tạo, hoặc bản đã tạo bằng chính mã này) và cờ "gọi
     * lại". Bản ghi `null` khi mã đã dùng nhưng bản ghi nó tạo không còn đọc được qua MCP (bị xoá trên
     * web, vụ rời tập R3): tool trả "Không tìm thấy", không tạo lại.
     *
     * @param  Closure(): Model  $write  ghi bản ghi qua Action nghiệp vụ
     * @param  Closure(int): ?Model  $find  đọc lại bản ghi theo id qua MCP (tập R3)
     * @return array{0: ?Model, 1: bool}
     */
    protected function confirmOnce(User $actor, string $tool, Matter $matter, string $jti, Closure $write, Closure $find): array
    {
        try {
            return DB::transaction(function () use ($actor, $tool, $matter, $jti, $write, $find): array {
                // Câu ĐẦU TIÊN chạm CSDL: khoá vụ (thứ tự khoá chung của dự án).
                $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

                $used = $this->usedConfirmation($jti);

                if ($used !== null) {
                    return [$this->recordOf($used, $actor, $tool, $find), true];
                }

                $record = $write();

                (new McpConfirmation([
                    'jti' => $jti,
                    'user_id' => $actor->getKey(),
                    'tool' => $tool,
                    'result_type' => $record->getMorphClass(),
                    'result_id' => $record->getKey(),
                ]))->save();

                return [$record, false];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $used = $this->usedConfirmation($jti) ?? throw $exception;

            return [$this->recordOf($used, $actor, $tool, $find), true];
        }
    }

    private function usedConfirmation(string $jti): ?McpConfirmation
    {
        return $this->scopelessly(McpConfirmation::query())->where('jti', $jti)->first();
    }

    /**
     * Bản ghi một mã đã tạo — chỉ cho đúng người và đúng tool đã dùng mã đó (mã đã ký cả hai, nên đây
     * là lớp thứ hai, không phải lớp duy nhất).
     *
     * @param  Closure(int): ?Model  $find
     */
    private function recordOf(McpConfirmation $used, User $actor, string $tool, Closure $find): ?Model
    {
        if ((int) $used->user_id !== (int) $actor->getKey() || $used->tool !== $tool) {
            return null;
        }

        return $find((int) $used->result_id);
    }
}
