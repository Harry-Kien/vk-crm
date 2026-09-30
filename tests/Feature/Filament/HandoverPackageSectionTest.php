<?php

use App\Actions\Matter\RequestHandoverPackage;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/**
 * Khối "Gói bàn giao hồ sơ" trên trang vụ việc và nút "Sinh (lại) gói bàn giao" (M7 Task 4, R9).
 * Mọi khẳng định đi qua Livewire (`ViewMatter`), không gọi thẳng Action.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create(['matter_id' => $this->matter->id, 'archived_by' => $this->lawyer->id]);
});

function hpsPage(object $test, ?User $user = null, ?Matter $matter = null): Testable
{
    $test->actingAs($user ?? $test->lawyer, 'web');

    return $test->livewire(ViewMatter::class, ['record' => ($matter ?? $test->matter)->getKey()]);
}

it('vụ đã kết thúc: hiện khối gói bàn giao ở trạng thái "Chưa sinh" và nút "Sinh gói bàn giao"', function () {
    hpsPage($this)
        ->assertSee('Gói bàn giao hồ sơ')
        ->assertSee('Chưa sinh')
        ->assertActionVisible('generateHandoverPackage')
        ->assertActionEnabled('generateHandoverPackage')
        ->assertActionHasLabel('generateHandoverPackage', 'Sinh gói bàn giao');
});

it('vụ chưa có bản ghi lưu trữ (chưa kết thúc): không có khối và không có nút', function () {
    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    hpsPage($this, matter: $open)
        ->assertDontSee('Gói bàn giao hồ sơ')
        ->assertActionHidden('generateHandoverPackage');
});

it('vụ đã kết thúc rồi được MỞ LẠI (bản ghi lưu trữ còn): vẫn thấy khối gói cũ nhưng không có nút sinh — gói chỉ sinh cho vụ đã kết thúc', function () {
    $this->archive->update(['handover_status' => HandoverPackageStatus::Ready, 'handover_requested_at' => now()->subDay()]);
    $this->matter->update(['closed_at' => null]);

    hpsPage($this)
        ->assertSee('Gói bàn giao hồ sơ')
        ->assertSee('Sẵn sàng')
        ->assertActionHidden('generateHandoverPackage');
});

it('bấm nút xếp job lên hàng handover, đặt generating và ghi người bấm', function () {
    hpsPage($this)
        ->callAction('generateHandoverPackage')
        ->assertHasNoActionErrors()
        ->assertNotified('Đã xếp hàng sinh gói bàn giao. Anh/chị sẽ được báo khi xong.');

    Queue::assertPushed(GenerateHandoverPackage::class, fn (GenerateHandoverPackage $job): bool => $job->matterId === $this->matter->id
        && $job->queue === 'handover');

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_requested_by)->toBe($this->lawyer->id);

    expect(Activity::query()->where('event', 'handover_package_requested')->count())->toBe(1);
});

it('đang sinh: hiện trạng thái, thời điểm bấm và khoá nút; bấm cũng không xếp thêm job', function () {
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->subMinutes(2),
        'handover_requested_by' => $this->lawyer->id,
    ]);

    hpsPage($this)
        ->assertSee('Đang sinh')
        ->assertSee(now()->subMinutes(2)->format('d/m/Y H:i'))
        ->assertSee($this->lawyer->name)
        ->assertActionDisabled('generateHandoverPackage')
        ->assertActionHasLabel('generateHandoverPackage', 'Sinh lại gói bàn giao')
        ->callAction('generateHandoverPackage');

    Queue::assertNothingPushed();
});

it('đang sinh nhưng kẹt quá lâu: nút mở khoá lại kèm lời nhắc', function () {
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->subMinutes(RequestHandoverPackage::STALE_AFTER_MINUTES + 5),
    ]);

    hpsPage($this)
        ->assertSee('chạy quá lâu bất thường')
        ->assertActionEnabled('generateHandoverPackage')
        ->callAction('generateHandoverPackage')
        ->assertHasNoActionErrors();

    Queue::assertPushed(GenerateHandoverPackage::class, 1);
});

it('lỗi: hiện câu lỗi cho người vận hành và cho bấm lại', function () {
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Failed,
        'handover_requested_at' => now()->subHour(),
        'handover_error' => 'Không đọc được tệp của tài liệu "Đơn khởi kiện".',
    ]);

    hpsPage($this)
        ->assertSee('Lỗi')
        ->assertSee('Không đọc được tệp của tài liệu "Đơn khởi kiện".')
        ->assertActionEnabled('generateHandoverPackage')
        ->assertActionHasLabel('generateHandoverPackage', 'Sinh lại gói bàn giao');
});

it('sẵn sàng: hiện tài liệu gói, phiên bản và thời điểm xong', function () {
    $package = Document::factory()->create([
        'matter_id' => $this->matter->id, 'title' => 'Gói bàn giao hồ sơ '.$this->matter->code, 'version' => 3,
    ]);
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Ready,
        'handover_document_id' => $package->id,
        'handover_requested_at' => now()->subMinutes(10),
        'handover_generated_at' => now()->subMinutes(8),
    ]);

    hpsPage($this)
        ->assertSee('Sẵn sàng')
        ->assertSee('Gói bàn giao hồ sơ '.$this->matter->code.' — phiên bản 3')
        ->assertSee(now()->subMinutes(8)->format('d/m/Y H:i'))
        ->assertSee(now()->subMinutes(10)->format('d/m/Y H:i'));
});

it('lần tự sinh khi vụ đóng (không có người bấm) ghi rõ "Tự động khi vụ kết thúc"', function () {
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->subMinute(),
        'handover_requested_by' => null,
    ]);

    hpsPage($this)->assertSee('Tự động khi vụ kết thúc');
});

it('người bấm đã bị xoá mềm: KHÔNG ghi sai là "Tự động khi vụ kết thúc"', function () {
    $requester = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Ready,
        'handover_requested_at' => now()->subMinute(),
        'handover_requested_by' => $requester->id,
    ]);
    $requester->delete();

    hpsPage($this)
        ->assertSee('Sẵn sàng')
        ->assertDontSee('Tự động khi vụ kết thúc');
});

it('trang mở từ trước khi người khác bấm sinh: bấm nút không xếp job thứ hai, không đè lần yêu cầu kia', function () {
    $page = hpsPage($this)->assertActionEnabled('generateHandoverPackage');

    // Trong lúc trang này còn mở, một người khác đã bấm (dòng lưu trữ sang `generating`).
    $other = User::factory()->withRole(Role::Admin)->create();
    $requestedAt = now()->subMinute()->startOfSecond();
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => $requestedAt,
        'handover_requested_by' => $other->id,
    ]);

    $page->callAction('generateHandoverPackage');

    Queue::assertNothingPushed();

    $archive = $this->archive->fresh();

    expect($archive->handover_requested_by)->toBe($other->id)
        ->and($archive->handover_requested_at->getTimestamp())->toBe($requestedAt->getTimestamp());
});

it('trợ lý (không có document.publish) xem được khối nhưng không có nút', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->team()->attach($assistant->id, ['role_in_matter' => 'assistant']);

    hpsPage($this, $assistant)
        ->assertSee('Gói bàn giao hồ sơ')
        ->assertActionHidden('generateHandoverPackage');

    Queue::assertNothingPushed();
    expect($this->archive->fresh()->handover_status)->toBeNull();
});

it('người không xem được vụ (manager với vụ restricted) không vào được trang, nên không thấy gì về gói', function () {
    $restricted = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->lawyer->id, 'closed_at' => now()->subDay()->toDateString(),
    ]);
    MatterArchive::factory()->create([
        'matter_id' => $restricted->id, 'handover_status' => HandoverPackageStatus::Failed,
        'handover_error' => 'CAU-LOI-CUA-VU-RESTRICTED',
    ]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web');

    expect(fn () => $this->livewire(ViewMatter::class, ['record' => $restricted->getKey()]))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs($this->lawyer, 'web');
    $this->livewire(ViewMatter::class, ['record' => $restricted->getKey()])->assertSee('CAU-LOI-CUA-VU-RESTRICTED');
});
