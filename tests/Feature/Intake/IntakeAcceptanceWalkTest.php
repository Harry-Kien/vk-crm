<?php

use App\Enums\ConflictLevel;
use App\Enums\ContractStatus;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Filament\Portal\Pages\Auth\Login;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Mail\Client\Activation;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 8 — nghiệm thu: ĐI HẾT ba luồng của kế hoạch trên DỮ LIỆU MẪU (`DatabaseSeeder`: văn phòng
 * mẫu + `IntakeSeeder`), qua màn hình thật (Livewire/HTTP), bằng đúng các tài khoản demo của README. Mỗi
 * bước là một khối có chú thích, theo đúng thứ tự kế hoạch ghi; báo cáo Task 8 và "Ghi chú M10" chép
 * lại từng bước.
 *
 *  1. Một người gọi tới → ghi nhận thông báo → kiểm tra Xanh → tư vấn → báo giá; luật sư chuyển thành
 *     vụ có mã → soạn hợp đồng M9 với giá trị gợi ý từ báo giá → ký; cấp tài khoản cổng (khách đổi mật
 *     khẩu lần đầu) và bật công bố cổng; khách đăng nhập thấy hồ sơ của mình.
 *  2. Một người gọi tới mà bên đối lập là khách hàng hiện hữu → chặn ở đúng bước đầu, nhật ký đủ,
 *     trưởng phòng từ chối, người gọi không được cho biết lý do.
 *  3. Một bản `lost` qua hạn lưu (`travelTo()`) bị tác vụ hằng ngày ẩn danh; admin xoá theo yêu cầu một
 *     bản ghi khác.
 *
 * Giờ cố định (Thứ Tư 07/10/2026 10:00, giờ văn phòng) để mọi mốc của dữ liệu mẫu đứng yên.
 *
 * Hàm toàn cục mang tiền tố `iaw…`.
 */
beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Ho_Chi_Minh'));
    $this->seed(DatabaseSeeder::class);
    Filament::setCurrentPanel('admin');
});

function iawStaff(string $email): User
{
    return User::query()->where('email', $email)->sole();
}

function iawEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

/** Mọi chuỗi đã lưu trong nhật ký, giải mã (cột `properties` là JSON thoát Unicode — LIKE không thấy). */
function iawActivityText(): string
{
    return Activity::query()->get()
        ->map(fn (Activity $row): string => json_encode($row->properties, JSON_UNESCAPED_UNICODE).'|'.$row->description)
        ->implode("\n");
}

it('walks a first call from the phone to the client portal: notice, green check, consulting, quote, conversion, signed contract, portal account and publication', function () {
    $assistant = iawStaff('troly1@luatvukhang.com');
    $lawyer = iawStaff('luatsu3@luatvukhang.com');
    $civil = MatterType::query()->where('code', 'DS')->sole();

    // ── Bước 1a — trợ lý nhận cuộc gọi: đọc câu thông báo, tích ô đồng ý, nhập phần danh tính, giao luật sư.
    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm([
            'contact_name' => 'Võ Thị Lần Đầu',
            'contact_phone' => '0988 000 101',
            'contact_email' => 'vo.thi.lan.dau@example.com',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Phone->value,
            'matter_type_id' => $civil->id,
            'assigned_to' => $lawyer->id,
            'privacy_notice' => true,
            'parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Công ty TNHH Nội Thất Hoàng Gia',
                'phone' => '0977 000 101',
                'id_number' => null,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->where('contact_name', 'Võ Thị Lần Đầu')->sole();

    // Kiểm tra chạy ở lần chạm đầu, trước câu chuyện: Xanh đủ định danh, trên văn phòng mẫu có 12 khách,
    // 23 vụ và 12 lần tiếp nhận khác (nguồn dò thứ hai).
    expect($intake->code)->toStartWith('TN-2026-')
        ->and($intake->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->conflict_result['incomplete_parties'])->toBe([])
        ->and($intake->privacy_notice_recorded_by)->toBe($assistant->id)
        ->and($intake->privacy_notice_version)->not->toBeNull();

    // ── Bước 1b — ô câu chuyện mở: trợ lý ghi câu chuyện, gọi lại cho khách (lần phản hồi đầu).
    iawEdit($intake)
        ->assertSee(__('intake.gate.open'))
        ->assertFormFieldIsEnabled('summary')
        ->set('data.summary', 'Chị Lần Đầu đặt đóng tủ bếp, đã trả 60 triệu tiền cọc, công ty giao trễ ba tháng và không trả lại cọc.')
        ->callAction(TestAction::make('saveSummary')->schemaComponent('storyActions'))
        ->assertHasNoErrors()
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Contacted->value])
        ->assertHasNoErrors();

    expect($intake->fresh()->first_response_at)->not->toBeNull();

    // ── Bước 1c — luật sư được giao tư vấn rồi báo giá 15 triệu.
    $this->actingAs($lawyer, 'web');
    iawEdit($intake)
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Consulting->value])
        ->assertHasNoErrors()
        ->fillForm(['quoted_amount' => '15.000.000'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Quoted->value])
        ->assertHasNoErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Quoted)
        ->and($intake->fresh()->quoted_amount)->toBe(15_000_000);

    // ── Bước 2a — luật sư chuyển thành vụ việc: trang điền sẵn mọi thứ từ bản ghi, không gõ lại gì.
    iawEdit($intake)->assertActionVisible('convert');
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->call('convert')
        ->assertHasNoFormErrors();

    $intake->refresh();
    $matter = Matter::query()->findOrFail($intake->matter_id);
    $client = Client::query()->findOrFail($intake->client_id);

    expect($intake->status)->toBe(IntakeStatus::Won)
        ->and($matter->code)->toStartWith('VK-2026-DS-')
        ->and($matter->lead_lawyer_id)->toBe($lawyer->id)
        ->and($matter->client_id)->toBe($client->id)
        ->and($client->name)->toBe('Võ Thị Lần Đầu')
        ->and($client->phone)->toBe('0988 000 101')
        ->and($matter->parties()->where('is_our_client', false)->sole()->phone_normalized)->toBe('84977000101')
        ->and(Client::query()->count())->toBe(13);

    // ── Bước 2b — soạn hợp đồng M9: tổng giá trị điền sẵn bằng phí đã báo; người soạn chỉ thêm lịch thu.
    $billing = fn () => $this->livewire(BillingRelationManager::class, ['ownerRecord' => $matter->fresh(), 'pageClass' => ViewMatter::class]);

    $billing()
        ->mountAction(TestAction::make('draftContract')->table())
        ->assertActionDataSet(['total_amount' => '15.000.000'])
        ->setActionData(['instalments' => [
            ['name' => 'Đợt 1 — khi ký', 'amount' => '5.000.000', 'trigger_type' => 'on_signing'],
            ['name' => 'Đợt 2 — trước phiên hoà giải', 'amount' => '10.000.000', 'trigger_type' => 'due_date', 'due_date' => '2026-11-15'],
        ]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $contract = Contract::query()->where('matter_id', $matter->id)->sole();

    expect($contract->total_amount)->toBe(15_000_000)
        ->and($contract->status)->toBe(ContractStatus::Draft);

    // ── Bước 2c — ký.
    $billing()
        ->callAction(TestAction::make('activateContract')->table(), data: ['signed_at' => '2026-10-07'])
        ->assertHasNoActionErrors();

    expect($contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and($contract->fresh()->signed_at->toDateString())->toBe('2026-10-07');

    // ── Bước 3a — cấp tài khoản cổng cho khách mới: luôn phải đổi mật khẩu lần đầu, chưa kích hoạt (R12).
    // Không ai gõ mật khẩu (luồng của `main` sau M6 Task 3): `IssuePortalAccess` sinh mật khẩu tạm và gửi
    // nó trong thư `client.activation` tới đúng địa chỉ vừa nhập — khách đọc nó từ thư.
    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $client->id,
            'name' => 'Võ Thị Lần Đầu',
            'email' => 'vo.thi.lan.dau@example.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = ClientUser::query()->where('email', 'vo.thi.lan.dau@example.com')->sole();
    $temporaryPassword = Mail::sent(Activation::class, fn (Activation $mail): bool => $mail->hasTo('vo.thi.lan.dau@example.com'))
        ->sole()
        ->temporaryPassword;

    expect($account->client_id)->toBe($client->id)
        ->and($account->must_change_password)->toBeTrue()
        ->and($account->activated_at)->toBeNull();

    // ── Bước 3b — bật công bố cổng cho vụ (`SetMatterPortalPublication` qua nút của trang vụ việc).
    expect($matter->fresh()->is_published_to_portal)->toBeFalse();

    $this->livewire(ViewMatter::class, ['record' => $matter->getRouteKey()])
        ->callAction('togglePortalPublication')
        ->assertHasNoErrors();

    expect($matter->fresh()->is_published_to_portal)->toBeTrue()
        ->and(Activity::query()->where('subject_type', 'matter')->where('subject_id', $matter->id)
            ->where('causer_id', $lawyer->id)->where('event', 'matter_portal_publication_set')->exists())->toBeTrue();

    // Một hồ sơ đang công bố của khách KHÁC, đọc trước khi phiên khách mở (dưới guard `client` mọi truy vấn
    // vụ việc chỉ ra hồ sơ của chính khách đó).
    $someoneElses = Matter::query()->where('client_id', '!=', $client->id)->where('is_published_to_portal', true)->firstOrFail();

    // ── Bước 4 — khách đăng nhập cổng: mật khẩu + mã một lần trong thư, rồi bị buộc đổi mật khẩu.
    Filament::setCurrentPanel('portal');
    auth('web')->logout();

    $login = $this->livewire(Login::class)
        ->set('data.email', 'vo.thi.lan.dau@example.com')
        ->set('data.password', $temporaryPassword)
        ->call('authenticate');

    $code = Notification::sent($account, SendLoginCode::class)->sole()->code();
    $login->set('data.multiFactor.email_code.code', $code)->call('authenticate')->assertHasNoErrors();

    expect(auth('client')->id())->toBe($account->id);

    $this->actingAs($account->fresh(), 'client');
    $this->get(MyMatters::getUrl(panel: 'portal'))->assertRedirect(ChangePassword::getUrl(panel: 'portal'));

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-rieng-cua-chi-2026')
        ->set('data.passwordConfirmation', 'mat-khau-rieng-cua-chi-2026')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect($account->fresh()->must_change_password)->toBeFalse()
        ->and($account->fresh()->activated_at)->not->toBeNull();

    // Khách thấy hồ sơ của mình — và chỉ hồ sơ của mình. Khách có đúng một hồ sơ đi thẳng vào hồ sơ đó
    // (luật của M5); danh sách vẫn mở được bằng đường "xem tất cả".
    $this->actingAs($account->fresh(), 'client');
    $this->get(MyMatters::getUrl(panel: 'portal'))
        ->assertRedirect(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'));
    $this->get(MyMatters::getUrl([MyMatters::SHOW_ALL_PARAMETER => 1], panel: 'portal'))
        ->assertOk()
        ->assertSee($matter->title);
    $this->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertSee($matter->title);

    $this->get(MatterProgress::getUrl(['record' => $someoneElses->getKey()], panel: 'portal'))->assertNotFound();
});

it('stops a call that names an existing client at the first touch, logs it, lets the manager decline it, and tells the caller no reason', function () {
    $assistant = iawStaff('troly2@luatvukhang.com');
    $manager = iawStaff('quanly@luatvukhang.com');
    $existing = Client::query()->where('name', 'Ngô Thanh Kiên')->sole();
    $existingMatter = Matter::query()->where('client_id', $existing->id)->orderBy('id')->firstOrFail();
    $reason = 'Bên bị kiện là khách hàng hiện hữu của văn phòng; không nhận, trả lời theo câu chuẩn.';

    // ── Bước 1 — trợ lý ghi cuộc gọi; bên đối lập mang đúng SĐT của một khách hiện hữu.
    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm([
            'contact_name' => 'Châu Văn Đối Đầu',
            'contact_phone' => '0988 000 102',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Phone->value,
            'privacy_notice' => true,
            'parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Ngô Thanh Kiên',
                'phone' => '0911 234 567',
                'id_number' => null,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->where('contact_name', 'Châu Văn Đối Đầu')->sole();

    expect($intake->conflict_level)->toBe(ConflictLevel::Red)
        ->and($intake->hasUnresolvedRed())->toBeTrue();

    // ── Bước 2 — chặn ở đúng bước đầu: ô câu chuyện khoá, trang nói phải làm gì, người nhập thấy mã hồ sơ
    // và vai, không thấy tiêu đề vụ; không nút xử lý Đỏ cho trợ lý; một câu chuyện gửi tay không được lưu.
    iawEdit($intake)
        ->assertFormFieldIsDisabled('summary')
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertSee($existingMatter->code)
        ->assertDontSee($existingMatter->title)
        ->assertActionDoesNotExist(TestAction::make('resolveRed')->schemaComponent('checkActions'))
        ->set('data.summary', 'Câu chuyện mà văn phòng không được nghe')
        ->callAction(TestAction::make('saveSummary')->schemaComponent('storyActions'));

    expect($intake->fresh()->summary)->toBeNull();

    // ── Bước 3 — nhật ký đầy đủ: lần ghi, lần kiểm tra (chủ thể là bản ghi, mức Đỏ, người ghi là trợ lý),
    // lần ghi nhận thông báo — không câu chuyện nào.
    $runs = Activity::query()->where('event', 'conflict_check_run')
        ->where('subject_type', 'intake_request')->where('subject_id', $intake->id)->get();

    expect($runs)->not->toBeEmpty()
        ->and($runs->last()->properties['level'])->toBe(ConflictLevel::Red->value)
        ->and($runs->last()->causer_id)->toBe($assistant->id)
        ->and(Activity::query()->where('event', 'intake_recorded')->where('subject_id', $intake->id)->sole()->properties['conflict_level'])->toBe('red')
        ->and(Activity::query()->where('event', 'intake_privacy_notice_recorded')->where('subject_id', $intake->id)->exists())->toBeTrue()
        ->and(iawActivityText())->not->toContain('Câu chuyện mà văn phòng không được nghe');

    // ── Bước 4 — trưởng phòng từ chối vì xung đột, kèm lý do.
    $this->actingAs($manager, 'web');
    iawEdit($intake)
        ->assertSee($existingMatter->code)
        ->callAction('decline', data: ['decline_reason' => $reason, 'decline_for_conflict' => true])
        ->assertHasNoErrors();

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::Declined)
        ->and($intake->decline_reason_is_conflict)->toBeTrue()
        ->and($intake->conflict_red_pending_since)->not->toBeNull()
        ->and($intake->first_response_at)->not->toBeNull()
        ->and($intake->retention_until)->not->toBeNull()
        ->and(Activity::query()->where('event', 'intake_declined')->where('subject_id', $intake->id)->sole()->causer_id)->toBe($manager->id)
        ->and(iawActivityText())->not->toContain($reason);

    // ── Bước 5 — người nhập (và người gọi qua người nhập) không được biết lý do: chỉ "đã từ chối" và câu
    // trả lời chuẩn ra ngoài; danh sách cũng vậy.
    $this->actingAs($assistant, 'web');
    iawEdit($intake)
        ->assertSee(IntakeStatus::Declined->label())
        ->assertSee(__('intake.decision.outward_answer'))
        ->assertDontSee($reason)
        ->assertDontSee(__('intake.decision.declined_for_conflict'));
    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$intake])
        ->assertDontSee($reason);

    // ── Bước 6 — người đó gọi lại hôm sau, không nhắc tới bên kia: lần gọi lại vẫn bị khoá như Đỏ (R1).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Asia/Ho_Chi_Minh'));
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm([
            'contact_name' => 'Châu Văn Đối Đầu',
            'contact_phone' => '+84 988 000 102',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Zalo->value,
            'privacy_notice' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $callback = IntakeRequest::query()->where('contact_name', 'Châu Văn Đối Đầu')->whereKeyNot($intake->id)->sole();

    expect($callback->hasUnresolvedRed())->toBeTrue();
    iawEdit($callback)->assertFormFieldIsDisabled('summary');
});

it('anonymises a lost call once its retention has passed, and lets the admin erase another record on request', function () {
    $admin = iawStaff('admin@luatvukhang.com');
    $lost = IntakeRequest::query()->where('contact_name', 'Hồ Thị Mất')->sole();
    $other = IntakeRequest::query()->where('contact_name', 'Đặng Thị Thu Hương')->sole();
    $erasure = 'Yêu cầu qua điện thoại ngày 07/10/2026, đã gọi lại đúng số đã ghi để xác minh.';

    $markersOf = fn (IntakeRequest $intake): array => array_values(array_filter([
        $intake->contact_name, $intake->contact_phone, $intake->summary,
        ...$intake->parties->pluck('name')->all(),
    ]));
    $lostMarkers = $markersOf($lost);
    $otherMarkers = $markersOf($other);

    expect($lost->status)->toBe(IntakeStatus::Lost)
        ->and($lost->retention_until)->not->toBeNull()
        ->and($lostMarkers)->toHaveCount(4)
        ->and($otherMarkers)->toHaveCount(4);

    // ── Bước 1 — ngày cuối của hạn lưu: chưa gì bị đụng.
    $event = collect(Schedule::events())->sole(fn (Event $event): bool => $event->description === 'prospects.anonymise');

    $this->travelTo(CarbonImmutable::parse($lost->retention_until->toDateString().' 03:30', 'Asia/Ho_Chi_Minh'));
    $event->run(app());

    expect($lost->fresh()->anonymised_at)->toBeNull();

    // ── Bước 2 — đêm sau ngày hạn: tác vụ hằng ngày ẩn danh bản `lost`; dòng, mã, trạng thái ở lại.
    $this->travelTo(CarbonImmutable::parse($lost->retention_until->toDateString().' 03:30', 'Asia/Ho_Chi_Minh')->addDay());
    $event->run(app());

    $fresh = $lost->fresh();

    expect($fresh->anonymised_at)->not->toBeNull()
        ->and($fresh->anonymised_by)->toBeNull()
        ->and($fresh->code)->toBe($lost->code)
        ->and($fresh->status)->toBe(IntakeStatus::Lost)
        ->and($fresh->contact_name)->toBeNull()
        ->and($fresh->summary)->toBeNull()
        ->and(IntakeParty::query()->where('intake_request_id', $lost->id)->whereNotNull('name')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'prospect_data_anonymised')->where('subject_id', $lost->id)->exists())->toBeTrue();

    // Bản còn mở (đã báo giá, chưa có hạn lưu) không bị đụng.
    expect($other->fresh()->anonymised_at)->toBeNull();

    // ── Bước 3 — admin xoá theo yêu cầu một bản ghi khác, có lý do ≥ 20 ký tự; trạng thái giữ nguyên.
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin, 'web');
    iawEdit($other)
        ->callAction('eraseData', data: ['erase_reason' => $erasure])
        ->assertHasNoActionErrors()
        ->assertNotified(__('intake.anonymise.done', ['code' => $other->code]));

    $erased = $other->fresh();

    expect($erased->anonymised_by)->toBe($admin->id)
        ->and($erased->status)->toBe(IntakeStatus::Quoted)
        ->and($erased->contact_name)->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_erased')->where('subject_id', $other->id)->sole()->properties->all())
        ->toBe(['code' => $other->code, 'reason' => $erasure]);

    // Không còn dấu nào của hai người trong bảng tiếp nhận và nhật ký.
    $columns = IntakeRequest::withTrashed()->get()->toJson(JSON_UNESCAPED_UNICODE)
        .IntakeParty::query()->get()->toJson(JSON_UNESCAPED_UNICODE)
        .iawActivityText();

    foreach ([...$lostMarkers, ...$otherMarkers] as $marker) {
        expect($columns)->not->toContain($marker);
    }
});
