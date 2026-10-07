<?php

namespace Tests\Support;

use App\Enums\ClientType;
use App\Enums\DocumentGroup;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\Login as StaffLogin;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient as CreateClientPage;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Portal\Pages\Auth\Login as PortalLogin;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use PragmaRX\Google2FAQRCode\Google2FA;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Các luồng THẬT mà SPEC §10.5 (kế hoạch M8 Task 4) đòi quét sau khi chạy — dùng chung cho phép
 * quét CSDL + log (`tests/Feature/Security/PersonalDataSpec105Test.php`) và phép quét bản sao lưu
 * thật (`tests/Feature/Backup/BackupPersonalDataScanTest.php`), để hai phép quét nhìn cùng một
 * lượng dữ liệu.
 *
 * Mọi bước đi qua MÀN HÌNH (Livewire) hoặc HTTP hoặc lệnh Artisan thật — không gọi thẳng Action:
 * câu hỏi là "sau khi người dùng làm những việc này, số CCCD nằm ở đâu", và một Action gọi thẳng
 * bỏ qua đúng những tầng (form, notification, hàng đợi, phiên) có thể làm lộ nó.
 *
 * # Lưu trữ giống máy chủ thật, không giống bộ test
 *
 * `phpunit.xml` đặt cache `array`, hàng đợi `sync`, phiên `array`, thư `array` — cả bốn đều giữ dữ
 * liệu trong bộ nhớ, tức NGOÀI tầm quét. Máy chủ thật dùng `database` cho cả ba cái đầu
 * (`.env.example`, "Bắt buộc dùng database cho session/queue/cache") và SMTP cho thư. Nên trước khi
 * chạy luồng, {@see self::useProductionStorage()} chuyển cache/hàng đợi/phiên sang `database`, thư
 * sang mailer `log`, và mọi log sang MỘT tệp tạm riêng của lượt chạy (không phải
 * `storage/logs/laravel.log`, nơi các worker song song cùng ghi). Bộ đếm `RateLimiter` đã dựng lúc
 * khởi động với store `array` được trỏ lại sang store `database` (giữ nguyên các bộ đếm có tên đã
 * đăng ký), nên mọi khoá đếm đăng nhập/tra cứu cũng nằm trong bảng `cache` bị quét.
 *
 * # Các giá trị lính canh
 *
 * Mỗi hằng dưới đây là một giá trị chỉ lượt chạy này dùng, và mọi dạng của nó phải KHÔNG xuất hiện
 * ở bất kỳ đâu ngoài cột đã mã hoá. Chỉ gồm chữ số, chữ thường và dấu gạch: không có ký tự nào bị
 * JSON/HTML/SQL thoát thành một dạng khác mà máy quét phải đoán.
 */
final class SensitiveDataFlows
{
    /** Số CCCD của khách hàng lúc tạo hồ sơ — gõ có dấu cách. */
    public const CLIENT_ID_NUMBER_TYPED = '079 188 123 456';

    /** Cùng số đó, gõ có dấu chấm ở ô tra cứu khi mở vụ việc. */
    public const CLIENT_ID_NUMBER_LOOKUP = '079.188.123.456';

    /** Số CCCD mới của khách hàng sau khi sửa hồ sơ — gõ có dấu gạch. */
    public const CLIENT_ID_NUMBER_EDITED = '079-188-654-321';

    /** Số CCCD của bên ĐỐI LẬP: dạng lưu duy nhất của nó là `matter_parties.id_number_hash`. */
    public const OPPOSING_ID_NUMBER_TYPED = '001 199 007 788';

    public const STAFF_PASSWORD = 'mat-khau-nhan-su-lk7q2m';

    public const WRONG_STAFF_PASSWORD = 'mat-khau-sai-o-o-mat-khau-q2w9e';

    public const TYPED_INTO_STAFF_EMAIL = 'mat-khau-go-nham-o-email-a9x3v';

    public const PORTAL_INITIAL_PASSWORD = 'mat-khau-tam-cong-khach-r5t8y';

    public const TYPED_INTO_PORTAL_EMAIL = 'mat-khau-khach-go-nham-email-z4p8';

    /** Tệp log riêng của lượt chạy — xem docblock lớp. */
    public readonly string $logPath;

    public ?string $twoFactorSecret = null;

    /** @var list<string> */
    public array $recoveryCodes = [];

    public ?Client $client = null;

    public ?Matter $matter = null;

    public ?User $enrolledStaff = null;

    public function __construct(private readonly TestCase $test)
    {
        $logPath = sys_get_temp_dir().'/vkcrm-personal-data-scan-'.getmypid().'-'.bin2hex(random_bytes(6)).'.log';
        $this->logPath = $logPath;

        // Tệp log tạm (chứa thân thư, mã vụ việc) bị xoá khi test kết thúc, đỗ hay trượt — không để
        // lại trong thư mục tạm của máy chạy CI.
        (fn () => $this->beforeApplicationDestroyed(function () use ($logPath): void {
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }))->call($test);
    }

    public static function for(TestCase $test): self
    {
        return new self($test);
    }

    public function useProductionStorage(): self
    {
        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
            'session.driver' => 'database',
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'personal_data_scan',
            'logging.default' => 'personal_data_scan',
            'logging.channels.personal_data_scan' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'level' => 'debug',
                'replace_placeholders' => true,
            ],
        ]);

        Mail::purge('log');

        // Trỏ bộ đếm đã dựng sẵn sang store `database`, giữ nguyên các bộ đếm có tên đã đăng ký lúc
        // khởi động (`document-download`) — `forgetInstance()` sẽ làm mất chúng.
        $limiter = app(RateLimiter::class);
        $store = Cache::store('database');
        (fn () => $this->cache = $store)->call($limiter);

        return $this;
    }

    /** Chạy toàn bộ các luồng; trả về chính đối tượng này, mang các giá trị sinh ra lúc chạy. */
    public function run(): self
    {
        $this->test->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->withRole(Role::Admin)->create();
        $assistant = User::factory()->withRole(Role::Assistant)->create();
        $lawyer = User::factory()->withRole(Role::Lawyer)->create();
        $type = MatterType::factory()->withStages()->create();

        $this->createClientThroughScreen($assistant);
        $this->openMatterThroughLookup($lawyer, $type);
        $this->uploadAndDownloadDocument($lawyer);
        $this->createPortalAccountThroughScreen($assistant);
        $this->editClientIdentityThroughScreen($admin);
        $this->drainQueue();
        $this->publishStageUpdate($lawyer, 'Văn phòng đã nộp đơn khởi kiện tới toà án có thẩm quyền.');
        $this->drainQueue();
        $this->failStageUpdateMailForGood($lawyer);
        $this->enrolTwoFactorThroughScreen();
        $this->signInWithAppCodeAndRecoveryCode();
        $this->failSignInsTypingSecretsIntoTheWrongBox();
        $this->unlockAndResetTwoFactorThroughScreens($admin);
        $this->runPreflightAsProduction();
        $this->persistSessionsOverHttp($admin);

        return $this;
    }

    private function createClientThroughScreen(User $assistant): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($assistant, 'web');

        Livewire::test(CreateClientPage::class)
            ->fillForm([
                'type' => ClientType::Individual->value,
                'name' => 'Khách hàng lính canh mục 10.5',
                'id_number' => self::CLIENT_ID_NUMBER_TYPED,
                'phone' => '0909 111 222',
                'email' => 'linh-canh-muc-10-5@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->client = Client::query()->where('name', 'Khách hàng lính canh mục 10.5')->sole();
    }

    private function openMatterThroughLookup(User $lawyer, MatterType $type): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($lawyer, 'web');

        Livewire::test(CreateMatter::class)
            ->fillForm(['client_lookup_identifier' => self::CLIENT_ID_NUMBER_LOOKUP])
            // `afterStateUpdated()` không tự chạy dưới `fillForm()` — gọi đúng phương thức ô đó gọi.
            ->call('lookupClient', self::CLIENT_ID_NUMBER_LOOKUP)
            ->fillForm([
                'client_role' => PartyRole::Plaintiff->value,
                'matter_type_id' => $type->id,
                'title' => 'Vụ việc lính canh mục 10.5',
                'lead_lawyer_id' => $lawyer->id,
                'summary_for_client' => 'Tóm tắt gửi khách hàng.',
                'is_published_to_portal' => true,
                'other_parties' => [[
                    'role' => PartyRole::Defendant->value,
                    'name' => 'Bên đối lập lính canh',
                    'id_number' => self::OPPOSING_ID_NUMBER_TYPED,
                    'phone' => '0911 222 333',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->matter = Matter::query()->where('title', 'Vụ việc lính canh mục 10.5')->sole();
    }

    /**
     * Một tệp hồ sơ qua ô tải lên của nhân sự, rồi tải về qua route có chữ ký — bảng `media`,
     * `documents`, `document_downloads` có dòng thật, và kho tệp `private` (giả) có tệp thật cho
     * phép quét bản sao lưu.
     */
    private function uploadAndDownloadDocument(User $lawyer): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($lawyer, 'web');

        config(['media-library.prefix' => 'muc-10-5-'.Str::lower(Str::random(12))]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $this->matter->fresh(),
            'pageClass' => ViewMatter::class,
        ])->callAction(TestAction::make('upload')->table(), data: [
            'file' => UploadedFile::fake()->createWithContent(
                'thong-bao-thu-ly.pdf',
                "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
            ),
            'title' => 'Thông báo thụ lý (tài liệu lính canh)',
            'group' => DocumentGroup::Authority->value,
            'issued_at' => today()->toDateString(),
        ])->assertHasNoActionErrors();

        $document = $this->matter->documents()->sole();

        $this->test->get($document->downloadUrlFor($lawyer))->assertOk();
    }

    private function createPortalAccountThroughScreen(User $assistant): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($assistant, 'web');

        Livewire::test(CreateClientUser::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'name' => 'Tài khoản cổng lính canh',
                'email' => 'cong-khach-linh-canh@example.test',
                'password' => self::PORTAL_INITIAL_PASSWORD,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Thư tiến độ chỉ tới tài khoản đã kích hoạt (R12) — khách tự đổi mật khẩu lần đầu ở cổng.
        // Luồng đó không phải đối tượng của phép quét này; ghi thẳng hai cột nó sẽ ghi.
        ClientUser::query()->where('email', 'cong-khach-linh-canh@example.test')->sole()
            ->forceFill(['must_change_password' => false, 'activated_at' => now()])
            ->save();
    }

    private function editClientIdentityThroughScreen(User $admin): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($admin, 'web');

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->fillForm(['id_number' => self::CLIENT_ID_NUMBER_EDITED])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    private function publishStageUpdate(User $lawyer, string $publicContent): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($lawyer, 'web');

        Livewire::test(StageLogsRelationManager::class, [
            'ownerRecord' => $this->matter->fresh(),
            'pageClass' => ViewMatter::class,
        ])->callTableAction('addUpdate', data: [
            'occurred_at' => today()->toDateString(),
            'internal_note' => null,
            'public_content' => $publicContent,
            'next_step' => null,
            'client_action' => null,
            'expected_next_update_at' => null,
            'publish' => true,
        ]);
    }

    /** Một job cố ý thất bại tới cùng: SMTP chết suốt năm lượt thử của thư tiến độ. */
    private function failStageUpdateMailForGood(User $lawyer): void
    {
        config()->set('mail.mailers.personal_data_scan_failing', ['transport' => 'personal_data_scan_failing']);
        Mail::extend('personal_data_scan_failing', fn (): TransportInterface => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
            {
                throw new TransportException('Máy chủ SMTP giả lập không trả lời (phép quét mục 10.5)');
            }

            public function __toString(): string
            {
                return 'personal-data-scan-failing://';
            }
        });
        config(['mail.default' => 'personal_data_scan_failing']);

        $this->publishStageUpdate($lawyer, 'Toà án đã thụ lý và đang xem xét hồ sơ khởi kiện của khách hàng.');

        // $tries = 5 của listener: 4 lần thả lại (60/300/900/3600 giây), lần thứ 5 hỏng hẳn.
        foreach ([0, 61, 301, 901, 3601] as $delay) {
            if ($delay > 0) {
                $this->test->travel($delay)->seconds();
            }

            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        }

        config(['mail.default' => 'log']);
    }

    private function enrolTwoFactorThroughScreen(): void
    {
        $this->enrolledStaff = User::factory()->withoutTwoFactor()->withRole(Role::Lawyer)->create([
            'password' => self::STAFF_PASSWORD,
        ]);

        Filament::setCurrentPanel('admin');
        $this->test->actingAs($this->enrolledStaff, 'web');

        $setUp = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
            ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent(true, 'content'));

        // Secret và mã khôi phục mà modal đang hiện cho người dùng — lấy từ đúng đối số mã hoá mà
        // `SetUpAppAuthenticationAction` gắn vào action đang mở.
        $issued = decrypt($setUp->instance()->mountedActions[0]['arguments']['encrypted']);
        $this->twoFactorSecret = $issued['secret'];
        $this->recoveryCodes = array_values($issued['recoveryCodes']);

        // Filament ≥ 5.8.2 (CVE-2026-104181) hỏi lại mật khẩu hiện tại khi bật xác thực ứng dụng.
        $setUp->fillForm([
            'code' => app(Google2FA::class)->getCurrentOtp($this->twoFactorSecret),
            'password' => self::STAFF_PASSWORD,
        ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        auth('web')->logout();
    }

    private function signInWithAppCodeAndRecoveryCode(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(StaffLogin::class)
            ->set('data.email', $this->enrolledStaff->email)
            ->set('data.password', self::STAFF_PASSWORD)
            ->call('authenticate')
            ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($this->twoFactorSecret))
            ->call('authenticate')
            ->assertHasNoErrors();

        auth('web')->logout();

        Livewire::test(StaffLogin::class)
            ->set('data.email', $this->enrolledStaff->email)
            ->set('data.password', self::STAFF_PASSWORD)
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $this->recoveryCodes[0])
            ->call('authenticate')
            ->assertHasNoErrors();

        auth('web')->logout();
    }

    private function failSignInsTypingSecretsIntoTheWrongBox(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(StaffLogin::class)
            ->set('data.email', $this->enrolledStaff->email)
            ->set('data.password', self::WRONG_STAFF_PASSWORD)
            ->call('authenticate')
            ->assertHasErrors();

        Livewire::test(StaffLogin::class)
            ->set('data.email', self::TYPED_INTO_STAFF_EMAIL)
            ->set('data.password', self::STAFF_PASSWORD)
            ->call('authenticate')
            ->assertHasErrors();

        Filament::setCurrentPanel('portal');

        Livewire::test(PortalLogin::class)
            ->set('data.email', self::TYPED_INTO_PORTAL_EMAIL)
            ->set('data.password', self::PORTAL_INITIAL_PASSWORD)
            ->call('authenticate')
            ->assertHasErrors();

        Livewire::test(PortalLogin::class)
            ->set('data.email', 'cong-khach-linh-canh@example.test')
            ->set('data.password', self::WRONG_STAFF_PASSWORD)
            ->call('authenticate')
            ->assertHasErrors();

        Filament::setCurrentPanel('admin');
    }

    /**
     * Mở khoá đăng nhập của người vừa gõ sai, và "Đặt lại 2FA" trên HAI nhân sự KHÁC (màn hình và
     * lệnh `vkcrm:reset-2fa`) — người đã cài 2FA ở trên giữ nguyên secret, để test đối chứng được
     * rằng cột mã hoá của họ CÓ giữ đúng secret mà phép quét đang tìm.
     */
    private function unlockAndResetTwoFactorThroughScreens(User $admin): void
    {
        Filament::setCurrentPanel('admin');
        $this->test->actingAs($admin, 'web');

        Livewire::test(EditUser::class, ['record' => $this->enrolledStaff->getRouteKey()])
            ->callAction('unlockLogin')
            ->assertHasNoActionErrors();

        $lostPhone = User::factory()->withRole(Role::Lawyer)->create();

        Livewire::test(EditUser::class, ['record' => $lostPhone->getRouteKey()])
            ->callAction('resetTwoFactor')
            ->assertHasNoActionErrors();

        $lostPhoneToo = User::factory()->withRole(Role::Assistant)->create();

        Assert::assertSame(0, Artisan::call('vkcrm:reset-2fa', ['email' => $lostPhoneToo->email]), Artisan::output());
    }

    /** `vkcrm:preflight` dưới cấu hình production giả lập — máy chủ web không lộ `storage/`. */
    private function runPreflightAsProduction(): void
    {
        $saved = config()->getMany(['app.env', 'app.debug', 'app.url', 'trustedproxy.proxies', 'vkcrm.heartbeat_url', 'session.secure']);

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'http://preflight-muc-10-5.example.test',
            'trustedproxy.proxies' => '10.0.0.1',
            'vkcrm.heartbeat_url' => 'https://heartbeat.example.test/ping',
            'session.secure' => true,
        ]);

        Http::fake(fn () => Http::response('not found', 404));
        // Dòng `mariadb-dump` của preflight hỏi `command -v` qua facade `Process` — cùng cách giả
        // của `PreflightCommandTest`, và bắt buộc trong `tests/Feature/Backup` (chặn tiến trình lạc).
        Process::fake(['command -v *' => Process::result(exitCode: 0)]);

        Artisan::call('vkcrm:preflight');

        config($saved);
    }

    /**
     * Phiên chỉ được ghi xuống bảng `sessions` ở cuối một request HTTP thật (middleware
     * `StartSession`) — lượt gọi Livewire của bộ test tắt middleware. Hai request thật đẩy toàn
     * bộ trạng thái phiên tích luỹ từ các bước trên (thông báo flash, URL định hướng…) xuống CSDL.
     */
    private function persistSessionsOverHttp(User $admin): void
    {
        auth('web')->logout();

        $this->test->get('/portal/login')->assertOk();

        $this->test->actingAs($admin, 'web')->get('/admin/clients')->assertOk();

        Assert::assertGreaterThan(0, DB::table('sessions')->count());
    }

    /** Rút hàng đợi `database` bằng worker THẬT, từng job một, tới khi không còn job nào sẵn sàng. */
    private function drainQueue(): void
    {
        for ($round = 0; $round < 20 && DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->exists(); $round++) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        }
    }
}
