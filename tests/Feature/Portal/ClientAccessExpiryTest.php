<?php

use App\Actions\TransitionMatterStage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Filament\Portal\Pages\MyRequests;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * M7 Task 5 (R4, SPEC §11 "Bàn giao và lưu trữ"): quá `client_access_until` thì vụ việc rời cổng
 * khách hàng ở CẢ HAI tầng — tầng truy vấn (`Matter::applyClientPortalConstraints()`) và tầng
 * policy (`MatterPolicy::releasedToPortal()`, điều kiện thứ năm) — nhưng còn nguyên trong admin.
 *
 * Định nghĩa hết hạn (phán quyết của controller): vụ có một dòng `matter_archives` chưa xoá mềm
 * với `client_access_until` khác null VÀ `client_access_until < hôm nay` theo múi giờ ứng dụng —
 * khách còn xem được HẾT ngày `client_access_until`.
 *
 * Mọi khẳng định về màn hình đi qua HTTP hoặc Livewire. Mỗi tầng có một test riêng làm thủng tầng
 * KIA rồi đo tầng này — nghi thức ba tầng của `PortalIsolationSweepTest`.
 *
 * Mốc thời gian: khách xem được hết ngày 20/10/2026; 00:00 ngày 21/10/2026 là phút đầu tiên vụ
 * việc không còn trên cổng.
 */
const CAE_MARKER = 'VU-DA-HET-HAN-TRA-CUU-5R2W';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('portal');

    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));

    $this->client = Client::factory()->create(['name' => 'Khách hàng có vụ đã kết thúc']);
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    // Vụ đã kết thúc từ 22/07/2026, khách còn tra cứu được tới hết ngày 20/10/2026.
    $this->closed = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Tranh chấp hợp đồng '.CAE_MARKER,
        'closed_at' => '2026-07-22',
        'lead_lawyer_id' => $this->lead->id,
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->closed->id,
        'client_access_until' => '2026-10-20',
    ]);

    // Vụ thứ hai của CÙNG khách, đang mở và trên cổng: vế dương của mọi khẳng định âm bên dưới —
    // một test nói "vụ kia biến mất" xanh y hệt khi cả trang trắng.
    $this->open = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Thủ tục cấp sổ đỏ còn đang làm',
    ]);
});

function caeLastMinute(): void
{
    test()->travelTo(Carbon::parse('2026-10-20 23:59:59'));
}

function caeAfterExpiry(): void
{
    test()->travelTo(Carbon::parse('2026-10-21 00:00:00'));
}

function caeProgressUrl(Matter $matter): string
{
    return MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal');
}

/** Danh sách hồ sơ, kèm `showAll` để một hồ sơ duy nhất không chuyển hướng sang trang chi tiết. */
function caeMyMattersHtml(): string
{
    return test()->actingAs(test()->clientUser, 'client')
        ->livewire(MyMatters::class, ['showAll' => true])
        ->html();
}

function caePublishedDocument(Matter $matter, string $title): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'title' => $title,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $document->addMedia(UploadedFile::fake()->create('ban-an.pdf', 10, 'application/pdf'))
        ->toMediaCollection('file');

    return $document->fresh();
}

// =========================================================================================
// Ngày biên
// =========================================================================================

it('vẫn cho khách xem vụ việc tới hết ngày client_access_until, và rút nó khỏi cổng từ 00:00 ngày hôm sau', function () {
    caeLastMinute();

    expect(caeMyMattersHtml())->toContain($this->closed->code)
        ->toContain($this->open->code);
    $this->get(caeProgressUrl($this->closed))->assertOk()->assertSee(CAE_MARKER);

    caeAfterExpiry();

    $html = caeMyMattersHtml();

    expect($html)->not->toContain($this->closed->code)
        ->and($html)->not->toContain(CAE_MARKER)
        ->and($html)->toContain($this->open->code);

    $this->get(caeProgressUrl($this->closed))->assertNotFound();
    $this->get(caeProgressUrl($this->open))->assertOk();
});

it('vụ chưa từng đóng (không có dòng lưu trữ) và vụ đã mở lại (client_access_until null) không bao giờ hết hạn', function () {
    $this->archive->update(['client_access_until' => null]);

    $this->travelTo(Carbon::parse('2030-01-01 09:00:00'));

    $html = caeMyMattersHtml();

    expect($html)->toContain($this->closed->code)
        ->and($html)->toContain($this->open->code);

    $this->get(caeProgressUrl($this->closed))->assertOk();
    $this->get(caeProgressUrl($this->open))->assertOk();
});

// =========================================================================================
// Mọi màn hình theo vụ của cổng, và mọi bản ghi con
// =========================================================================================

it('sau ngày hết hạn, mọi trang theo vụ của cổng trả 404 cho vụ đó và vẫn mở cho vụ còn lại', function () {
    ClientRequest::factory()->create(['matter_id' => $this->closed->id, 'client_user_id' => $this->clientUser->id]);
    ClientRequest::factory()->create(['matter_id' => $this->open->id, 'client_user_id' => $this->clientUser->id]);

    $this->actingAs($this->clientUser, 'client');

    // Vế dương TRƯỚC giờ hết hạn: cùng URL, cùng khách, trang mở được.
    $this->get(MyRequests::getUrl(['record' => $this->closed->id], panel: 'portal'))->assertOk();

    caeAfterExpiry();

    $this->get(caeProgressUrl($this->closed))->assertNotFound();
    $this->get(MyRequests::getUrl(['record' => $this->closed->id], panel: 'portal'))->assertNotFound();
    $this->get(SubmitDocument::getUrl(['record' => $this->closed->id], panel: 'portal'))->assertNotFound();

    $this->get(caeProgressUrl($this->open))->assertOk();
    $this->get(MyRequests::getUrl(['record' => $this->open->id], panel: 'portal'))->assertOk();
});

it('sau ngày hết hạn, không bản ghi con nào của vụ đó còn đọc được dưới phiên khách, ở cả hai tầng', function () {
    $children = fn (Matter $matter): array => [
        StageLog::factory()->for($matter)->published()->create(),
        caePublishedDocument($matter, 'Bản án '.$matter->id),
        Deadline::factory()->for($matter)->published()->create(),
        MatterChecklistItem::factory()->for($matter)->create(),
        ClientRequest::factory()->create(['matter_id' => $matter->id, 'client_user_id' => $this->clientUser->id]),
    ];

    $expired = $children($this->closed);
    $live = $children($this->open);

    caeAfterExpiry();
    $this->actingAs($this->clientUser, 'client');

    foreach ($expired as $record) {
        expect($record::query()->whereKey($record->getKey())->exists())
            ->toBeFalse($record::class.' của vụ đã hết hạn vẫn còn trong truy vấn của cổng')
            ->and(Gate::forUser($this->clientUser)->allows('view', $record->fresh()))
            ->toBeFalse($record::class.' của vụ đã hết hạn vẫn qua được policy');
    }

    foreach ($live as $record) {
        expect($record::query()->whereKey($record->getKey())->exists())
            ->toBeTrue($record::class.' của vụ còn hạn biến mất khỏi truy vấn của cổng')
            ->and(Gate::forUser($this->clientUser)->allows('view', $record->fresh()))
            ->toBeTrue($record::class.' của vụ còn hạn bị policy từ chối');
    }
});

// =========================================================================================
// Tải có chữ ký
// =========================================================================================

/**
 * Đường tải ký sống 5 phút (`Document::DOWNLOAD_LINK_MINUTES`) và quyết định ở thời điểm TẢI, không
 * ở thời điểm dựng đường dẫn. Một URL phát lúc 23:58 ngày cuối còn chữ ký hợp lệ tới 00:03 — nên
 * lần tải lúc 00:01 chỉ bị chặn nếu policy hỏi lại ngày hết hạn. Khẳng định chữ ký VẪN hợp lệ ở
 * cuối để chắc 404 đến từ quyền, không từ URL đã hết hạn.
 */
it('một URL tải có chữ ký phát ra trước giờ hết hạn trả 404 sau giờ đó, và tệp vẫn còn nguyên cho nhân sự', function () {
    $document = caePublishedDocument($this->closed, 'Bản án phúc thẩm');

    $this->travelTo(Carbon::parse('2026-10-20 23:58:00'));
    $this->actingAs($this->clientUser, 'client');

    $url = $document->downloadUrlFor($this->clientUser);

    $this->travelTo(Carbon::parse('2026-10-20 23:59:00'));
    $this->get($url)->assertOk();

    $this->travelTo(Carbon::parse('2026-10-21 00:01:00'));
    $this->get($url)->assertNotFound();

    expect(URL::hasValidSignature(Request::create($url)))->toBeTrue()
        ->and($document->fresh()->getFirstMedia('file'))->not->toBeNull();

    $this->actingAs($this->lead, 'web')->get($document->fresh()->downloadUrlFor($this->lead))->assertOk();
});

// =========================================================================================
// Admin: dữ liệu còn nguyên
// =========================================================================================

it('sau ngày hết hạn, vụ việc còn nguyên trong admin: nhân sự mở được trang vụ và không một cột nào đổi', function () {
    $document = caePublishedDocument($this->closed, 'Quyết định công nhận thoả thuận');

    $snapshot = fn (): array => [
        'matter' => (array) DB::table('matters')->where('id', $this->closed->id)->first(),
        'archive' => (array) DB::table('matter_archives')->where('id', $this->archive->id)->first(),
        'document' => (array) DB::table('documents')->where('id', $document->id)->first(),
    ];

    $before = $snapshot();

    caeAfterExpiry();

    // Khách đã mất quyền xem — tiền đề, nếu không thì phần dưới không đo gì về "hết hạn".
    $this->actingAs($this->clientUser, 'client')->get(caeProgressUrl($this->closed))->assertNotFound();

    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web')
        ->get(ViewMatter::getUrl(['record' => $this->closed], panel: 'admin'))
        ->assertOk()
        ->assertSee($this->closed->code)
        ->assertSee(CAE_MARKER);

    expect($snapshot())->toEqual($before)
        ->and($before['matter']['is_published_to_portal'])->toBeTruthy();
});

// =========================================================================================
// Mở lại vụ việc
// =========================================================================================

it('mở lại vụ việc (client_access_until về null qua TransitionMatterStage thật) đưa vụ về lại cổng', function () {
    $type = MatterType::factory()->create();
    $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận', 'client_label' => 'Tiếp nhận',
        'client_description' => 'Đã tiếp nhận', 'sort_order' => 1,
        'allowed_next' => ['closed'], 'default_next_update_days' => 14,
    ]);
    $type->stages()->create([
        'key' => 'closed', 'label' => 'Kết thúc', 'client_label' => 'Đã kết thúc',
        'client_description' => 'Đã kết thúc', 'sort_order' => 2, 'is_terminal' => true,
        'allowed_next' => [], 'default_next_update_days' => 30,
    ]);
    $type->unsetRelation('stages');

    $matter = Matter::factory()->for($this->client)->for($type, 'matterType')->create([
        'is_published_to_portal' => true,
        'title' => 'Vụ sẽ được mở lại',
    ]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $transition = fn (string $to) => app(TransitionMatterStage::class)->handle(
        matter: $matter->fresh(), actor: $admin, toStage: $to, occurredAt: now(),
        internalNote: 'Đổi giai đoạn', publicContent: null, nextStep: null, clientAction: null,
        expectedNextUpdateAt: null, publish: false,
    );

    $transition('closed');

    $accessDays = (int) config('vkcrm.client_access_days');
    $this->travel($accessDays + 1)->days();

    $this->actingAs($this->clientUser, 'client')->get(caeProgressUrl($matter))->assertNotFound();

    $this->actingAs($admin, 'web');
    $transition('intake');

    expect(MatterArchive::query()->where('matter_id', $matter->id)->value('client_access_until'))->toBeNull();

    $this->actingAs($this->clientUser, 'client')->get(caeProgressUrl($matter))->assertOk();
    expect(caeMyMattersHtml())->toContain($matter->code);
});

// =========================================================================================
// Hai tầng độc lập (nghi thức ba tầng của PortalIsolationSweepTest)
// =========================================================================================

/**
 * Tầng TRUY VẤN một mình: policy được ép cho qua mọi lần hỏi `view` của khách (`Gate::before`),
 * nên điều duy nhất còn giữ vụ đã hết hạn ngoài màn hình là `Matter::applyClientPortalConstraints()`.
 *
 * Đồng thời là bằng chứng cho cái bẫy controller nêu: `MatterArchive` mang `ClientPortalScope` chặn
 * sạch (`1 = 0`), nên một `whereDoesntHave('archive', …)` chạy dưới phiên khách mà KHÔNG gỡ scope
 * đó trong truy vấn con sẽ luôn đúng — điều kiện thành vô hiệu đúng lúc nó cần. Test này chạy
 * dưới phiên khách thật (`ClientPortalScope::isActive()` được khẳng định).
 */
it('tầng truy vấn một mình vẫn giấu vụ đã hết hạn khi policy cho qua mọi lần hỏi view, dưới phiên khách đang mở', function () {
    caeAfterExpiry();

    Gate::before(fn ($user, string $ability) => $user instanceof ClientUser && $ability === 'view' ? true : null);

    $this->actingAs($this->clientUser, 'client');

    expect(ClientPortalScope::isActive())->toBeTrue()
        // Policy đã thủng — nếu không thì các khẳng định dưới không đo tầng truy vấn.
        ->and(Gate::forUser($this->clientUser)->allows('view', $this->closed))->toBeTrue()
        ->and(Matter::query()->pluck('id')->all())->toBe([$this->open->id]);

    $html = caeMyMattersHtml();

    expect($html)->not->toContain($this->closed->code)
        ->and($html)->toContain($this->open->code);

    $this->get(caeProgressUrl($this->closed))->assertNotFound();
    $this->get(caeProgressUrl($this->open))->assertOk();
});

/**
 * Tầng POLICY một mình, đường truy vấn dự phòng (`clientAccessArchive` CHƯA nạp): scope portal của
 * `Matter` bị thay bằng một scope rỗng, nên `MatterProgress::resolveMatter()` tìm thấy vụ và
 * `visibleToPortal()` cũng cho qua — chỉ điều kiện thứ năm của `releasedToPortal()` còn từ chối.
 */
it('tầng policy một mình vẫn từ chối vụ đã hết hạn khi scope portal của Matter quên luật — đường dự phòng', function () {
    caeAfterExpiry();

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        $this->actingAs($this->clientUser, 'client');

        $loaded = Matter::query()->find($this->closed->id);

        // Tầng truy vấn đã thủng.
        expect($loaded)->not->toBeNull()
            ->and($loaded->relationLoaded('clientAccessArchive'))->toBeFalse()
            ->and(Gate::forUser($this->clientUser)->allows('view', $loaded))->toBeFalse()
            ->and(Gate::forUser($this->clientUser)->allows('view', Matter::query()->find($this->open->id)))->toBeTrue();

        $this->get(caeProgressUrl($this->closed))->assertNotFound();
        $this->get(caeProgressUrl($this->open))->assertOk();
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

/**
 * Tầng POLICY một mình, đường TRONG BỘ NHỚ: `MyMatters::buildCards()` nạp sẵn `clientAccessArchive`
 * DƯỚI PHIÊN KHÁCH. Scope portal của `Matter` bị làm rỗng (nên danh sách lấy mọi vụ trong CSDL và
 * chỉ `Gate` lọc), còn scope của `MatterArchive` thì KHÔNG — nên nếu quan hệ nạp sẵn quên gỡ scope
 * `1 = 0` của chính nó, dòng lưu trữ về `null`, đường trong bộ nhớ đọc "không hết hạn" và vụ hiện
 * lại trên danh sách.
 */
it('tầng policy một mình vẫn từ chối ở đường trong bộ nhớ của MyMatters, nơi dòng lưu trữ được nạp sẵn dưới phiên khách', function () {
    caeAfterExpiry();

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        $html = caeMyMattersHtml();

        expect($html)->not->toContain($this->closed->code)
            ->and($html)->not->toContain(CAE_MARKER)
            ->and($html)->toContain($this->open->code);
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

/**
 * Hai tầng nói cùng MỘT định nghĩa: một dòng lưu trữ đã xoá mềm không tính (phán quyết "có bản ghi
 * archive (chưa xoá mềm)"). Không có luồng sản phẩm nào xoá mềm `MatterArchive` — test này ghim
 * rằng hai tầng ĐỒNG Ý với nhau, để một ngày một tầng đổi sang `withTrashed()` mà tầng kia không đổi
 * thì có một test đỏ.
 */
it('hai tầng đồng ý về dòng lưu trữ đã xoá mềm: không tính là hết hạn', function () {
    $this->archive->delete();

    caeAfterExpiry();
    $this->actingAs($this->clientUser, 'client');

    $fresh = Matter::query()->find($this->closed->id);

    expect($fresh)->not->toBeNull()
        ->and(Gate::forUser($this->clientUser)->allows('view', $fresh))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('view', Matter::query()->with('clientAccessArchive')->find($this->closed->id)))->toBeTrue();
});
