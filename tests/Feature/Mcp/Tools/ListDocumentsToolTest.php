<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DocumentPresenter;
use App\Support\Mcp\Presenters\MatterPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — tool `list_documents` (bảng tool 9 [DC:55])
|--------------------------------------------------------------------------
| Metadata tài liệu nhóm A/B/C của MỘT vụ trong tập `McpMatterScope`, mới nhất trước (như tab "Tài
| liệu"): tiêu đề, nhóm, trạng thái, version, ngày, khách xem hay tải được không. Nhóm D vắng mặt BẤT
| KỂ `document.viewInternal` — không liệt kê, không đếm, không làm lệch "còn trang sau" (R4). Không
| đường tải, không URL ký; `url` là tab Tài liệu. Tiêu đề nhóm A (khách đặt) chỉ trong
| `untrusted_client_content` (R11). `DocumentPolicy::view` từng dòng.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{matter: array<string, mixed>, documents: list<array<string, mixed>>, next_cursor: ?string} */
function documentsOf(string $token, Matter $matter, array $arguments = []): array
{
    return McpToolCall::structured(test(), $token, 'list_documents', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), ...$arguments]);
}

/** @return list<string> */
function documentIds(array $out): array
{
    return array_column($out['documents'], 'id');
}

function docId(Document $document): string
{
    return McpIds::encode(McpIds::DOCUMENT, $document->id);
}

function docIn(Matter $matter, DocumentGroup $group, array $extra = []): Document
{
    return Document::factory()->group($group)->create(['matter_id' => $matter->id, ...$extra]);
}

/**
 * Mọi chuỗi nằm dưới một khoá tên `url`, ở mọi độ sâu.
 *
 * @return list<string>
 */
function documentUrlValues(array $value): array
{
    $urls = [];

    foreach ($value as $key => $child) {
        if ($key === 'url' && is_string($child)) {
            $urls[] = $child;
        } elseif (is_array($child)) {
            $urls = [...$urls, ...documentUrlValues($child)];
        }
    }

    return $urls;
}

it('trả metadata tài liệu A/B/C, mới nhất trước, đủ trường của DocumentPresenter; tiêu đề nhóm A chỉ trong untrusted_client_content (đã sạch), tiêu đề B/C ra thẳng; url về tab Tài liệu', function () {
    $matter = $this->world->matter;
    $issued = docIn($matter, DocumentGroup::Issued, [
        'title' => 'Đơn khởi kiện', 'status' => DocumentStatus::Published, 'version' => 2,
        'client_can_view' => true, 'client_can_download' => true, 'published_at' => now()->subDay(),
        'created_at' => now()->subDays(2),
    ]);
    $client = docIn($matter, DocumentGroup::ClientProvided, [
        'title' => "Ảnh CCCD \u{200B}<b>mặt trước</b> ![x](https://evil.example/p.png)",
        'status' => DocumentStatus::PendingApproval,
        'created_at' => now()->subHour(),
    ]);
    $authority = docIn($matter, DocumentGroup::Authority, ['title' => 'Thông báo thụ lý', 'created_at' => now()->subDays(5)]);

    $out = documentsOf($this->token, $matter);

    expect(array_keys($out))->toBe(['matter', 'documents', 'next_cursor'])
        ->and($out['matter'])->toBe(MatterPresenter::reference($matter))
        ->and(documentIds($out))->toBe([docId($client), docId($issued), docId($authority)])
        ->and($out['next_cursor'])->toBeNull();

    [$clientRow, $issuedRow] = $out['documents'];

    expect(array_keys($issuedRow))->toBe(DocumentPresenter::FIELDS)
        ->and($issuedRow['group'])->toBe('B')
        ->and($issuedRow['group_label'])->toBe(DocumentGroup::Issued->label())
        ->and($issuedRow['title'])->toBe('Đơn khởi kiện')
        ->and($issuedRow['untrusted_client_content'])->toBeNull()
        ->and($issuedRow['status'])->toBe(DocumentStatus::Published->value)
        ->and($issuedRow['version'])->toBe(2)
        ->and($issuedRow['client_can_view'])->toBeTrue()
        ->and($issuedRow['client_can_download'])->toBeTrue()
        ->and($issuedRow['url'])->toBe(AdminUrls::document($issued));

    expect($clientRow['title'])->toBeNull()
        ->and($clientRow['untrusted_client_content'])->toBe(['title' => ['text' => 'Ảnh CCCD mặt trước '.__('mcp.untrusted.image_removed'), 'truncated' => false]]);
});

it('R4: luật sư phụ trách và admin (có document.viewInternal) vẫn không thấy nhóm D — không dòng nào, không tiêu đề nào, và nhóm D không làm "còn trang sau"', function () {
    $matter = $this->world->matter;
    foreach (range(1, 4) as $i) {
        docIn($matter, DocumentGroup::Internal, ['title' => "SECRET-INTERNAL-MEMO-Quoll-{$i}", 'created_at' => now()->subMinutes($i)]);
    }
    $visible = collect(range(1, 3))->map(fn (int $i) => docIn($matter, DocumentGroup::Issued, ['created_at' => now()->subHours($i)]));

    foreach ([$this->world->lead, $this->world->admin] as $user) {
        expect($user->can('document.viewInternal'))->toBeTrue();

        $token = McpOAuth::accessToken($this, $user);
        $response = McpToolCall::call($this, $token, 'list_documents', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), 'limit' => 3]);
        $out = documentsOf($token, $matter, ['limit' => 3]);

        expect(documentIds($out))->toBe($visible->map(fn (Document $document) => docId($document))->all())
            ->and($out['next_cursor'])->toBeNull()
            ->and($response->getContent())->not->toContain('SECRET-INTERNAL-MEMO');
    }

    // Vụ chỉ có tài liệu nhóm D: cùng phản hồi với vụ không có tài liệu nào.
    $onlyInternal = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);
    docIn($onlyInternal, DocumentGroup::Internal);
    $empty = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);

    expect(documentsOf($this->token, $onlyInternal)['documents'])->toBe([])
        ->and(documentsOf($this->token, $onlyInternal)['next_cursor'])->toBe(null)
        ->and(documentsOf($this->token, $empty)['documents'])->toBe([]);

    // Lớp thứ ba, ở tầng giao thức: outputSchema chỉ cho nhóm A, B, C (mọi kết quả thành công được so với nó).
    expect(McpToolCall::outputSchema('list_documents')['properties']['documents']['items']['properties']['group']['enum'])
        ->toBe(['A', 'B', 'C']);
});

it('R4: không có khoá url nào trỏ tới documents.download, không URL ký nào trong phản hồi', function () {
    $matter = $this->world->matter;
    $document = docIn($matter, DocumentGroup::Issued, ['status' => DocumentStatus::Published, 'client_can_view' => true, 'client_can_download' => true]);
    docIn($matter, DocumentGroup::ClientProvided);

    // Cặp dương của phép quét: đường tải thật của tài liệu này có dạng ta đang tìm.
    $downloadUrl = $document->downloadUrlFor($this->world->lead);
    $downloadPath = (string) parse_url($downloadUrl, PHP_URL_PATH);
    expect($downloadPath)->toContain('/documents/'.$document->id.'/download')
        ->and($downloadUrl)->toContain('signature=');

    $response = McpToolCall::call($this, $this->token, 'list_documents', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]);
    $urls = documentUrlValues(documentsOf($this->token, $matter));

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        expect($url)->not->toContain('/download')
            ->and($url)->not->toContain('signature=')
            ->and($url)->toStartWith(AdminUrls::matter($matter));
    }

    expect($response->getContent())->not->toContain('/download')
        ->and($response->getContent())->not->toContain('signature');
});

it('R3: vụ đội khác, vụ hạn chế của chính mình, vụ denied và id không tồn tại cho CÙNG một phản hồi', function () {
    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'list_documents', ['matter_id' => $id])->json('result');

    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue()
        ->and($baseline)->not->toHaveKey('structuredContent');

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        docIn($matter, DocumentGroup::Issued);

        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    foreach (['matter_0', 'doc_1', (string) $this->world->matter->id] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    expect(documentsOf(McpOAuth::accessToken($this, $this->world->outsider), $this->world->otherTeam)['documents'])->toHaveCount(1);
});

it('trợ lý (không document.viewInternal) thấy cùng A/B/C; kế toán bị EnsureMcpAccess từ chối (401, không phản hồi tool); tài liệu đã xoá mềm không ra', function () {
    $matter = $this->world->matter;
    $kept = docIn($matter, DocumentGroup::Issued);
    docIn($matter, DocumentGroup::Issued)->delete();
    docIn($matter, DocumentGroup::Internal);

    expect(documentIds(documentsOf(McpOAuth::accessToken($this, $this->world->assistant), $matter)))->toBe([docId($kept)])
        ->and(documentIds(documentsOf($this->token, $matter)))->toBe([docId($kept)]);

    McpToolCall::refused($this, McpOAuth::accessToken($this, $this->world->accountant), 'list_documents', [
        'matter_id' => McpIds::encode(McpIds::MATTER, $matter->id),
    ]);
});

it('phân trang: limit mặc định 10; limit quá 25 bị kẹp về 25; cursor đi theo (ngày tạo, id) kể cả khi tài liệu cùng giờ đứng hai bên ranh giới; cursor của A không mở cho B', function () {
    $matter = $this->world->matter;
    $base = now()->subDay()->startOfMinute();
    foreach (range(0, 29) as $i) {
        docIn($matter, DocumentGroup::Issued, ['created_at' => $base->copy()->subMinutes(intdiv($i, 2))]);
    }

    $all = Document::query()->where('matter_id', $matter->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (Document $document) => docId($document))->all();

    $default = documentsOf($this->token, $matter);
    expect(documentIds($default))->toBe(array_slice($all, 0, 10))
        ->and($default['next_cursor'])->toBeString();

    $first = documentsOf($this->token, $matter, ['limit' => 100]);
    expect(documentIds($first))->toBe(array_slice($all, 0, 25));

    $second = documentsOf($this->token, $matter, ['limit' => 100, 'cursor' => $first['next_cursor']]);
    expect(documentIds($second))->toBe(array_slice($all, 25))
        ->and($second['next_cursor'])->toBeNull();

    expect(McpToolCall::error($this, McpOAuth::accessToken($this, $this->world->admin), 'list_documents', [
        'matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), 'limit' => 100, 'cursor' => $first['next_cursor'],
    ]))->toBe(__('mcp.tool_errors.invalid_cursor'));
});

it('kế thừa policy web: Gate view từ chối một tài liệu thì nó vắng mặt; Gate view từ chối vụ thì "Không tìm thấy"; cặp dương trước', function () {
    $matter = $this->world->matter;
    $hidden = docIn($matter, DocumentGroup::Issued, ['created_at' => now()->subHour()]);
    $shown = docIn($matter, DocumentGroup::Issued, ['created_at' => now()->subHours(2)]);

    expect(documentIds(documentsOf($this->token, $matter)))->toBe([docId($hidden), docId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Document
        && $arguments[0]->is($hidden) ? false : null);

    expect(documentIds(documentsOf($this->token, $matter)))->toBe([docId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'list_documents', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt tài liệu chưa công bố', function () {
    $draft = docIn($this->world->matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    expect(documentIds(documentsOf($this->token, $this->world->matter)))->toBe([docId($draft)]);
});

it('matter_id là tham số bắt buộc; tham số sai kiểu bị từ chối bằng thông điệp kiểm tra', function () {
    expect(McpToolCall::error($this, $this->token, 'list_documents'))->not->toBe(__('mcp.tool_errors.not_found'))
        ->and(McpToolCall::error($this, $this->token, 'list_documents', ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id), 'limit' => 'nhieu']))
        ->not->toBe(__('mcp.tool_errors.not_found'));
});
