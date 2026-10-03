<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\UserPosition;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\DemoPdf;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    // `MatterSeeder` nay nộp tệp thật qua `UploadStaffDocument`/`SubmitClientDocument`,
    // nên nó GHI RA ĐĨA. Không có dòng này, mỗi lần chạy bộ test lại bỏ vài chục tệp PDF
    // vào `storage/app/private` thật của máy dev.
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);
});

it('seeds the staff roster', function () {
    expect(User::count())->toBe(8)
        ->and(User::where('position', UserPosition::Admin)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Manager)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Lawyer)->count())->toBe(3)
        ->and(User::where('position', UserPosition::Assistant)->count())->toBe(2)
        ->and(User::where('position', UserPosition::Accountant)->count())->toBe(1)
        ->and(User::where('email', 'admin@luatvukhang.com')->exists())->toBeTrue();
});

it('seeds six matter types each with a stage set', function () {
    expect(MatterType::count())->toBe(6)
        ->and(MatterType::pluck('code')->sort()->values()->all())->toBe(['DD', 'DN', 'DS', 'HN', 'HS', 'LD'])
        ->and(MatterType::all()->every(fn ($t) => $t->stages()->count() >= 5))->toBeTrue()
        ->and(MatterType::where('code', 'DS')->first()->stages()->count())->toBe(11);
});

it('seeds the land dispute checklist with twelve items and two more templates', function () {
    $land = ChecklistTemplate::whereHas('matterType', fn ($q) => $q->where('code', 'DD'))->firstOrFail();

    expect(ChecklistTemplate::count())->toBe(3)
        ->and($land->items)->toHaveCount(12)
        ->and($land->items->where('is_required', true))->toHaveCount(4)
        ->and($land->items->first()->name)->toContain('Giấy tờ tuỳ thân');
});

it('seeds twelve clients each with one or two portal accounts', function () {
    expect(Client::count())->toBe(12)
        ->and(ClientUser::count())->toBe(16)
        ->and(Client::doesntHave('clientUsers')->count())->toBe(0)
        ->and(Client::withCount('clientUsers')->get()->max('client_users_count'))->toBe(2)
        ->and(ClientUser::where('email', 'khach1@example.com')->exists())->toBeTrue();
});

/**
 * **Khách demo được ghi trong tài liệu phải đi hết được luồng giấy tờ.**
 *
 * Trước vòng hợp nhất M5, mọi đầu mục trên cả hai hồ sơ của `khach1@example.com` đều ở
 * `accepted` hoặc `not_applicable`, nên màn hình nộp giấy tờ KHÔNG BAO GIỜ vẽ ra một cái nút —
 * và người demo mốc này bằng đúng tài khoản được ghi trong tài liệu sẽ kết luận rằng màn hình nộp
 * không với tới được. Chỉ `khach6` đi được luồng đó, và không có dòng nào ở đâu nói ra chuyện đó.
 *
 * Bốn trạng thái phải cùng có mặt trên MỘT hồ sơ, vì bốn trạng thái ấy là bốn câu khác nhau mà
 * màn hình phải nói: đang chờ văn phòng, cần nộp lại kèm lý do, chưa nộp (bắt buộc), và chưa nộp
 * nhưng không bắt buộc — vế cuối là thứ khối "việc anh/chị cần làm" tách thành nhóm thứ hai.
 */
it('lets the documented first demo client walk the whole paperwork journey', function () {
    $demo = ClientUser::query()->where('email', 'khach1@example.com')->firstOrFail();

    $items = MatterChecklistItem::query()
        ->whereIn('matter_id', Matter::query()->where('client_id', $demo->client_id)->pluck('id'))
        ->get();

    $byStatus = fn (ChecklistItemStatus $status) => $items->where('status', $status);

    expect($byStatus(ChecklistItemStatus::PendingReview))->not->toBeEmpty()
        ->and($byStatus(ChecklistItemStatus::Rejected))->not->toBeEmpty()
        // Chưa nộp, BẮT BUỘC — đây là dòng làm nút "Gửi giấy tờ này" hiện ra.
        ->and($byStatus(ChecklistItemStatus::Missing)->where('is_required', true))->not->toBeEmpty()
        // Và chưa nộp, KHÔNG bắt buộc — nhóm thứ hai của khối "việc anh/chị cần làm".
        ->and($byStatus(ChecklistItemStatus::Missing)->where('is_required', false))->not->toBeEmpty();

    // Lời từ chối phải là một câu THẬT khách đọc và làm theo được, không phải một chỗ điền chữ.
    $rejected = $byStatus(ChecklistItemStatus::Rejected)->first();

    expect($rejected->rejection_reason)->not->toBeNull()
        ->and(mb_strlen((string) $rejected->rejection_reason))->toBeGreaterThanOrEqual(20)
        ->and($rejected->reviewed_by)->not->toBeNull()
        // Đã từng có một tệp gửi lên rồi mới bị trả lại — nếu không thì "nộp lại" là một câu
        // không có gốc, và chuỗi version bắt đầu sai.
        ->and(Document::query()->where('matter_checklist_item_id', $rejected->getKey())->count())->toBeGreaterThan(0);

    // Và cái quan trọng nhất: đi THẬT. Hình dạng dữ liệu đúng mà nút không vẽ ra thì người demo
    // vẫn kết luận màn hình nộp không với tới được — luật của mốc này: một test nói về một đường
    // ở tầng request thì phải gửi một request.
    $matter = Matter::query()
        ->where('client_id', $demo->client_id)
        ->whereIn('id', $items->where('status', ChecklistItemStatus::Rejected)->pluck('matter_id'))
        ->firstOrFail();

    Filament::setCurrentPanel('portal');

    $html = test()->actingAs($demo, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($rejected->rejection_reason)
        ->toContain(SubmitDocument::urlForItem($rejected));

    // Và màn hình nộp mở được từ chính đường dẫn ấy.
    test()->actingAs($demo, 'client')->get(SubmitDocument::urlForItem($rejected))->assertOk();
});

/**
 * 22 = 20 vụ theo kịch bản SPEC §12 + 1 vụ `restricted` (mang sang từ rà soát M2, task 10):
 * MatterSeeder::restrictedMatter() thêm đúng một vụ mật ngoài 20 vụ đánh số, để nhánh
 * `restricted` của Matter::scopeListableBy() có dữ liệu thật thay vì chỉ có trong test —
 * + 1 vụ MỞ TỪ MỘT LẦN TIẾP NHẬN (M10 Task 8, `IntakeSeeder`: `ConvertIntakeToMatter` gắn một
 * khách đã có vào vụ mới, đính chính SPEC §12 ngày 2026-10-03).
 */
it('seeds twenty matters with the deliberate situations from the spec, plus one restricted matter and one opened from an intake', function () {
    expect(Matter::count())->toBe(22)
        ->and(IntakeRequest::query()->whereNotNull('matter_id')->count())->toBe(1)
        ->and(Matter::where('last_client_update_at', '<', now()->subDays(14))->count())->toBeGreaterThanOrEqual(3)
        ->and(Deadline::query()->upcoming(3)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(2)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::Missing)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(4)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::PendingReview)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(5)
        ->and(Matter::pluck('stage')->unique()->count())->toBeGreaterThanOrEqual(4);
});

/**
 * Vụ mở từ tiếp nhận (M10 Task 8) là một vụ VỪA MỞ qua `OpenMatter`: lead trong đội ngũ, khách và bên
 * đối lập mang từ bản ghi tiếp nhận, nhưng chưa có dòng tiến độ nào — đúng hình dạng một vụ mới mở trên
 * dữ liệu thật. Luật "3–8 dòng" của SPEC §12 là của 21 vụ `MatterSeeder` dựng (đính chính SPEC §12
 * ngày 2026-10-03).
 */
it('gives every matter a lead in the team, three to eight stage logs and two to four parties', function () {
    $fromIntake = IntakeRequest::query()->whereNotNull('matter_id')->pluck('matter_id');

    Matter::with(['team', 'stageLogs', 'parties'])->get()->each(function (Matter $m) use ($fromIntake) {
        expect($m->team->pluck('id'))->toContain($m->lead_lawyer_id)
            ->and($m->parties->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($m->parties->where('is_our_client', true)->count())->toBe(1);

        $fromIntake->contains($m->id)
            ? expect($m->stageLogs)->toBeEmpty()
            : expect($m->stageLogs->count())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8);
    });

    expect(StageLog::where('is_published', true)->count())->toBeGreaterThan(0)
        ->and(StageLog::where('is_published', false)->count())->toBeGreaterThan(0);
});

it('plants one red conflict of interest between two matters', function () {
    $ours = MatterParty::where('is_our_client', true)->whereNotNull('id_number_hash')->get();

    $conflicts = MatterParty::where('is_our_client', false)
        ->whereIn('id_number_hash', $ours->pluck('id_number_hash'))
        ->get()
        ->filter(fn ($p) => $ours->where('id_number_hash', $p->id_number_hash)->where('matter_id', '!=', $p->matter_id)->isNotEmpty());

    expect($conflicts)->toHaveCount(1);
});

it('leaves some published logs unread so the dashboard has data', function () {
    $published = StageLog::where('is_published', true)->pluck('id');
    $viewed = StageLogView::whereIn('stage_log_id', $published)->pluck('stage_log_id')->unique();

    expect($viewed->count())->toBeGreaterThan(0)
        ->and($published->diff($viewed)->count())->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------------------------
// Tệp thật trên dữ liệu mẫu (SPEC §12 "dùng thật được ngay"). Cho tới vòng rà soát cuối M4,
// `grep -rn addMedia database/` không ra dòng nào: mọi `Document` của bản demo không có tệp, nên
// mọi nút tải trả 404 và route `documents.download` chưa từng chạy bằng tay một lần nào.
// ---------------------------------------------------------------------------------------------

it('gives every seeded document a real file on the private disk', function () {
    $documents = Document::withoutGlobalScopes()->get();

    expect($documents)->not->toBeEmpty();

    $withoutFile = $documents->filter(fn (Document $d): bool => $d->getFirstMedia('file') === null);

    expect($withoutFile)->toBeEmpty()
        ->and(Media::query()->pluck('disk')->unique()->all())->toBe(['private']);
});

it('seeds all four document groups so every rule in the spec table has a row to look at', function () {
    // Nhóm D phải có mặt: nhãn "Chỉ nội bộ — không bao giờ hiện cho khách" (SPEC §7.2) và cổng
    // "nhóm D không bao giờ công bố" (SPEC §4.11) chỉ kiểm được bằng mắt khi có dòng thật.
    $byGroup = Document::withoutGlobalScopes()->get()->groupBy(fn (Document $d): string => $d->group->value);

    expect($byGroup->keys()->sort()->values()->all())->toBe(['A', 'B', 'C', 'D']);

    Document::withoutGlobalScopes()->where('group', DocumentGroup::Internal)->get()
        ->each(fn (Document $d) => expect($d->client_can_download)->toBeFalse()
            ->and($d->client_can_view)->toBeFalse());
});

it('routes every seeded document through the production actions, not through addMedia by hand', function () {
    // Dấu vết của việc đó, đọc được từ dữ liệu: nhóm A của khách để lại `document_submitted`,
    // nhóm A của nhân viên nộp thay để lại `document_published`, và mọi tài liệu đều có một dòng
    // `document_uploaded` hoặc `document_submitted`. Một seeder ghi thẳng `media` không để lại
    // dòng nào trong số đó.
    $uploaded = Activity::query()->whereIn('event', ['document_uploaded', 'document_submitted'])->count();

    expect($uploaded)->toBe(Document::withoutGlobalScopes()->count())
        ->and(Activity::query()->where('event', 'document_submitted')->count())->toBeGreaterThanOrEqual(5)
        ->and(Activity::query()->where('event', 'document_published')->count())->toBeGreaterThanOrEqual(4);

    // Và ba hệ quả mà chỉ Action mới tạo ra: khách nộp thì đầu mục sang `pending_review`, nhân
    // viên nộp thay ở nhóm A thì sang `accepted` KÈM người duyệt, và văn phòng trả lại thì sang
    // `rejected` KÈM lý do. Trạng thái thứ ba mới có mặt trong dữ liệu mẫu từ vòng hợp nhất M5 —
    // trước đó không hồ sơ nào của bản demo đi qua đường "nộp lại" của SPEC §8.3 mục 4.
    $submitted = Document::withoutGlobalScopes()
        ->where('group', DocumentGroup::ClientProvided)
        ->whereNotNull('matter_checklist_item_id')
        ->with('checklistItem')
        ->get();

    expect($submitted)->not->toBeEmpty()
        ->and($submitted->every(fn (Document $d): bool => in_array(
            $d->checklistItem?->status,
            [ChecklistItemStatus::PendingReview, ChecklistItemStatus::Accepted, ChecklistItemStatus::Rejected],
            true,
        )))->toBeTrue();

    // Nới danh sách trên ra thì phải trả giá bằng một phép đo: cả BA trạng thái đều thật sự có
    // mặt. Không có câu này, thêm một trạng thái vào danh sách là làm khẳng định trên yếu đi một
    // cách không ai nhìn thấy.
    $seen = $submitted->map(fn (Document $d) => $d->checklistItem?->status)->unique()->values();

    expect($seen)->toContain(ChecklistItemStatus::PendingReview)
        ->toContain(ChecklistItemStatus::Accepted)
        ->toContain(ChecklistItemStatus::Rejected);
});

it('lets a seeded document actually download through the signed route', function () {
    // Đây là lần chạy bằng tay mà Task 5 chưa từng có: một dòng của bản demo, một người của bản
    // demo, đúng route `documents.download`, và nội dung trả về là byte của một PDF thật.
    $lawyer = User::where('email', 'luatsu1@luatvukhang.com')->firstOrFail();
    $matter = Matter::query()->listableBy($lawyer)->firstOrFail();

    $document = Document::withoutGlobalScopes()
        ->where('matter_id', $matter->id)
        ->where('group', '!=', DocumentGroup::Internal)
        ->firstOrFail();

    $response = $this->actingAs($lawyer, 'web')
        ->get($document->downloadUrlFor($lawyer))
        ->assertOk();

    expect(substr($response->streamedContent(), 0, 5))->toBe('%PDF-')
        ->and(DocumentDownload::withoutGlobalScopes()->where('document_id', $document->id)->count())->toBe(1);
});

it('writes PDFs whose xref offsets really point at their objects', function () {
    // Một PDF có xref sai vẫn mở được ở nhiều trình đọc dễ tính, nên "tệp tải về được" chưa nói
    // được gì về việc nó MỞ được. Kiểm chính bảng xref: mỗi offset phải trỏ đúng vào token
    // `N 0 obj`, và `startxref` phải trỏ vào từ `xref`.
    $pdf = DemoPdf::bytes('Giay chung nhan quyen su dung dat', 'DD-2026-0001');

    preg_match('/startxref\s+(\d+)/', $pdf, $start);

    expect(substr($pdf, 0, 8))->toBe('%PDF-1.4')
        ->and($start[1] ?? null)->not->toBeNull()
        ->and(substr($pdf, (int) $start[1], 4))->toBe('xref');

    preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $entries);

    expect($entries[1])->toHaveCount(5);

    foreach ($entries[1] as $index => $offset) {
        expect(substr($pdf, (int) $offset, strlen(($index + 1).' 0 obj')))->toBe(($index + 1).' 0 obj');
    }
});
