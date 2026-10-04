<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use Closure;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Access token của tài khoản dịch vụ Google (kế hoạch M14, R1, R6): `google/auth` ký JWT RS256 bằng
 * khoá trong tệp JSON (`GOOGLE_DRIVE_CREDENTIALS_PATH`, ngoài repo và ngoài gốc web) và đổi nó lấy
 * access token ở `https://oauth2.googleapis.com/token`. Phần dễ sai nhất và dễ lộ bí mật nhất giao
 * cho thư viện chính chủ; HTTP của nó đi qua `Http` của Laravel (tham số `httpHandler`), nên
 * `Http::fake()` phủ cả endpoint token và không có đường gọi mạng nào ngoài `Http`.
 *
 * - **Phạm vi `drive`**, không `drive.file`: `drive.file` hẹp hơn nhưng không đọc được `drives.get`
 *   (kiểm chia sẻ của R5). Một khoá bị lộ thì xin được mọi phạm vi mà tài khoản dịch vụ có, nên thu
 *   hẹp phạm vi ở đây chỉ che một TOKEN bị lộ trong một giờ; hàng rào thật là vai "Người quản lý nội
 *   dung" trên đúng một Shared Drive (R5). Không domain-wide delegation: không đặt `sub`.
 * - **JWT sống 3540 giây**: thư viện lùi `iat` 60 giây (lệch đồng hồ) và mặc định `exp` = bây giờ +
 *   3600, tức `exp − iat` = 3660, vượt giới hạn "tối đa 1 giờ sau `iat`" mà Google ghi cho assertion.
 *   3540 giữ `exp − iat` đúng 3600.
 * - **Cache 50 phút** trong store `vkcrm.storage.google_drive.token_cache_store` (`file`), KHÔNG
 *   trong store mặc định `database`: bản sao lưu CSDL không bao giờ mang một token còn sống. Khoá
 *   cache theo đường dẫn tệp khoá.
 * - Không đọc tệp khoá cho tới lần đầu cần token: dựng đối tượng này không bao giờ ném.
 * - Log và ngoại lệ không bao giờ mang token, header, thân phản hồi của endpoint token, hay nội dung
 *   khoá: chỉ mã trạng thái và mã lỗi OAuth (`invalid_grant`, …). Lỗi do thư viện ném lên (khoá hỏng,
 *   ký không được) chỉ để lại TÊN LỚP trong log; thông điệp của chúng không được chép.
 */
final class ServiceAccountTokenProvider implements DriveTokenProvider
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    public const CACHE_SECONDS = 3000;

    public const JWT_LIFETIME_SECONDS = 3540;

    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly Repository $cache,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetch();
        $this->cache->put($this->cacheKey(), $token, self::CACHE_SECONDS);

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'drive-access-token:'.hash('sha256', (string) $this->credentialsPath);
    }

    private function fetch(): string
    {
        $credentials = $this->credentials();

        try {
            $result = $credentials->fetchAuthToken($this->httpHandler());
        } catch (DocumentStorageMisconfigured|DocumentStorageUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error(__('storage.drive.log.credentials_unusable'), ['exception' => $e::class]);

            throw DocumentStorageMisconfigured::credentialsUnusable();
        }

        $token = $result['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw DocumentStorageMisconfigured::tokenRejected('no_access_token');
        }

        return $token;
    }

    /**
     * Đọc và kiểm tệp khoá. Kiểm quyền tệp (R6: không nằm dưới gốc web, không ai khác đọc được) là
     * việc của dòng `drive_credentials` của kiểm tra sẵn sàng, không ở đây.
     */
    private function credentials(): ServiceAccountCredentials
    {
        if ($this->credentialsPath === null || $this->credentialsPath === '') {
            throw DocumentStorageMisconfigured::credentialsMissing();
        }

        $json = json_decode((string) @file_get_contents($this->credentialsPath), true);

        // `client_email` phải kiểm ở đây: thư viện nhận `null` và ký một JWT không có `iss`, rồi gửi
        // nó đi. Thiếu `private_key` hay khoá không ký được thì thư viện ném lúc dựng hoặc lúc ký
        // (trước mọi request); cả hai đường đều thành `credentialsUnusable()`.
        if (($json['type'] ?? null) !== 'service_account'
            || ! is_string($json['client_email'] ?? null)
            || $json['client_email'] === '') {
            throw DocumentStorageMisconfigured::credentialsUnusable();
        }

        try {
            $credentials = new ServiceAccountCredentials(self::SCOPE, $json);
        } catch (Throwable $e) {
            Log::error(__('storage.drive.log.credentials_unusable'), ['exception' => $e::class]);

            throw DocumentStorageMisconfigured::credentialsUnusable();
        }

        // `$auth` (OAuth2) là thuộc tính protected của thư viện; đặt thời hạn assertion qua nó.
        (fn () => $this->auth->setExpiry(ServiceAccountTokenProvider::JWT_LIFETIME_SECONDS))->call($credentials);

        return $credentials;
    }

    /**
     * Bộ chuyển HTTP cho `google/auth`: request PSR-7 → `Http` của Laravel → phản hồi PSR-7.
     * Lỗi kết nối, 429, 5xx → {@see DocumentStorageUnavailable}; 4xx khác (`invalid_grant`, …) →
     * {@see DocumentStorageMisconfigured}. Log chỉ có mã trạng thái và mã lỗi OAuth.
     *
     * @return Closure(RequestInterface, array<string, mixed>): ResponseInterface
     */
    private function httpHandler(): Closure
    {
        return function (RequestInterface $request, array $options = []): ResponseInterface {
            $headers = $request->withoutHeader('Host')->withoutHeader('Content-Length')->getHeaders();

            try {
                $response = Http::connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->withHeaders($headers)
                    ->withBody((string) $request->getBody(), $request->getHeaderLine('Content-Type'))
                    ->send($request->getMethod(), (string) $request->getUri());
            } catch (ConnectionException) {
                Log::warning(__('storage.drive.log.token_failed'), ['endpoint' => 'oauth2/token', 'status' => 0, 'reason' => DriveApiError::CONNECTION]);

                throw DocumentStorageUnavailable::temporarily();
            }

            if ($response->successful()) {
                return $response->toPsrResponse();
            }

            $error = $response->json('error');
            $error = is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'unknown';

            Log::warning(__('storage.drive.log.token_failed'), ['endpoint' => 'oauth2/token', 'status' => $response->status(), 'reason' => $error]);

            if ($response->status() === 429 || $response->serverError()) {
                throw DocumentStorageUnavailable::temporarily();
            }

            throw DocumentStorageMisconfigured::tokenRejected($error);
        };
    }
}
