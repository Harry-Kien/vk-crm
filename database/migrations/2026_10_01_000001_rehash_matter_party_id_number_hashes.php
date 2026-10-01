<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * M8 Task 4 (SPEC §10.5, phán quyết controller làn m8b): `matter_parties.id_number_hash` đổi từ
 * `sha256` TRẦN sang HMAC-SHA256 khoá bằng `APP_KEY` — đúng giá trị mà
 * `App\Support\Normalizer::idNumberHash()` (gọi `App\Support\Audit::identifierHash()`) tính từ bản
 * sửa này. Lý do đổi: docblock `Normalizer::idNumberHash()`.
 *
 * # Dòng nào nhận giá trị gì
 *
 *  - Dòng có `client_id` (bên là khách hàng của văn phòng, kể cả dòng đã xoá mềm và dòng của một
 *    khách hàng đã xoá mềm): tính lại từ `clients.id_number` (giải mã) — đúng nguồn mà
 *    `BuildsMatterParties`/`SyncClientPartyIdentities` luôn dùng cho bên này. Khách hàng không có số
 *    thì `NULL`, cũng đúng như ứng dụng tự ghi. Mọi dòng có `client_id` đều được ghi lại, kể cả dòng
 *    đang `NULL` mà khách hàng CÓ số (một dòng đã lệch khỏi bất biến "bên khách hàng phản chiếu hồ
 *    sơ khách hàng" — sửa luôn cho đúng).
 *  - Dòng KHÔNG có `client_id` (bên đối lập, bên liên quan; kể cả dòng cũ tự nhận `is_our_client`
 *    mà không trỏ hồ sơ nào): số thô CHƯA BAO GIỜ được lưu — `sha256` trần là dạng lưu duy nhất của
 *    nó, và dạng đó chính là chỗ lộ phải đóng. Không tính lại được, nên `NULL`.
 *
 * **Vì sao `NULL` chấp nhận được.** Chưa có dữ liệu thật: ra mắt bị chặn bởi chính M8. Cái giá, nếu
 * phán quyết sai, là một bản cài trước ra mắt mất so trùng CCCD của bên đối lập đã nhập trước
 * migration — nhập lại số của bên đó (form "Sửa một bên") là khôi phục được.
 *
 * # Không giải mã được thì dừng, không ghi gì
 *
 * Một `clients.id_number` không giải mã được bằng `APP_KEY` hiện tại (kể cả khoá cũ khai ở
 * `APP_PREVIOUS_KEYS`) nghĩa là máy đang chạy SAI khoá — sinh khoá mới, chép nhầm `.env`. Đi tiếp
 * thì migration ghi `NULL` (hoặc một hash của khoá sai) cho mọi bên khách hàng, và kiểm tra xung
 * đột lợi ích mù với họ vĩnh viễn. Nên toàn bộ việc ghi nằm trong MỘT transaction và một lỗi giải
 * mã ném `RuntimeException` nêu đích danh `APP_KEY`: không dòng nào bị đổi, người vận hành sửa khoá
 * rồi chạy lại. (MariaDB không tự bọc migration trong transaction — `supportsSchemaTransactions()`
 * là `false` — nên transaction ở đây là tường minh.)
 *
 * # Công thức viết thẳng ở đây, không gọi `Normalizer`
 *
 * Một migration là một bức ảnh của lịch sử: nó phải cho cùng một kết quả mãi về sau, kể cả khi mã
 * ứng dụng đổi tiếp. Công thức HMAC vì vậy viết thẳng; test
 * `tests/Feature/Database/RehashMatterPartyIdNumberHashesTest.php` ghim rằng nó bằng đúng
 * `Normalizer::idNumberHash()` hôm nay.
 *
 * # `down()` khôi phục `sha256` trần cho bên khách hàng
 *
 * Lùi migration này chỉ có nghĩa khi mã cũng lùi về trước Task 4 — mã đó so bằng `sha256` trần.
 * Để các dòng mang HMAC thì kiểm tra xung đột lợi ích của mã cũ mù im lặng với mọi khách hàng, nên
 * `down()` tính lại `sha256` trần cho bên khách hàng (nguồn vẫn là `clients.id_number`). Bên không
 * có nguồn số thô giữ `NULL`: không có gì để khôi phục.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(fn () => $this->rewrite(
            fn (string $digits): string => hash_hmac('sha256', $digits, (string) config('app.key')),
        ));
    }

    public function down(): void
    {
        DB::transaction(fn () => $this->rewrite(
            fn (string $digits): string => hash('sha256', $digits),
        ));
    }

    /** @param  Closure(string): string  $hash */
    private function rewrite(Closure $hash): void
    {
        DB::table('matter_parties')
            ->whereNull('client_id')
            ->whereNotNull('id_number_hash')
            ->update(['id_number_hash' => null]);

        DB::table('matter_parties')
            ->whereNotNull('client_id')
            ->select(['id', 'client_id'])
            ->chunkById(500, function ($parties) use ($hash): void {
                $ciphertexts = DB::table('clients')
                    ->whereIn('id', $parties->pluck('client_id')->unique()->values())
                    ->pluck('id_number', 'id');

                foreach ($parties as $party) {
                    DB::table('matter_parties')->where('id', $party->id)->update([
                        'id_number_hash' => $this->hashOf($ciphertexts[$party->client_id] ?? null, (int) $party->client_id, $hash),
                    ]);
                }
            });
    }

    /** @param  Closure(string): string  $hash */
    private function hashOf(?string $ciphertext, int $clientId, Closure $hash): ?string
    {
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($ciphertext);
        } catch (DecryptException $exception) {
            throw new RuntimeException(
                "clients.id_number của khách hàng #{$clientId} không giải mã được bằng APP_KEY hiện tại — "
                .'máy đang chạy sai APP_KEY? Không dòng matter_parties nào bị đổi; khôi phục đúng APP_KEY rồi chạy lại migrate.',
                previous: $exception,
            );
        }

        $digits = (string) preg_replace('/\D+/', '', $plain);

        return $digits === '' ? null : $hash($digits);
    }
};
