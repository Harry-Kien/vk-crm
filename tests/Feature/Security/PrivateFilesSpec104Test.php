<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| SPEC §10.4 — không route nào khác phát tệp hồ sơ
|--------------------------------------------------------------------------
|
| Kế hoạch M8 Task 4: "không có symlink/route nào khác phát tệp đó". Phần symlink/`storage:link`
| ở `tests/Feature/Storage/PrivateDiskTest.php`; phần route ở đây, đo bằng HÀNH VI chứ không bằng
| danh sách tay: mọi route GET của router, mọi cách một tham số route có thể gọi tên tệp đó (id tài
| liệu, id/uuid media, tên tệp trên đĩa, đường dẫn tương đối), dưới tài khoản mạnh nhất của đúng
| panel — không phản hồi nào được mang nội dung tệp. Đường hợp lệ duy nhất là `documents.download`
| có chữ ký đúng người nhận (các test `§10.4` của `DocumentDownloadTest`).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

/** Nội dung thật một phản hồi mang về, kể cả phản hồi dạng luồng hay tệp nhị phân. */
function privateFilesResponseBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    if ($base instanceof BinaryFileResponse) {
        return (string) file_get_contents($base->getFile()->getPathname());
    }

    if ($base instanceof StreamedResponse) {
        return (string) $response->streamedContent();
    }

    return (string) $base->getContent();
}

/**
 * Tài khoản mạnh nhất để thử một route: route của panel admin dưới quản trị viên (đã cài 2FA), route
 * của cổng dưới khách của chính hồ sơ, route ngoài panel dưới cả hai.
 *
 * @param  array{web: User, client: ClientUser}  $actors
 * @return list<array{0: User|ClientUser, 1: string}>
 */
function privateFilesActorsFor(RoutingRoute $route, array $actors): array
{
    $name = (string) $route->getName();

    return match (true) {
        str_starts_with($name, 'filament.admin.') => [[$actors['web'], 'web']],
        str_starts_with($name, 'filament.portal.') => [[$actors['client'], 'client']],
        default => [[$actors['web'], 'web'], [$actors['client'], 'client']],
    };
}

/**
 * Route được thử: MỌI route GET có tham số (chỗ duy nhất một URL gọi tên được một tệp cụ thể), và
 * MỌI route GET ngoài hai panel (các cửa tệp của framework: Livewire, xuất/nhập của Filament,
 * `documents.download`). Trang panel không tham số (danh sách, bảng điều khiển, form tạo) không có
 * cách nào nêu một tệp — bỏ qua cho test đủ nhanh để chạy mỗi lần.
 *
 * Lỗi được vẽ như máy chủ thật (`app.debug = false`) và log đi vào kênh `null`: đo được, trang lỗi
 * debug cộng dấu vết ngăn xếp ghi xuống `storage/logs` qua ổ 9p làm mỗi phản hồi 500 (route xuất/
 * nhập của Filament khi không có bảng `exports`/`imports`, route tài nguyên Livewire với tên bịa)
 * tốn 3–25 giây, cả test hơn 200 giây. Không phản hồi 500 nào mang nội dung tệp dù vẽ kiểu nào.
 */
it('§10.4 không route GET nào ngoài documents.download (chữ ký hợp lệ, đúng người nhận) trả về nội dung tệp hồ sơ, với bất kỳ tham số nào', function () {
    config(['app.debug' => false, 'logging.default' => 'null']);

    $marker = '%PDF-1.4 dau-vet-muc-10-4-'.Str::random(32);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $admin->id, 'is_published_to_portal' => true]);

    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', $marker))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');
    $media = $document->refresh()->getFirstMedia('file');

    // Đối chứng: phép đọc nội dung thấy được tệp qua đúng đường hợp lệ (phản hồi dạng luồng).
    expect(privateFilesResponseBody($this->actingAs($admin, 'web')->get($document->downloadUrlFor($admin))))
        ->toContain($marker);

    $candidates = array_values(array_unique([
        (string) $document->getKey(),
        (string) $media->getKey(),
        (string) $media->uuid,
        (string) $media->file_name,
        $media->getPathRelativeToRoot(),
        'app/private/'.$media->getPathRelativeToRoot(),
    ]));

    $actors = ['web' => $admin, 'client' => $clientUser];
    $served = [];
    $requests = 0;

    foreach (Route::getRoutes() as $route) {
        $hasParameters = str_contains($route->uri(), '{');
        $insidePanel = str_starts_with((string) $route->getName(), 'filament.admin.')
            || str_starts_with((string) $route->getName(), 'filament.portal.');

        if (! in_array('GET', $route->methods(), true) || ($insidePanel && ! $hasParameters)) {
            continue;
        }

        $uris = $hasParameters
            ? array_map(fn (string $value): string => (string) preg_replace('/\{[^}]+\}/', $value, $route->uri()), $candidates)
            : [$route->uri()];

        foreach ($uris as $uri) {
            foreach (privateFilesActorsFor($route, $actors) as [$actor, $guard]) {
                $requests++;

                $body = privateFilesResponseBody($this->actingAs($actor, $guard)->get('/'.ltrim($uri, '/')));

                if (str_contains($body, $marker)) {
                    $served[] = "{$guard} GET /{$uri}";
                }
            }
        }
    }

    expect($requests)->toBeGreaterThan(50)
        ->and($served)->toBe([]);
});
