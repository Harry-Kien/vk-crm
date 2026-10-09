<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Mcp\AcknowledgeAiPolicy;
use App\Actions\Mcp\AiConnection;
use App\Actions\Mcp\DisconnectAiConnections;
use App\Actions\Mcp\ListAiConnections;
use App\Enums\McpAccessRefusal;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\AiAcknowledgement;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpEndpoint;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Trang "Kết nối AI của tôi" của nhân sự (M11 Task 15; R2, R8, R12 mục 1). Tên lớp tiếng Anh (kế
 * hoạch gọi nó `KetNoiAiCuaToi` — khoảng lệch D9); slug là {@see McpEndpoint::MY_AI_CONNECTIONS_SLUG},
 * đúng đường dẫn mà màn hình đồng ý OAuth (Task 4) trỏ tới từ trước khi trang này có.
 *
 * Bốn khối, đều về CHÍNH người đang đăng nhập — trang không bao giờ nhận id người dùng từ trình duyệt:
 *
 *  1. Chế độ của tôi (`users.ai_access`) và, khi chưa dùng được, lý do đầu tiên theo R2
 *     ({@see McpAccess::refusal()}, cùng câu với màn hình đồng ý).
 *  2. Chính sách dùng AI phiên bản hiện hành và ô cam kết KHÔNG đánh dấu sẵn ({@see AcknowledgeAiPolicy},
 *     IP và user agent đọc từ request của chính lần bấm). Đã cam kết đúng phiên bản thì ô biến mất,
 *     thay bằng ngày đã cam kết; chính sách đổi phiên bản thì ô hiện lại.
 *  3. URL MCP để dán vào client ({@see McpEndpoint::resource()}) và đường tới hướng dẫn (Task 16).
 *  4. Kết nối của tôi ({@see ListAiConnections::forUser()} với actor = target = chính mình) kèm nút tự
 *     thu hồi từng dòng [DC:144] ({@see DisconnectAiConnections}: chỉ token của chính người bấm cho
 *     client đó — gửi id client của người khác lên đây không chạm được gì của người đó).
 *
 * # Cổng: nhân sự đang hoạt động có `matter.view`, hỏi ở MỌI request
 *
 * Như trang quản trị: {@see self::boot()} 404 ở mount và mọi request cập nhật Livewire; mọi hành
 * động thật hỏi lại ({@see self::guard()}); Action hỏi `Gate` lần nữa. Kế toán (không `matter.view`)
 * không dùng được AI (R2), nên không có trang này.
 */
class MyAiConnections extends Page
{
    use ReportsActionFailures;

    protected string $view = 'filament.admin.pages.my-ai-connections';

    protected static ?string $slug = McpEndpoint::MY_AI_CONNECTIONS_SLUG;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    /** @var array<string, mixed> trạng thái form cam kết */
    public array $acknowledgement = [];

    public static function getNavigationLabel(): string
    {
        return __('ai_connections.mine.navigation_label');
    }

    public function getTitle(): string
    {
        return __('ai_connections.mine.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active && McpAccess::canHold($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function mount(): void
    {
        $this->getSchema('acknowledgementForm')?->fill(['acknowledged' => false]);
    }

    public function acknowledgementForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('acknowledgement')
            ->components([
                Checkbox::make('acknowledged')
                    ->label(__('ai_connections.policy.checkbox'))
                    ->default(false),
            ]);
    }

    /**
     * Ghi lời cam kết qua {@see AcknowledgeAiPolicy}: chính người đang đăng nhập, ô do chính họ tích,
     * IP và user agent của request này. Lỗi "chưa tích" của Action gắn vào ô của form.
     */
    public function acknowledge(): void
    {
        $this->guard();

        try {
            app(AcknowledgeAiPolicy::class)->handle(
                $this->user(),
                ($this->acknowledgement['acknowledged'] ?? false) === true,
                request()->ip(),
                request()->userAgent(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["acknowledgement.{$field}" => $messages])
                ->all());
        }

        $this->getSchema('acknowledgementForm')?->fill(['acknowledged' => false]);

        Notification::make()->title(__('ai_connections.policy.saved'))->success()->send();
    }

    /** "Thu hồi" một kết nối của chính mình — đối số `client` là `oauth_clients.id` của dòng đó. */
    public function revokeConnectionAction(): Action
    {
        return Action::make('revokeConnection')
            ->label(__('ai_connections.actions.revoke'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('ai_connections.actions.revoke_heading'))
            ->modalDescription(__('ai_connections.actions.revoke_description'))
            ->action(function (Action $action, array $arguments): void {
                $this->guard();

                $clientId = $arguments['client'] ?? null;

                // Một dòng luôn mang id client; thiếu id KHÔNG được rơi về "thu hồi tất cả".
                abort_unless(is_string($clientId) && $clientId !== '', 404);

                $revoked = 0;
                $user = $this->user();

                $this->runAction($action, function () use ($user, $clientId, &$revoked): void {
                    $revoked = app(DisconnectAiConnections::class)->handle($user, $user, $clientId);
                });

                Notification::make()
                    ->title(__($revoked > 0 ? 'ai_connections.actions.revoked' : 'ai_connections.actions.nothing_revoked'))
                    ->success()
                    ->send();
            });
    }

    // ---------------------------------------------------------------------------------------------
    // Dữ liệu cho view.
    // ---------------------------------------------------------------------------------------------

    /**
     * Dữ liệu của view, tính ở mỗi lần render — mọi hàm đọc là `protected`, không gọi được từ trình
     * duyệt, và chỉ đọc về chính người đang đăng nhập.
     *
     * @return array{user: User, refusal: ?McpAccessRefusal, policyVersion: ?string, currentAcknowledgement: ?AiAcknowledgement, mcpUrl: string, connections: list<AiConnection>}
     */
    protected function getViewData(): array
    {
        $user = $this->user();
        $version = McpAccess::policyVersion();

        return [
            'user' => $user,
            'refusal' => McpAccess::refusal($user),
            'policyVersion' => $version,
            'currentAcknowledgement' => $version === null ? null : AiAcknowledgement::query()
                ->where('user_id', $user->getKey())
                ->where('policy_version', $version)
                ->first(),
            'mcpUrl' => McpEndpoint::resource(),
            'connections' => app(ListAiConnections::class)->forUser($user, $user),
        ];
    }

    protected function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }

    /** Hỏi lại cổng của trang ở đầu MỌI hành động thật, không tin vòng đời đã chạy. */
    private function guard(): void
    {
        abort_unless(static::canAccess(), 404);
    }
}
