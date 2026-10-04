<?php

use App\Actions\Document\MarkChecklistItemNotApplicable;
use App\Actions\Document\RetractDocument;
use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\MatterLockRace;

/**
 * Rà soát cuối M7, I1 — luật M6.5 (fix round 3, `TriageClientRequest::realMatterId()`): bên trong
 * transaction của một Action khoá vụ việc, câu ĐẦU TIÊN là câu khoá dòng `matters`, và nó không đọc
 * trần bảng nào khác. Mọi câu đọc trần cần để biết khoá DÒNG nào (`matter_id` của bản ghi con) chạy
 * TRƯỚC `DB::transaction()`, trong một câu auto-commit riêng.
 *
 * Vì sao: trên MariaDB (REPEATABLE READ), lần đọc KHÔNG khoá đầu tiên cố định READ VIEW của cả
 * transaction. Đứng trước khoá `matters` (M7 Task 3: `OpensChecklistItem` đọc đầu mục trước khi khoá
 * vụ; M7 Task 7: `RetractDocument` khoá vụ qua một truy vấn con KHÔNG khoá trên `documents`), nó cố
 * định READ VIEW TRƯỚC lúc đợi khoá — và một lần gỡ khỏi đội ngũ hay vô hiệu hoá commit trong lúc
 * đợi thì Gate/lần đọc lại người thực hiện phía sau không thấy.
 *
 * Hai lớp test:
 *  - **Thứ tự câu lệnh** (mọi driver, chạy trong bộ thường): câu đầu tiên sau khi Action mở
 *    transaction là `select * from matters where matters.id = ?` — không truy vấn con, không bảng
 *    khác. SQLite không có READ VIEW, nên đây là chỗ duy nhất bộ thường thấy được luật này.
 *  - **Cuộc đua thật** (chỉ `test:mariadb`, nhóm `mariadb-locking`): {@see MatterLockRace} giữ khoá
 *    `matters` ở một tiến trình khác, chờ Action THẬT bị chặn, rồi gỡ người thực hiện khỏi đội ngũ /
 *    vô hiệu hoá họ và commit. Action phải từ chối.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

/** SQL của câu đầu tiên chạy SAU khi Action mở transaction của chính nó. */
function mlbsFirstStatementInsideTransaction(Closure $call): ?string
{
    $opened = false;
    $first = null;

    Event::listen(TransactionBeginning::class, function () use (&$opened): void {
        $opened = true;
    });

    DB::listen(function (QueryExecuted $query) use (&$opened, &$first): void {
        if ($opened && $first === null) {
            $first = $query->sql;
        }
    });

    $call();

    return $first;
}

/** `select * from matters where matters.id = ?` theo đúng cách quote của driver đang chạy. */
function mlbsMatterLockPrefix(): string
{
    $grammar = DB::connection()->getQueryGrammar();

    return 'select * from '.$grammar->wrapTable('matters').' where '.$grammar->wrap('matters.id').' = ?';
}

function mlbsPublishedDocument(Matter $matter): Document
{
    return Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'published_at' => now()->subDay(),
    ]);
}

const MLBS_REASON = 'Văn bản này công bố nhầm, văn phòng sẽ gửi bản đúng sau.';

// ---------------------------------------------------------------------------------------------
// Thứ tự câu lệnh — mọi driver.
// ---------------------------------------------------------------------------------------------

it('ReviewChecklistItem: câu đầu tiên trong transaction là khoá dòng matters, không đọc đầu mục trước', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->status(ChecklistItemStatus::PendingReview)->create();

    $first = mlbsFirstStatementInsideTransaction(
        fn () => app(ReviewChecklistItem::class)->handle($item, $this->lead, ChecklistItemStatus::Accepted),
    );

    expect($first)->toStartWith(mlbsMatterLockPrefix())
        ->and($item->fresh()->status)->toBe(ChecklistItemStatus::Accepted);
});

it('MarkChecklistItemNotApplicable: câu đầu tiên trong transaction là khoá dòng matters', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->status(ChecklistItemStatus::Missing)->create();

    $first = mlbsFirstStatementInsideTransaction(
        fn () => app(MarkChecklistItemNotApplicable::class)->handle($item, $this->lead),
    );

    expect($first)->toStartWith(mlbsMatterLockPrefix())
        ->and($item->fresh()->status)->toBe(ChecklistItemStatus::NotApplicable);
});

it('RetractDocument: câu đầu tiên trong transaction khoá matters theo id, không qua truy vấn con trên documents', function () {
    $document = mlbsPublishedDocument($this->matter);

    $first = mlbsFirstStatementInsideTransaction(
        fn () => app(RetractDocument::class)->handle($document, $this->lead, MLBS_REASON),
    );

    expect($first)->toStartWith(mlbsMatterLockPrefix())
        ->and($first)->not->toContain(DB::connection()->getQueryGrammar()->wrapTable('documents'))
        ->and($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});

// ---------------------------------------------------------------------------------------------
// Cuộc đua thật — chỉ MariaDB.
// ---------------------------------------------------------------------------------------------

it('MarkChecklistItemNotApplicable từ chối người vừa bị gỡ khỏi đội ngũ trong lúc nó đợi khoá matters (MariaDB, hai phiên)', function () {
    MatterLockRace::requireMariadb();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $item = MatterChecklistItem::factory()->for($this->matter)->status(ChecklistItemStatus::Missing)->create();

    $matterId = $this->matter->id;
    $assistantId = $assistant->id;

    $outcome = MatterLockRace::run(
        $matterId,
        fn (Connection $child) => $child->table('matter_user')
            ->where('matter_id', $matterId)
            ->where('user_id', $assistantId)
            ->delete(),
        function () use ($item, $assistant): string {
            try {
                app(MarkChecklistItemNotApplicable::class)->handle($item, $assistant);

                return 'marked';
            } catch (ChecklistItemNotReviewable) {
                return 'refused';
            }
        },
    );

    expect($outcome)->toBe('refused')
        ->and(MatterChecklistItem::query()->find($item->id)->status)->toBe(ChecklistItemStatus::Missing);
})->group('mariadb-locking');

/**
 * Hai lần ghi đồng thời: gỡ khỏi đội ngũ (Gate `publish` đọc `matter_user`) và vô hiệu hoá (Action
 * đọc lại người thực hiện). Trước bản sửa, đo trên MariaDB 11.8 của dự án: ca gỡ khỏi đội ngũ RÚT
 * ĐƯỢC (READ VIEW cũ thấy người đó còn trong đội); ca vô hiệu hoá cũng qua được lần đọc lại và Gate,
 * rồi chết ở câu UPDATE với lỗi 1020 "Record has changed since last read" — kiểm khoá ngoại
 * `retracted_by` đọc có khoá dòng `users` vừa đổi, và `innodb_snapshot_isolation` (bật mặc định từ
 * MariaDB 11.6) từ chối. Cả hai đều sai: đúng là một lời từ chối sạch.
 */
it('RetractDocument từ chối người vừa mất quyền trong lúc nó đợi khoá matters (MariaDB, hai phiên)', function (string $write) {
    MatterLockRace::requireMariadb();

    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    $document = mlbsPublishedDocument($this->matter);

    $matterId = $this->matter->id;
    $associateId = $associate->id;

    $outcome = MatterLockRace::run(
        $matterId,
        fn (Connection $child) => $write === 'removed_from_team'
            ? $child->table('matter_user')->where('matter_id', $matterId)->where('user_id', $associateId)->delete()
            : $child->table('users')->where('id', $associateId)->update(['is_active' => false]),
        function () use ($document, $associate): string {
            try {
                app(RetractDocument::class)->handle($document, $associate, MLBS_REASON);

                return 'retracted';
            } catch (AuthorizationException) {
                return 'refused';
            } catch (QueryException $exception) {
                return 'database error: '.$exception->errorInfo[1];
            }
        },
    );

    expect($outcome)->toBe('refused')
        ->and(Document::query()->find($document->id)->status)->toBe(DocumentStatus::Published);
})->with(['removed_from_team', 'deactivated'])->group('mariadb-locking');
