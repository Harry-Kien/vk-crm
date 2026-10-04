<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Lớp cơ sở của mười một tool ĐỌC (kế hoạch M11, bảng tool 1–11; Task 10 dựng năm tool đầu). Đứng
 * trên {@see CrmTool} (hint R14, tiêu đề/mô tả tiếng Việt) và thêm đúng bốn việc dùng chung:
 *
 * - **`writes()` cố định `false`.** Một tool đọc không tự khai mình là tool ghi được.
 * - **Người gọi tường minh** ({@see self::actor()}): người sở hữu token, đọc từ guard `mcp` — không
 *   bao giờ `auth()` mặc định, `auth('web')` hay `auth('client')`, cả hai rỗng (hoặc là một người
 *   KHÁC) trong request `/mcp` (Review Focus 4). Action đọc nhận người này làm `$actor`.
 * - **Một thông điệp "Không tìm thấy" duy nhất** ({@see self::notFound()}): id không tồn tại, vụ đội
 *   khác, vụ hạn chế, vụ `denied`, id sai định dạng — cùng một chuỗi, cùng một hình dạng (R3, SPEC
 *   §10.10). Tool không có nhánh nào nói "có nhưng bị ẩn".
 * - **`inputSchema` chặt cả hai phía** ("Quy ước chung" của bộ tool): `toArray()` khai
 *   `additionalProperties: false` cho `inputSchema` và `outputSchema` (laravel/mcp 1.0.1 dựng object
 *   gốc của hai schema bên trong `Tool::toArray()`, nên tool con không tự đặt được); và
 *   {@see self::validated()} từ chối ở server mọi tham số ngoài khai báo, thay vì lờ đi — gói không
 *   kiểm tham số theo `inputSchema`, và một client không tôn trọng schema vẫn gửi được.
 *
 * Audit và rate limit (R8) là việc của lớp cơ sở/middleware ở Task 8, không của tool con.
 */
abstract class CrmReadTool extends CrmTool
{
    final protected function writes(): bool
    {
        return false;
    }

    /**
     * Người sở hữu token của request `/mcp` đang chạy. `auth:mcp` đứng trước server (`routes/ai.php`),
     * nên không có người là lỗi cấu hình route: từ chối, không bao giờ chạy với người rỗng.
     *
     * @throws AuthenticationException
     */
    protected function actor(): User
    {
        $user = Auth::guard('mcp')->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(__('mcp.tool_errors.unauthenticated'));
        }

        return $user;
    }

    protected function notFound(): Response
    {
        return Response::error(__('mcp.tool_errors.not_found'));
    }

    /**
     * Kết quả thành công: `structuredContent` là `$result`, `content[0].text` là đúng JSON của nó
     * (client không đọc `structuredContent` nhận cùng dữ liệu, không hơn).
     *
     * @param  array<string, mixed>  $result
     */
    protected function result(array $result): ResponseFactory
    {
        return Response::structured($result);
    }

    /**
     * Từ chối tham số ngoài `inputSchema`, rồi kiểm theo luật Laravel `$rules`. Khoá của `$rules`
     * là danh sách tham số được phép — một chỗ khai, hai việc.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validated(Request $request, array $rules): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($request->all())), array_keys($rules)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => __('mcp.tool_errors.unknown_arguments', ['names' => implode(', ', $unknown)]),
            ]);
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        $array['inputSchema']['additionalProperties'] = false;

        if (isset($array['outputSchema'])) {
            $array['outputSchema']['additionalProperties'] = false;
        }

        return $array;
    }
}
