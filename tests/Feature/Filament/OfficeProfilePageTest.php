<?php

use App\Actions\Matter\RenderHandoverIndex;
use App\Enums\Role;
use App\Filament\Admin\Pages\OfficeProfilePage;
use App\Filament\Portal\Pages\MatterProgress;
use App\Mail\Client\StageUpdate;
use App\Mail\Staff\DeadlineReminder;
use App\Mail\Staff\HandoverPackageReady;
use App\Mail\Staff\MatterReassigned;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Setting;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use App\Support\OfficeProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\Email;
use Tests\Support\PdfText;

/**
 * M7 Task 10 — trang "Thông tin văn phòng" (chỉ admin). Mọi hành vi của màn hình đo qua Livewire
 * hoặc HTTP, không gọi thẳng Action: lưu xong thì thư, `MUC-LUC.pdf` và cổng khách hàng render
 * SAU ĐÓ mang giá trị mới; để trống thì chân thư/chân PDF bỏ hẳn dòng đó.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();

    // Trạng thái hôm nay của `.env`: bốn thông tin pháp lý trống, năm trường còn lại có giá trị.
    config([
        'vkcrm.brand.tax_code' => null,
        'vkcrm.brand.bar_association' => null,
        'vkcrm.brand.licence_number' => null,
        'vkcrm.brand.office_address' => null,
    ]);
});

/** @return array<string, string> chín trường mới, khác hẳn mọi giá trị mặc định của cấu hình */
function newOfficeValues(): array
{
    return [
        'legal_name' => 'Công ty Luật TNHH Pháp Lý Mới',
        'tax_code' => '0109876543-002',
        'bar_association' => 'Đoàn Luật sư TP. Đà Nẵng',
        'licence_number' => '32.01.0099/TP/ĐKHĐ',
        'office_address' => '45 Bạch Đằng, Hải Châu, Đà Nẵng',
        'hotline' => '0236 3888 999',
        'zalo' => 'https://zalo.me/0905111222',
        'website' => 'https://phaplymoi.vn',
        'reply_to' => 'hoidap@phaplymoi.vn',
    ];
}

/** Lưu qua đúng màn hình, như admin bấm "Lưu". */
function saveOfficeProfileThroughPage(array $values): void
{
    Livewire::test(OfficeProfilePage::class)
        ->fillForm($values)
        ->call('save')
        ->assertHasNoFormErrors();
}

/** Thư THẬT cuối cùng đã "gửi" qua transport `array` (cùng kỹ thuật `EmailLayoutTest`). */
function lastOfficeMail(): Email
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();
}

function officeStageUpdateMail(): StageUpdate
{
    return new StageUpdate(StageLog::factory()->create(), ClientUser::factory()->create());
}

function officeIndexPdfText(): string
{
    $matter = Matter::factory()->create(['closed_at' => now()->toDateString()]);

    return PdfText::squash(PdfText::extract(app(RenderHandoverIndex::class)->handle($matter, collect())));
}

function officePageSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === OfficeProfilePage::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Thông tin văn phòng trong HTML.');
}

/** Một request cập nhật Livewire THẬT (đường `/livewire-…/update`), không qua `Livewire::test()`. */
function postOfficePageUpdate(string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]);
}

// ---------------------------------------------------------------------------------------------
// Cổng: chỉ admin; mọi người khác nhận 404, gồm cả đường Livewire update.
// ---------------------------------------------------------------------------------------------

it('mở được cho admin và hiện chín ô', function () {
    $this->actingAs($this->admin, 'web')
        ->get(OfficeProfilePage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('office.page_title'))
        ->assertSee(__('office.fields.tax_code.label'))
        ->assertSee(__('office.fields.reply_to.label'));
});

it('trả 404 cho mọi người không phải admin, và không hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(OfficeProfilePage::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(OfficeProfilePage::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

/**
 * Đường Livewire update THẬT: admin mở trang, rồi bị hạ xuống trưởng phòng, rồi gửi một request
 * cập nhật từ trang đang mở. Middleware 404 của panel không phủ được request này, và
 * `CanAuthorizeAccess::hydrateCanAuthorizeAccess()` của Filament trả 403, không phải 404.
 *
 * Mutation probe: xoá `boot()` của trang làm test đỏ ("Expected response status code [404] but
 * received 403").
 */
it('trả 404 cho một request cập nhật Livewire từ người đã mất quyền, và không lưu gì', function () {
    $snapshot = officePageSnapshot(
        $this->actingAs($this->admin, 'web')->get(OfficeProfilePage::getUrl(panel: 'admin'))->assertOk()->getContent()
    );

    $this->admin->syncRoles([Role::Manager->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    postOfficePageUpdate($snapshot, ['data.tax_code' => '0312345678'])->assertNotFound();
    postOfficePageUpdate($snapshot, [], [['path' => '', 'method' => 'save', 'params' => []]])->assertNotFound();

    expect(Setting::query()->count())->toBe(0);
});

/** Cùng đường, qua `Livewire::test()`: lần gọi `save` sau khi người dùng đổi thành luật sư. */
it('trả 404 khi gọi save dưới một tài khoản không phải admin', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(OfficeProfilePage::class)->fillForm(newOfficeValues());

    $this->actingAs(User::factory()->withRole(Role::Lawyer)->create(), 'web');

    $page->call('save')->assertNotFound();

    expect(Setting::query()->count())->toBe(0);
});

/**
 * Hành động THẬT tự hỏi lại cổng, độc lập với `boot()` (một thể hiện dựng thẳng không đi qua vòng
 * đời Livewire nào). Mutation probe: bỏ `abort_unless` đầu `save()` làm test đỏ.
 */
it('save() tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new OfficeProfilePage)->save())->toThrow(NotFoundHttpException::class);
});

// ---------------------------------------------------------------------------------------------
// Form: độ dài, kiểm tra đầu vào, chuẩn hoá.
// ---------------------------------------------------------------------------------------------

it('giới hạn mỗi ô đúng bằng giới hạn của trường trong OfficeProfile::FIELDS', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(OfficeProfilePage::class);

    foreach (OfficeProfile::FIELDS as $field => $limit) {
        $page->assertFormFieldExists($field, fn (Field $component): bool => $component->getMaxLength() === $limit);
    }
});

/**
 * Lỗi của Action (mã số thuế, hotline) gắn đúng vào ô của nó trên form. Mutation probe: bỏ việc đổi
 * khoá lỗi sang `data.<trường>` trong `save()` làm test đỏ (lỗi không nằm trên ô nào).
 */
it('hiện lỗi đúng ô khi mã số thuế hay hotline sai dạng, và không lưu gì', function (string $field, string $value) {
    $this->actingAs($this->admin, 'web');

    Livewire::test(OfficeProfilePage::class)
        ->fillForm([...newOfficeValues(), $field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'mã số thuế 9 chữ số' => ['tax_code', '010987654'],
    'mã số thuế đuôi 2 chữ số' => ['tax_code', '0109876543-02'],
    'hotline quá ngắn' => ['hotline', '12345'],
    'hotline đầu số dịch vụ 9 chữ số' => ['hotline', '1900 12345'],
    'zalo không phải URL' => ['zalo', 'zalo.me/0905111222'],
    'email hỏng' => ['reply_to', 'hoidap-phaplymoi.vn'],
]);

it('từ chối một ô dài quá giới hạn ngay ở form', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(OfficeProfilePage::class)
        ->fillForm(['office_address' => str_repeat('a', OfficeProfile::FIELDS['office_address'] + 1)])
        ->call('save')
        ->assertHasFormErrors(['office_address']);

    expect(Setting::query()->count())->toBe(0);
});

it('lưu chín trường, chuẩn hoá hotline và mã số thuế, rồi hiện lại giá trị đã chuẩn hoá', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(OfficeProfilePage::class)
        ->fillForm([...newOfficeValues(), 'tax_code' => '0109876543002', 'hotline' => '+84 236 3888 999'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('office.notifications.saved'))
        ->assertFormSet(['hotline' => '02363888999', 'tax_code' => '0109876543-002']);

    $office = OfficeProfile::current();

    expect($office->legalName())->toBe('Công ty Luật TNHH Pháp Lý Mới')
        ->and($office->taxCode())->toBe('0109876543-002')
        ->and($office->hotline())->toBe('02363888999')
        ->and($office->replyTo())->toBe('hoidap@phaplymoi.vn');
});

/**
 * Đầu số dịch vụ làm hotline: lưu nguyên các chữ số, không thành `019006557` (số không tồn tại) —
 * form hiện lại đúng số, và chân trang đăng nhập của cổng mang `tel:19006557`.
 */
it('lưu hotline đầu số dịch vụ 1900 nguyên các chữ số, và cổng gọi đúng số đó', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(OfficeProfilePage::class)
        ->fillForm([...newOfficeValues(), 'hotline' => '1900 6557'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet(['hotline' => '19006557']);

    expect(OfficeProfile::current()->hotline())->toBe('19006557');

    auth('web')->logout();

    $this->get('/portal/login')
        ->assertOk()
        ->assertSee('tel:19006557', false)
        ->assertDontSee('019006557');
});

/** Ô trống là "dùng giá trị của `.env`", nên form không điền sẵn giá trị cấu hình vào ô — chỉ gợi ý. */
it('mở form với giá trị đã lưu, để trống những trường đang dùng cấu hình', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(OfficeProfilePage::class)
        ->assertFormSet(['hotline' => null, 'legal_name' => null])
        ->assertSee((string) config('vkcrm.brand.hotline'));

    saveOfficeProfileThroughPage(['hotline' => '0905111222']);

    Livewire::test(OfficeProfilePage::class)->assertFormSet(['hotline' => '0905111222', 'legal_name' => null]);
});

it('báo "không có gì thay đổi" khi bấm lưu mà không sửa gì', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    Livewire::test(OfficeProfilePage::class)
        ->call('save')
        ->assertNotified(__('office.notifications.unchanged'));
});

/** Audit qua màn hình: tên đúng những trường đổi. */
it('ghi audit nêu đúng tên các trường admin vừa sửa trên màn hình', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(['tax_code' => '0109876543', 'website' => 'https://phaplymoi.vn']);

    $activity = Activity::query()->where('event', 'office_profile_updated')->sole();

    expect($activity->properties->get('changed_fields'))->toBe(['tax_code', 'website'])
        ->and($activity->causer?->is($this->admin))->toBeTrue()
        ->and(__('activity.events.office_profile_updated'))->not->toBe('activity.events.office_profile_updated');
});

// ---------------------------------------------------------------------------------------------
// Lưu xong → thư, PDF, cổng render SAU ĐÓ mang giá trị mới.
// ---------------------------------------------------------------------------------------------

it('thư cho khách gửi sau khi lưu mang tên pháp lý, bốn thông tin pháp lý, hotline, website và Reply-To mới', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    Mail::to('khach@example.test')->send(officeStageUpdateMail());

    $email = lastOfficeMail();

    foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
        expect($body)->toContain('Công ty Luật TNHH Pháp Lý Mới')
            ->and($body)->toContain('45 Bạch Đằng, Hải Châu, Đà Nẵng')
            ->and($body)->toContain(__('emails.footer.tax_code', ['value' => '0109876543-002']))
            ->and($body)->toContain(__('emails.footer.bar_association', ['value' => 'Đoàn Luật sư TP. Đà Nẵng']))
            ->and($body)->toContain(__('emails.footer.licence_number', ['value' => '32.01.0099/TP/ĐKHĐ']))
            ->and($body)->toContain('02363888999')
            ->and($body)->toContain('https://phaplymoi.vn')
            ->and($body)->not->toContain((string) config('vkcrm.brand.hotline'));
    }

    expect($email->getReplyTo()[0]->getAddress())->toBe('hoidap@phaplymoi.vn');
});

/**
 * Thư nhân sự: tên văn phòng in hai lần — ở dòng ký tên của chính mẫu thư (biến `office` mà
 * `content()` đưa vào) và ở chân thư của layout. Chỉ khẳng định "có tên mới" thì chân thư đã đủ làm
 * test xanh, nên test khẳng định thêm tên CŨ của cấu hình không còn ở đâu trong thư.
 *
 * Mutation probe: cho `content()` của một trong ba mẫu đọc lại tên pháp lý từ cấu hình làm ca của
 * mẫu đó đỏ (dòng ký tên mang tên cũ).
 */
it('thư cho nhân sự gửi sau khi lưu mang tên pháp lý mới ở cả dòng ký tên lẫn chân thư', function (string $kind) {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($stage) => $stage->is_terminal)->first()->key,
    ]);

    $mail = match ($kind) {
        'deadline_reminder' => new DeadlineReminder(Deadline::factory()->create([
            'matter_id' => $matter->id,
            'responsible_user_id' => $lawyer->id,
            'due_date' => today()->addDays(7),
        ]), $lawyer, 'd7'),
        'matter_reassigned' => new MatterReassigned($lawyer, []),
        'handover_ready' => new HandoverPackageReady($lawyer, $matter, Document::factory()->create(['matter_id' => $matter->id])),
    };

    Mail::to($lawyer->email)->send($mail);

    $old = (string) config('vkcrm.brand.legal_name');
    $html = (string) lastOfficeMail()->getHtmlBody();
    $text = (string) lastOfficeMail()->getTextBody();

    expect($old)->not->toBe('')
        ->and($html)->toContain('Công ty Luật TNHH Pháp Lý Mới')
        ->and($html)->not->toContain(e($old))
        ->and($text)->toContain('Công ty Luật TNHH Pháp Lý Mới')
        ->and($text)->not->toContain($old);
})->with(['deadline_reminder', 'matter_reassigned', 'handover_ready']);

it('thư mã đăng nhập gửi sau khi lưu mang hotline mới trong thân thư', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    ClientUser::factory()->create()->notify(new SendLoginCode('123456', 5));

    expect((string) lastOfficeMail()->getTextBody())
        ->toContain(__('portal.email.otp.ignore', ['phone' => '02363888999']))
        ->toContain(__('portal.email.otp.salutation', ['office' => 'Công ty Luật TNHH Pháp Lý Mới']));
});

/**
 * "Thư đang nằm trong hàng đợi dùng giá trị ở LÚC RENDER, không phải lúc xếp hàng." Một thư dựng
 * và tuần tự hoá (như khi xếp hàng) TRƯỚC lần lưu, gửi SAU lần lưu, mang giá trị mới.
 *
 * Mutation probe: cho `StageUpdate` chụp `office`/`hotline` vào thuộc tính lúc khởi tạo làm test đỏ.
 */
it('một thư xếp hàng trước lần lưu nhưng render sau đó mang giá trị mới', function () {
    $queued = serialize(officeStageUpdateMail());

    $this->actingAs($this->admin, 'web');
    saveOfficeProfileThroughPage(newOfficeValues());

    Mail::to('khach@example.test')->send(unserialize($queued));

    expect((string) lastOfficeMail()->getTextBody())->toContain('Công ty Luật TNHH Pháp Lý Mới')
        ->and((string) lastOfficeMail()->getTextBody())->toContain(__('portal.email.stage_update.help', ['phone' => '02363888999']));
});

it('MUC-LUC.pdf sinh sau khi lưu mang tên pháp lý và bốn thông tin pháp lý mới ở chân trang', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    $text = officeIndexPdfText();

    foreach ([
        'Công ty Luật TNHH Pháp Lý Mới',
        '45 Bạch Đằng, Hải Châu, Đà Nẵng',
        __('emails.footer.tax_code', ['value' => '0109876543-002']),
        __('emails.footer.bar_association', ['value' => 'Đoàn Luật sư TP. Đà Nẵng']),
        __('emails.footer.licence_number', ['value' => '32.01.0099/TP/ĐKHĐ']),
    ] as $line) {
        expect($text)->toContain(PdfText::squash($line));
    }
});

// ---------------------------------------------------------------------------------------------
// Để trống → chân thư (HTML, văn bản) và chân PDF bỏ hẳn dòng đó, không nhãn treo.
// ---------------------------------------------------------------------------------------------

it('xoá trắng bốn thông tin pháp lý thì chân thư và chân PDF bỏ hẳn dòng lẫn nhãn', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());

    saveOfficeProfileThroughPage([
        ...newOfficeValues(),
        'tax_code' => '',
        'bar_association' => '',
        'licence_number' => '',
        'office_address' => '',
    ]);

    Mail::to('khach@example.test')->send(officeStageUpdateMail());

    $email = lastOfficeMail();
    $html = (string) $email->getHtmlBody();
    $text = (string) $email->getTextBody();
    $pdf = officeIndexPdfText();

    $label = fn (string $key): string => trim(str_replace(':value', '', __("emails.footer.{$key}")));

    foreach (['tax_code', 'bar_association', 'licence_number'] as $key) {
        expect($html)->not->toContain($label($key))
            ->and($text)->not->toContain($label($key))
            ->and($pdf)->not->toContain(PdfText::squash($label($key)));
    }

    foreach ([$html, $text] as $body) {
        expect($body)->not->toContain('45 Bạch Đằng')
            ->and($body)->toContain('Công ty Luật TNHH Pháp Lý Mới');
    }

    expect($pdf)->not->toContain(PdfText::squash('45 Bạch Đằng'))
        // Không thẻ khối rỗng trong HTML, không dòng trắng thừa trong văn bản thuần.
        ->and(preg_match('/<(p|div|td|span)\b[^>]*>\s*<\/\1>/', $html))->toBe(0)
        ->and(preg_match('/\n[ \t]*\n[ \t]*\n/', $text))->toBe(0);
});

/** Trống = dùng `.env`: xoá trắng tên pháp lý thì thư quay về tên của cấu hình, không thành dòng trống. */
it('xoá trắng tên pháp lý thì thư quay về tên trong cấu hình', function () {
    $this->actingAs($this->admin, 'web');

    saveOfficeProfileThroughPage(newOfficeValues());
    saveOfficeProfileThroughPage([...newOfficeValues(), 'legal_name' => '']);

    Mail::to('khach@example.test')->send(officeStageUpdateMail());

    expect((string) lastOfficeMail()->getTextBody())->toContain((string) config('vkcrm.brand.legal_name'))
        ->and((string) lastOfficeMail()->getTextBody())->not->toContain('Công ty Luật TNHH Pháp Lý Mới');
});

/**
 * Trống ở CẢ HAI nơi (không lưu gì, và `.env` đặt rỗng) thì tên pháp lý, hotline, website cũng
 * biến mất khỏi chân thư và chân PDF — không "Điện thoại:" treo, không thẻ khối rỗng.
 *
 * Mutation probe: bỏ một trong các `@if (filled(...))` ở `emails/layout.blade.php` /
 * `layout-text.blade.php`, hay `@if ($officeName !== '')` ở hai view của `MUC-LUC.pdf`, làm test đỏ.
 */
it('khi cả cấu hình cũng trống, chân thư và chân PDF không in nhãn treo hay thẻ rỗng nào', function () {
    config(['vkcrm.brand.legal_name' => '', 'vkcrm.brand.hotline' => '', 'vkcrm.brand.website' => '']);

    Mail::to('khach@example.test')->send(officeStageUpdateMail());

    $email = lastOfficeMail();
    $html = (string) $email->getHtmlBody();
    $text = (string) $email->getTextBody();

    $label = fn (string $key): string => trim(str_replace(':value', '', __("emails.footer.{$key}")));

    foreach (['hotline', 'website'] as $key) {
        expect($html)->not->toContain($label($key))
            ->and($text)->not->toContain($label($key));
    }

    expect(preg_match('/<(p|div|td|span|strong)\b[^>]*>\s*<\/\1>/', $html))->toBe(0)
        ->and(preg_match('/\n[ \t]*\n[ \t]*\n/', $text))->toBe(0)
        // Chân văn bản thuần: ngay sau dấu `--` là câu "thư tự động", không dòng trống nào.
        ->and($text)->toContain("--\n".__('emails.footer.automated'));

    $matter = Matter::factory()->create(['closed_at' => now()->toDateString()]);

    $pdf = app(RenderHandoverIndex::class)->handle($matter, collect());

    // dompdf dựng thẻ rỗng thành một luồng văn bản rỗng — đo trên chính HTML mà nó nhận.
    $indexHtml = view('handover.index', [
        'officeName' => '',
        'legalLines' => [],
        'generatedAt' => '01/10/2026',
        'matterInfo' => [],
        'entries' => collect(),
        'timeline' => collect(),
    ])->render();

    expect($pdf)->toStartWith('%PDF')
        ->and(preg_match('/<(div|strong)\b[^>]*>\s*(<strong>\s*<\/strong>)?\s*<\/\1>/', $indexHtml))->toBe(0)
        ->and($indexHtml)->not->toContain('class="office"');
});

// ---------------------------------------------------------------------------------------------
// Cổng khách hàng: chân trang đăng nhập, trang lỗi, trạng thái trống hiện giá trị mới.
// ---------------------------------------------------------------------------------------------

it('chân trang đăng nhập của cổng hiện tên pháp lý, hotline và website mới', function () {
    $this->actingAs($this->admin, 'web');
    saveOfficeProfileThroughPage(newOfficeValues());

    auth('web')->logout();

    $this->get('/portal/login')
        ->assertOk()
        ->assertSee('Công ty Luật TNHH Pháp Lý Mới')
        ->assertSee('tel:02363888999', escape: false)
        ->assertSee('phaplymoi.vn')
        ->assertDontSee((string) config('vkcrm.brand.hotline'));
});

it('trang 404 của cổng mang hotline mới', function () {
    $this->actingAs($this->admin, 'web');
    saveOfficeProfileThroughPage(newOfficeValues());

    auth('web')->logout();

    $clientUser = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    $this->actingAs($clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => 999999], panel: 'portal'))
        ->assertNotFound()
        ->assertSee('tel:02363888999', escape: false)
        ->assertDontSee((string) config('vkcrm.brand.hotline'));
});

it('trạng thái "chưa có hồ sơ" của cổng mang hotline và Zalo mới', function () {
    $this->actingAs($this->admin, 'web');
    saveOfficeProfileThroughPage(newOfficeValues());

    auth('web')->logout();

    $clientUser = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    $this->actingAs($clientUser, 'client')
        ->get(url('/portal'))
        ->assertOk()
        ->assertSee('tel:02363888999', escape: false)
        ->assertSee('https://zalo.me/0905111222', escape: false);
});
