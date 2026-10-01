<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Máy quét DẤU VẾT của dữ liệu nhạy cảm (SPEC §10.5, kế hoạch M8 Task 4): "quét dữ liệu thật sau
 * khi chạy luồng tạo khách hàng và luồng gửi thư, không đọc mã bằng mắt".
 *
 * Nó không đọc mã nguồn và không tin một danh sách cột viết tay. Nó nhận một tập "kim" — giá trị
 * mà test vừa cho đi qua các luồng thật — rồi tìm MỌI dạng suy ra được của từng kim ở: mọi cột của
 * mọi bảng (liệt kê bằng `Schema`), một tệp văn bản (log), và từng mục của một archive sao lưu.
 *
 * # Ba loại kim, ba cách tìm
 *
 *  - {@see self::digits()} — số định danh ít chữ số (CCCD 12 số). Tìm dãy chữ số với MỌI cách
 *    viết có dấu cách/chấm/gạch/gạch dưới/gạch chéo/dấu phẩy chen giữa bất kỳ hai chữ số nào (kể
 *    cả dạng mã hoá URL `%20`, `%2E`, `+`…), cộng `sha256`/`sha1`/`md5` TRẦN của dãy chữ số và của
 *    từng cách gõ đã đi qua màn hình. Băm trần của 12 chữ số dò ngược được bằng vét cạn — với
 *    CCCD còn nhanh hơn nữa, vì sáu chữ số đầu là mã tỉnh + thế kỷ/giới tính + năm sinh — nên một
 *    băm trần ở bất kỳ đâu là chính con số đó, chỉ đổi cách viết.
 *  - {@see self::typed()} — chuỗi ít entropy do người gõ (mật khẩu, thứ gõ nhầm vào ô email).
 *    Tìm nguyên văn (không phân biệt hoa thường), dạng mã hoá URL, và băm trần của bản gốc lẫn bản
 *    đã gấp chữ thường + cắt khoảng trắng (đúng phép gấp mà một khoá đếm theo email sẽ làm).
 *  - {@see self::secret()} — bí mật NGẪU NHIÊN entropy cao (secret 2FA 80 bit, mã khôi phục,
 *    `APP_KEY`). Chỉ tìm nguyên văn. Băm trần của chúng KHÔNG được tìm, có chủ đích: một băm của
 *    80 bit ngẫu nhiên không dò ngược được, và Filament chủ động ghi `md5(secret)` vào khoá cache
 *    chống dùng lại mã TOTP (`AppAuthentication::verifyCode()`) — tìm nó chỉ sinh báo động giả.
 *
 * # Giải mã một tầng trước khi tìm
 *
 * Một giá trị có thể nằm trong CSDL ở dạng đã bọc: `sessions.payload` là base64 của một mảng PHP
 * đã serialize, một thư ghi qua mailer `log` là MIME (quoted-printable hoặc base64, xuống dòng mỗi
 * 76 ký tự). Nên mỗi văn bản được tìm ở bốn dạng: nguyên bản; bản giải quoted-printable; bản giải
 * base64 của từng dải base64 đủ dài (≥ 24 ký tự); và cùng việc đó sau khi nối các dòng base64 bị
 * ngắt. Chỉ MỘT tầng: bản mã AES của một cột `encrypted` giải base64 ra JSON `{iv, value, mac}` mà
 * `value` vẫn là bản mã — đúng thứ phải KHÔNG khớp gì.
 *
 * # Không có ngoại lệ nào theo tên cột
 *
 * Kể cả `clients.id_number` và `users.two_factor_secret` cũng được quét: chúng phải chứa BẢN MÃ,
 * nên cũng không được chứa dạng rõ. Test gọi máy quét tự kiểm riêng (bằng cách giải mã) rằng hai
 * cột đó CÓ giữ đúng giá trị — để một lần quét sạch không thể là sạch vì luồng không ghi gì.
 */
final class SensitiveTraceScanner
{
    /** Ký tự (và dạng mã hoá URL của chúng) được phép chen giữa hai chữ số của một số định danh. */
    private const DIGIT_SEPARATOR = '(?:[\s.\-_+\/,]|%20|%2E|%2D|%2F|%2C|%5F)*';

    /** @var list<array{label: string, form: string, pattern: string}> */
    private array $needles = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Một số định danh (CCCD/CMND) và các cách nó đã được GÕ trên màn hình của lượt chạy.
     */
    public function digits(string $label, string ...$typedForms): self
    {
        $digits = (string) preg_replace('/\D+/', '', $typedForms[0] ?? '');

        if (strlen($digits) < 9) {
            throw new \InvalidArgumentException("Kim `{$label}` quá ngắn để quét không báo động giả: '{$digits}'.");
        }

        $this->add($label, 'chữ số (mọi cách viết)', '/'.implode(self::DIGIT_SEPARATOR, str_split($digits)).'/i');

        foreach (array_values(array_unique([$digits, ...$typedForms])) as $form) {
            $this->addBareHashes($label, $form);
        }

        return $this;
    }

    /** Một chuỗi ít entropy do người gõ — mật khẩu, hoặc thứ gõ nhầm vào ô email. */
    public function typed(string $label, string $value): self
    {
        foreach (array_values(array_unique([$value, mb_strtolower(trim($value))])) as $form) {
            $this->add($label, 'nguyên văn', '/'.preg_quote($form, '/').'/iu');
            $this->add($label, 'mã hoá URL', '/'.preg_quote(rawurlencode($form), '/').'/i');
            $this->add($label, 'mã hoá form', '/'.preg_quote(urlencode($form), '/').'/i');
            $this->addBareHashes($label, $form);
        }

        return $this;
    }

    /** Một bí mật ngẫu nhiên entropy cao — chỉ tìm nguyên văn (xem docblock lớp). */
    public function secret(string $label, string $value): self
    {
        if (strlen($value) < 12) {
            throw new \InvalidArgumentException("Bí mật `{$label}` quá ngắn để quét không báo động giả.");
        }

        $this->add($label, 'nguyên văn', '/'.preg_quote($value, '/').'/');

        return $this;
    }

    /**
     * Mọi cột của mọi bảng thuộc CSDL hiện tại — danh sách bảng và cột lấy từ `Schema`, không từ
     * một danh sách viết tay. Mỗi giá trị vô hướng khác null được ép về chuỗi (một số định danh lưu
     * nhầm vào cột số nguyên cũng lộ ra).
     *
     * @return list<string>
     */
    public function scanDatabase(): array
    {
        $findings = [];

        foreach ($this->tables() as $table) {
            $primaryKey = $this->primaryKey($table);
            $query = DB::table($table);

            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            foreach ($query->get() as $index => $row) {
                $rowLabel = $primaryKey === []
                    ? '[dòng '.$index.']'
                    : '#'.implode(',', array_map(fn (string $column): string => (string) $row->{$column}, $primaryKey));

                foreach ((array) $row as $column => $value) {
                    if ($value === null || ! is_scalar($value)) {
                        continue;
                    }

                    array_push($findings, ...$this->scanText("{$table}.{$column}{$rowLabel}", (string) $value));
                }
            }
        }

        return $findings;
    }

    /**
     * Số dòng của mỗi bảng có ít nhất một dòng — để test chứng minh lần quét đi qua dữ liệu thật
     * (và đúng những bảng luồng đã ghi), không phải một CSDL rỗng.
     *
     * @return array<string, int>
     */
    public function populatedTables(): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $count = DB::table($table)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    public function scanText(string $where, string $text): array
    {
        $findings = [];

        foreach ($this->decodedVariants($text) as $variant => $candidate) {
            foreach ($this->needles as $needle) {
                if (preg_match($needle['pattern'], $candidate) === 1) {
                    $findings[] = "{$where}{$variant}: {$needle['label']} — {$needle['form']}";
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Mọi mục của một archive zip (đã mã hoá thì mở bằng `$password`).
     *
     * @return list<string>
     */
    public function scanZip(string $path, ?string $password = null): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Không mở được archive {$path}.");
        }

        if ($password !== null) {
            $zip->setPassword($password);
        }

        $findings = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $content = $zip->getFromIndex($index);

                if ($content === false) {
                    throw new \RuntimeException("Không đọc được mục {$name} của archive — sai mật khẩu?");
                }

                array_push($findings, ...$this->scanText("archive:{$name}", $content));
            }
        } finally {
            $zip->close();
        }

        return $findings;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false);
    }

    /**
     * Cột khoá chính của bảng — nhãn của một phát hiện mang giá trị khoá (ổn định giữa SQLite và
     * MariaDB, khác thứ tự dòng trả về), và dòng được đọc theo thứ tự khoá đó.
     *
     * @return list<string>
     */
    private function primaryKey(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] ?? false) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /** @return array<string, string> */
    private function decodedVariants(string $text): array
    {
        $variants = ['' => $text];

        $quotedPrintable = quoted_printable_decode($text);

        if ($quotedPrintable !== $text) {
            $variants[' [quoted-printable]'] = $quotedPrintable;
        }

        // Dải base64 nằm nguyên trên một dòng (payload phiên, JSON lồng base64), rồi cùng việc đó
        // sau khi nối các dòng base64 bị ngắt ở 76 ký tự (phần thân thư MIME).
        $joined = (string) preg_replace('/(?<=[A-Za-z0-9+\/])\r?\n(?=[A-Za-z0-9+\/])/', '', $text);

        foreach (['' => $text, ' nối dòng' => $joined] as $suffix => $source) {
            $decoded = $this->decodeBase64Runs($source);

            if ($decoded !== '') {
                $variants[' [base64'.$suffix.']'] = $decoded;
            }
        }

        return $variants;
    }

    private function decodeBase64Runs(string $text): string
    {
        preg_match_all('/[A-Za-z0-9+\/]{24,}={0,2}/', $text, $matches);

        $decoded = [];

        foreach ($matches[0] as $run) {
            // Một dải cắt ra giữa chừng có thể lệch khỏi ranh giới 4 ký tự: thử cả bốn cách cắt đầu.
            for ($offset = 0; $offset < 4; $offset++) {
                $candidate = substr($run, $offset);
                $candidate = substr($candidate, 0, strlen($candidate) - (strlen($candidate) % 4));

                if ($candidate === '') {
                    continue;
                }

                $bytes = base64_decode($candidate, true);

                if ($bytes !== false) {
                    $decoded[] = $bytes;
                }
            }
        }

        return implode("\n", $decoded);
    }

    private function addBareHashes(string $label, string $form): void
    {
        foreach (['sha256', 'sha1', 'md5'] as $algorithm) {
            $this->add($label, "{$algorithm} trần của '{$form}'", '/'.hash($algorithm, $form).'/i');
        }
    }

    private function add(string $label, string $form, string $pattern): void
    {
        $this->needles[] = ['label' => $label, 'form' => $form, 'pattern' => $pattern];
    }
}
