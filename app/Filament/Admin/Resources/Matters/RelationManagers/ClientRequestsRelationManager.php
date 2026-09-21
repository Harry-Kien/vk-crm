<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Portal\OpenClientRequest;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Filament\Portal\Pages\MyRequests;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Tab "Yêu cầu từ khách" (SPEC §7.2) — **hộp thư của vụ việc**, đầu VĂN PHÒNG của cuộc trao đổi.
 *
 * Đầu kia là {@see MyRequests}. Hai đầu được dựng trong cùng một task
 * vì tiêu chí SPEC §14 mục 4 đòi khách **nhận được phản hồi**: một yêu cầu gửi đi mà không ai
 * trong văn phòng nhìn thấy thì tiêu chí đó không chứng minh được bằng bất cứ cách nào.
 *
 * **Lớp này không có một dòng nghiệp vụ nào.** Mọi lần ghi đi qua {@see ReplyToClientRequest}
 * hoặc {@see TriageClientRequest} (CLAUDE.md: nghiệp vụ chỉ ở `app/Actions/`). Những gì ở đây
 * là: cột nào hiện, nút nào hiện cho ai, và một lời từ chối của Action đến được mắt người dùng
 * bằng tiếng Việt thay vì thành trang 500 — xem {@see ReportsActionFailures}.
 *
 * # Phạm vi: `ScopesToVisibleMatters`, như mọi relation manager khác của M3/M4
 *
 * Một vụ việc `restricted` chỉ hiện cho luật sư phụ trách và quản trị, và `whereHas('matter')`
 * loại luôn vụ đã xoá mềm nhờ global scope của `SoftDeletes`. Không có một câu `where` nào viết
 * tay ở đây.
 *
 * # Hai cổng khác nhau trên mỗi nút, cố ý tách rời
 *
 * `->authorize()` hỏi `Gate` về QUYỀN (`ClientRequestPolicy::update`, tức `MatterPolicy::update`
 * — nên **kế toán không có `matter.update` thì không thấy nút nào ở đây**); `->visible()` hỏi về
 * TRẠNG THÁI bản ghi. Hai câu hỏi khác nhau, và Action cũng tách chúng ra đúng như vậy. Không
 * cổng nào ở đây là cổng thật: cả hai Action tự hỏi lại tất cả và không tin màn hình đã lọc.
 * Cùng thành ngữ {@see ChecklistRelationManager} dùng cho ba nút của nó.
 *
 * # Trạng thái đọc như thế nào ở hai phía
 *
 * Bảng này dùng nhãn NGẮN của `lang/vi/enums.php` ("Mới", "Đang xử lý", "Đã trả lời", "Đã đóng")
 * — từ vựng làm việc của văn phòng, đọc lướt được trong một cột. Cổng khách hàng dùng một CÂU
 * cho mỗi trạng thái (`requests.portal.status.*`). Đó không phải hai bản dịch của cùng một thứ:
 * hai bên bàn cần biết hai điều khác nhau về cùng một dòng dữ liệu, và câu chuyện đó được kể đủ
 * ở đầu `lang/vi/requests.php`.
 *
 * # Không có nút "mở một yêu cầu mới" ở đây, và đó là một quyết định
 *
 * `client_user_id` là `NOT NULL` (SPEC §4.14) và SPEC §7.2 giao cho văn phòng đúng bốn động từ:
 * nhận, đổi trạng thái, gán người xử lý, trả lời. Một hàng do nhân sự tạo ra là một câu hỏi văn
 * phòng tự đặt cho mình rồi tự trả lời, và trong cùng một hộp thư nó không phân biệt được với
 * câu hỏi thật của khách. Lý lẽ đầy đủ ở docblock {@see OpenClientRequest}.
 */
class ClientRequestsRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'clientRequests';

    /** Bí danh của mốc "lần trao đổi gần nhất" — xem {@see self::table()}. */
    private const LAST_REPLY_AT_ALIAS = 'last_reply_at';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('requests.tab.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->emptyStateHeading(__('requests.tab.empty_state'))
            ->columns([
                TextColumn::make('subject')
                    ->label(__('requests.tab.columns.subject'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('clientUser.name')
                    ->label(__('requests.tab.columns.client_user')),
                TextColumn::make('status')
                    ->label(__('requests.tab.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (ClientRequestStatus $state): string => $state->label())
                    ->color(fn (ClientRequestStatus $state): string => static::statusColor($state)),
                TextColumn::make('assignee.name')
                    ->label(__('requests.tab.columns.assignee'))
                    ->placeholder(__('requests.tab.unassigned')),
                TextColumn::make('created_at')
                    ->label(__('requests.tab.columns.created_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make(self::LAST_REPLY_AT_ALIAS)
                    ->label(__('requests.tab.columns.last_activity'))
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('replies_count')
                    ->label(__('requests.tab.columns.replies_count'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Mới nhất trên cùng theo lúc KHÁCH GỬI, không theo lần trao đổi gần nhất: câu trả
            // lời của chính văn phòng đẩy một luồng lên đầu là một hộp thư sắp theo việc mình vừa
            // làm, không theo việc còn phải làm. `last_reply_at` vẫn là một cột bấm sắp được cho
            // ai muốn đọc theo dòng thời gian trao đổi.
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                $this->replyAction(),
                $this->assignAction(),
                $this->changeStatusAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with([
                    'clientUser',
                    'assignee',
                    'replies' => fn (HasMany $replies): HasMany => $replies->orderBy('created_at'),
                ])
                ->withCount('replies')
                ->withMax('replies as '.self::LAST_REPLY_AT_ALIAS, 'created_at'));
    }

    /** Màu badge của từng trạng thái. Chỉ hiển thị — không luật nghiệp vụ nào đọc nó. */
    public static function statusColor(ClientRequestStatus $status): string
    {
        return match ($status) {
            ClientRequestStatus::New => 'danger',
            ClientRequestStatus::InProgress => 'warning',
            ClientRequestStatus::Answered => 'success',
            ClientRequestStatus::Closed => 'gray',
        };
    }

    /**
     * **Cuộc trao đổi được vẽ ra ngay trong modal trả lời**, không phải sau một cái nút "xem" thứ
     * hai: người đang viết câu trả lời phải đọc lại được nguyên văn thứ khách đã hỏi, và bắt họ
     * mở hai modal để làm một việc là cách chắc chắn nhất để câu trả lời viết ra lệch với câu
     * hỏi.
     *
     * Ô nhập tên là `content` — TRẦN, trùng khoá mà `ReplyToClientRequest` gắn vào
     * `ValidationException` của nó, nên {@see ReportsActionFailures} dịch được sang state path
     * thật của modal và câu lỗi hiện đúng dưới ô.
     */
    private function replyAction(): Action
    {
        return Action::make('reply')
            ->label(__('requests.tab.actions.reply'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('primary')
            ->modalHeading(__('requests.tab.actions.reply_heading'))
            ->modalSubmitActionLabel(__('requests.tab.actions.reply_submit'))
            ->modalDescription(fn (ClientRequest $record): HtmlString => static::renderThread($record))
            ->authorize(fn (ClientRequest $record): bool => Gate::allows('update', $record))
            // Cổng TRẠNG THÁI, chép từ `ClientRequestNotOpen::accepts()`: một cuộc trao đổi đã
            // đóng không nhận thêm chữ nào, kể cả của văn phòng. Nút "Đổi trạng thái" ngay cạnh
            // là đường mở lại nó.
            ->visible(fn (ClientRequest $record): bool => $record->status !== ClientRequestStatus::Closed)
            ->schema([
                Textarea::make('content')
                    ->label(__('requests.tab.fields.content'))
                    ->helperText(__('requests.tab.fields.content_help'))
                    ->rows(5)
                    ->columnSpanFull()
                    ->required(),
            ])
            ->successNotificationTitle(__('requests.tab.actions.reply_success'))
            ->action(fn (Action $action, ClientRequest $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReplyToClientRequest::class)->handle(
                    $record,
                    Auth::user(),
                    $data['content'] ?? '',
                ),
            ));
    }

    /**
     * "Nhận" và "gán người xử lý" của SPEC §7.2 là MỘT nút: một yêu cầu được nhận là một yêu cầu
     * đã có người đứng tên — xem docblock {@see TriageClientRequest}.
     *
     * Ô chọn chỉ liệt kê **đội ngũ của vụ việc** (`matter_user`, SPEC §4.7), lấy bằng
     * `pluck('name', 'id')` — hai cột, nên `email`, `phone` và `bar_number` của đồng nghiệp không
     * đi vào HTML của một ô `<select>`. Danh sách này là một tiện ích, không phải cổng: Action
     * hỏi lại `MatterPolicy::update` **trên người được chọn** và từ chối bằng một câu gắn vào
     * chính ô này nếu người đó không mở được hồ sơ.
     */
    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('requests.tab.actions.assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading(__('requests.tab.actions.assign_heading'))
            ->modalSubmitActionLabel(__('requests.tab.actions.assign_submit'))
            ->authorize(fn (ClientRequest $record): bool => Gate::allows('update', $record))
            ->fillForm(fn (ClientRequest $record): array => ['assigned_to' => $record->assigned_to])
            ->schema([
                Select::make('assigned_to')
                    ->label(__('requests.tab.fields.assignee'))
                    ->helperText(__('requests.tab.fields.assignee_help'))
                    ->options(fn (): array => $this->assignableUsers())
                    // Để trống là "gỡ người đang giữ ra", một việc hợp lệ — nên ô này KHÔNG
                    // `required()`.
                    ->placeholder(__('requests.tab.unassigned'))
                    ->native(false),
            ])
            ->successNotificationTitle(__('requests.tab.actions.assign_success'))
            ->action(fn (Action $action, ClientRequest $record, array $data) => $this->runAction(
                $action,
                fn () => app(TriageClientRequest::class)->assign(
                    $record,
                    Auth::user(),
                    filled($data['assigned_to'] ?? null)
                        ? User::query()->find($data['assigned_to'])
                        : null,
                ),
            ));
    }

    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('requests.tab.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->modalHeading(__('requests.tab.actions.change_status_heading'))
            ->modalSubmitActionLabel(__('requests.tab.actions.change_status_submit'))
            ->authorize(fn (ClientRequest $record): bool => Gate::allows('update', $record))
            ->fillForm(fn (ClientRequest $record): array => ['status' => $record->status->value])
            ->schema([
                Select::make('status')
                    ->label(__('requests.tab.fields.status'))
                    ->options(fn (): array => collect(ClientRequestStatus::cases())
                        ->mapWithKeys(fn (ClientRequestStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->required()
                    ->native(false),
            ])
            ->successNotificationTitle(__('requests.tab.actions.change_status_success'))
            ->action(fn (Action $action, ClientRequest $record, array $data) => $this->runAction(
                $action,
                fn () => app(TriageClientRequest::class)->setStatus(
                    $record,
                    Auth::user(),
                    ClientRequestStatus::from($data['status']),
                ),
            ));
    }

    /**
     * Cuộc trao đổi cho tới lúc này, dựng thành HTML.
     *
     * **Kiểu dáng viết thẳng bằng `style=`, không bằng lớp Tailwind.** Không có bước dựng CSS
     * trong dự án (CLAUDE.md): panel nạp `theme.css` đã biên dịch sẵn của Filament, và tệp đó chỉ
     * chứa các lớp `fi-*` — một lớp tiện ích viết tay ở đây KHÔNG TÔ GÌ CẢ, và bản M3 của
     * `StageLogsRelationManager` mất hai milestone với đúng lỗi đó. Màu lấy bằng `color-mix` trên
     * biến của Filament để một luật phủ đúng cả nền sáng lẫn nền tối.
     *
     * `e()` trên mọi giá trị: nội dung ở đây do KHÁCH HÀNG gõ vào, tức do người ngoài văn phòng,
     * và nó được ghép thành HTML thô. Đây là chỗ duy nhất trong task này làm việc đó.
     *
     * Tên người lấy qua {@see self::authorNames()} — `pluck('name', 'id')` — chứ không qua
     * `$reply->author`: quan hệ đó là một `MorphTo` không scope trả về nguyên hàng. Ở panel nội
     * bộ việc đó không phải một lỗ hổng như trên cổng khách, nhưng cùng một thói quen ở hai nơi
     * là cách để thói quen đúng sống sót lần sửa sau.
     */
    public static function renderThread(ClientRequest $request): HtmlString
    {
        $names = static::authorNames($request);
        $clientMorph = (new ClientUser)->getMorphClass();

        $blocks = [sprintf(
            '<div style="border-radius:0.375rem;padding:0.5rem;margin-top:0.5rem;'
            .'border:1px solid color-mix(in srgb, var(--gray-500) 35%%, transparent)">'
            .'<span style="font-weight:600">%s</span>'
            .'<p style="margin-top:0.25rem;white-space:pre-line">%s</p></div>',
            e(__('requests.tab.thread.client_said', [
                'name' => $names['client'][$request->client_user_id] ?? __('requests.tab.columns.client_user'),
                'at' => $request->created_at->format('H:i d/m/Y'),
            ])),
            e((string) $request->content),
        )];

        foreach ($request->replies as $reply) {
            $fromClient = $reply->author_type === $clientMorph;

            $blocks[] = sprintf(
                '<div style="border-radius:0.375rem;padding:0.5rem;margin-top:0.5rem;%s">'
                .'<span style="font-weight:600">%s</span>'
                .'<p style="margin-top:0.25rem;white-space:pre-line">%s</p></div>',
                $fromClient
                    ? 'border:1px solid color-mix(in srgb, var(--gray-500) 35%, transparent)'
                    : 'background-color:color-mix(in srgb, var(--primary-500) 12%, transparent)',
                e($fromClient
                    ? __('requests.tab.thread.client_said', [
                        'name' => $names['client'][$reply->author_id] ?? __('requests.tab.columns.client_user'),
                        'at' => $reply->created_at->format('H:i d/m/Y'),
                    ])
                    : __('requests.tab.thread.office_said', [
                        'name' => $names['staff'][$reply->author_id] ?? __('requests.tab.thread.office_unknown'),
                        'at' => $reply->created_at->format('H:i d/m/Y'),
                    ])),
                e((string) $reply->content),
            );
        }

        return new HtmlString(
            '<p style="font-weight:600">'.e(__('requests.tab.thread.heading')).'</p>'.implode('', $blocks)
        );
    }

    /**
     * Tên người viết, **chỉ tên**, hai truy vấn chiếu cột. `withTrashed()` ở cả hai: một luật sư
     * đã nghỉ việc và một tài khoản khách đã bị vô hiệu hoá vẫn phải hiện tên, vì câu họ viết
     * nằm trong lịch sử và một dòng "không rõ ai" làm người đọc mất tin vào cả cuộc trao đổi.
     *
     * @return array{staff: Collection<int, string>, client: Collection<int, string>}
     */
    private static function authorNames(ClientRequest $request): array
    {
        $staffMorph = (new User)->getMorphClass();

        $staffIds = $request->replies->where('author_type', $staffMorph)->pluck('author_id')->filter()->unique();
        $clientIds = $request->replies->where('author_type', '!=', $staffMorph)->pluck('author_id')
            ->push($request->client_user_id)->filter()->unique();

        return [
            'staff' => $staffIds->isEmpty()
                ? collect()
                : User::withTrashed()->whereKey($staffIds)->pluck('name', 'id'),
            'client' => $clientIds->isEmpty()
                ? collect()
                : ClientUser::withTrashed()->whereKey($clientIds)->pluck('name', 'id'),
        ];
    }

    /**
     * Đội ngũ của vụ việc, tên và id. Xem docblock {@see self::assignAction()} cho lý do danh
     * sách hẹp lại đúng ở đội ngũ chứ không mở ra toàn bộ nhân sự.
     *
     * @return array<int, string>
     */
    private function assignableUsers(): array
    {
        return $this->getOwnerRecord()->team()->pluck('name', 'users.id')->all();
    }
}
