<?php

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use App\Support\Storage\GoogleDrive\ServiceAccountTokenProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — access token của tài khoản dịch vụ (kế hoạch M14, R1, R6)
|--------------------------------------------------------------------------
|
| `google/auth` ký JWT RS256 và đổi lấy access token; HTTP của nó đi qua `Http` của Laravel, nên
| `Http::fake()` phủ cả endpoint token. Khoá RSA sinh lúc chạy (`openssl_pkey_new`): không khoá
| thật nào trong repo (phán quyết C2). Token cache 50 phút trong store `token_cache_store` (`file`
| ở production, `array` trong test), không bao giờ trong store `database` của bản sao lưu.
*/

beforeEach(function () {
    $this->drive = FakeGoogleDrive::install();
});

function serviceAccountTokens(?string $path = null): ServiceAccountTokenProvider
{
    return new ServiceAccountTokenProvider(
        $path ?? FakeGoogleDrive::serviceAccountKeyFile(),
        Cache::store('array'),
    );
}

it('cài giao diện DriveTokenProvider', function () {
    expect(serviceAccountTokens())->toBeInstanceOf(DriveTokenProvider::class);
});

it('đổi JWT lấy access token ở endpoint token của Google', function () {
    expect(serviceAccountTokens()->token())->toBe('ya29.issued-token-1');

    Http::assertSent(fn (Request $request) => $request->url() === FakeGoogleDrive::TOKEN_URL
        && $request->method() === 'POST'
        && $request->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
});

it('JWT có iss là client_email, scope drive, aud là endpoint token, exp − iat ≤ 3600', function () {
    serviceAccountTokens()->token();

    [$header, $claims] = array_map(
        fn (string $part) => json_decode(JWT::urlsafeB64Decode($part), true),
        array_slice(explode('.', $this->drive->assertions[0]), 0, 2),
    );

    expect($header['alg'])->toBe('RS256')
        ->and($claims['iss'])->toBe(FakeGoogleDrive::SERVICE_ACCOUNT)
        ->and($claims['scope'])->toBe('https://www.googleapis.com/auth/drive')
        ->and($claims['aud'])->toBe(FakeGoogleDrive::TOKEN_URL)
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(3600)
        ->and($claims['exp'] - $claims['iat'])->toBeGreaterThan(3000)
        ->and($claims)->not->toHaveKey('sub');
});

it('chữ ký JWT kiểm được bằng khoá công khai sinh lúc chạy', function () {
    serviceAccountTokens()->token();

    $decoded = JWT::decode($this->drive->assertions[0], new Key(FakeGoogleDrive::keyPair()['public'], 'RS256'));

    expect($decoded->iss)->toBe(FakeGoogleDrive::SERVICE_ACCOUNT);

    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    expect(fn () => JWT::decode($this->drive->assertions[0], new Key(openssl_pkey_get_details($other)['key'], 'RS256')))
        ->toThrow(SignatureInvalidException::class);
});

it('token được cache: lần hỏi thứ hai không gọi endpoint token', function () {
    $provider = serviceAccountTokens();

    expect($provider->token())->toBe('ya29.issued-token-1')
        ->and($provider->token())->toBe('ya29.issued-token-1')
        ->and(serviceAccountTokens()->token())->toBe('ya29.issued-token-1');

    Http::assertSentCount(1);
});

it('token cache 50 phút: sau 50 phút thì xin token mới', function () {
    $provider = serviceAccountTokens();
    $provider->token();

    $this->travel(49)->minutes();
    expect($provider->token())->toBe('ya29.issued-token-1');

    $this->travel(2)->minutes();
    expect($provider->token())->toBe('ya29.issued-token-2');
});

it('forget() bỏ token đã cache', function () {
    $provider = serviceAccountTokens();
    $provider->token();
    $provider->forget();

    expect($provider->token())->toBe('ya29.issued-token-2');
    Http::assertSentCount(2);
});

it('cache theo đường dẫn khoá: hai khoá khác nhau là hai token', function () {
    serviceAccountTokens()->token();

    expect(serviceAccountTokens(FakeGoogleDrive::serviceAccountKeyFile(['client_email' => 'khac@vk-crm-test.iam.gserviceaccount.com']))->token())
        ->toBe('ya29.issued-token-2');
});

it('không có đường dẫn khoá → DocumentStorageMisconfigured, không request nào', function (?string $path) {
    expect(fn () => (new ServiceAccountTokenProvider($path, Cache::store('array')))->token())
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.credentials_missing'));

    Http::assertNothingSent();
})->with([
    'null' => [null],
    'rỗng' => [''],
]);

it('tệp khoá không có, không phải JSON, hay thiếu trường → DocumentStorageMisconfigured, không request nào', function (Closure $path) {
    expect(fn () => serviceAccountTokens($path())->token())
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.credentials_unusable'));

    Http::assertNothingSent();
})->with([
    'không tồn tại' => [fn () => sys_get_temp_dir().'/vkcrm-khong-co-khoa-'.getmypid().'.json'],
    'không phải JSON' => [function () {
        $path = sys_get_temp_dir().'/vkcrm-khoa-hong-'.getmypid().'.json';
        file_put_contents($path, 'khong phai json');

        return $path;
    }],
    'không phải tài khoản dịch vụ' => [fn () => FakeGoogleDrive::serviceAccountKeyFile(['type' => 'authorized_user'])],
    'thiếu client_email' => [fn () => FakeGoogleDrive::serviceAccountKeyFile(['client_email' => null])],
    'client_email rỗng' => [fn () => FakeGoogleDrive::serviceAccountKeyFile(['client_email' => ''])],
    'thiếu private_key' => [fn () => FakeGoogleDrive::serviceAccountKeyFile(['private_key' => ''])],
    'không có trường private_key' => [function () {
        $path = sys_get_temp_dir().'/vkcrm-khoa-thieu-truong-'.getmypid().'.json';
        file_put_contents($path, json_encode(['type' => 'service_account', 'client_email' => FakeGoogleDrive::SERVICE_ACCOUNT]));

        return $path;
    }],
    'JSON là một chuỗi' => [function () {
        $path = sys_get_temp_dir().'/vkcrm-khoa-chuoi-'.getmypid().'.json';
        file_put_contents($path, '"service_account"');

        return $path;
    }],
    'private_key hỏng' => [fn () => FakeGoogleDrive::serviceAccountKeyFile(['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"])],
]);

it('endpoint token trả 400 invalid_grant → DocumentStorageMisconfigured nhắc đồng hồ máy chủ', function () {
    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400));

    expect(fn () => serviceAccountTokens()->token())
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.token_rejected', ['error' => 'invalid_grant']));
});

it('endpoint token lỗi tạm thời (5xx, 429, lỗi kết nối) → DocumentStorageUnavailable', function (Closure $response) {
    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, $response());

    expect(fn () => serviceAccountTokens()->token())->toThrow(DocumentStorageUnavailable::class);
})->with([
    '503' => [fn () => Http::response(['error' => 'backend'], 503)],
    '500' => [fn () => Http::response('', 500)],
    '429' => [fn () => Http::response(['error' => 'rate_limit'], 429)],
    'lỗi kết nối' => [fn () => Http::failedConnection()],
]);

it('mã lỗi OAuth lạ (không phải chữ thường và _) không được chép vào câu lỗi hay log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, Http::response(['error' => 'ya29.leaked-secret'], 400));

    expect(fn () => serviceAccountTokens()->token())
        ->toThrow(DocumentStorageMisconfigured::class, __('storage.exceptions.token_rejected', ['error' => 'unknown']));

    expect(implode("\n", $logged))->not->toContain('ya29.leaked-secret');
});

it('endpoint token trả 200 mà không có access_token → DocumentStorageMisconfigured', function () {
    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, Http::response(['token_type' => 'Bearer']));

    expect(fn () => serviceAccountTokens()->token())->toThrow(DocumentStorageMisconfigured::class);
});

it('log và ngoại lệ của lỗi token không chứa token, khoá hay thân phản hồi', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $this->drive->respondNext('POST', FakeGoogleDrive::TOKEN_URL, Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'access_token ya29.leaked-secret -----BEGIN PRIVATE KEY-----',
    ], 400));

    try {
        serviceAccountTokens()->token();
        $this->fail('Phải ném DocumentStorageMisconfigured.');
    } catch (DocumentStorageMisconfigured $e) {
        $chain = [];
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $chain[] = $error->getMessage();
        }
    }

    $everything = implode("\n", [...$logged, ...$chain]);

    expect($logged)->not->toBeEmpty()
        ->and($everything)->not->toContain('ya29.leaked-secret')
        ->and($everything)->not->toContain('-----BEGIN')
        ->and($everything)->not->toContain('access_token')
        ->and($everything)->not->toContain('private_key')
        ->and($everything)->not->toContain('Bearer')
        ->and($everything)->not->toContain(FakeGoogleDrive::keyPair()['private']);
});
