<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Mcp\UseReplyDraft;
use App\Actions\Portal\OpenClientRequest;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\SetClientRequestStatusResult;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Filament\Admin\Resources\Matters\RelationManagers\Concerns\ManagesAiDrafts;
use App\Filament\Portal\Pages\MyRequests;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * # Phạm vi: cổng thật là `canViewForRecord()`, `ScopesToVisibleMatters` chỉ lọc HÀNG
 *
 * Hai thiết bị, và chúng làm hai việc khác nhau — bản đầu của docblock này gộp chúng làm một và
 * nói sai:
 *
 *  - {@see self::canViewForRecord()} quyết định tab này có TỒN TẠI cho người đang xem không. Đây
 *    là cổng, và nó hỏi `MatterPolicy::view` trên chính vụ việc chủ. Kế toán có
 *    `matter.viewAny` nhưng không có `matter.view` (SPEC §5: danh sách rút gọn, không nội dung
 *    hồ sơ), nên tab không tồn tại cho họ.
 *  - `ScopesToVisibleMatters` lọc các HÀNG trong bảng, và với một người có `matter.viewAny` nó
 *    **không lọc gì cả** — `Matter::scopeListableBy` trả về không ràng buộc cho họ ngay ở nhánh
 *    đầu. Đo được ở vòng rà soát 21/09/2026: đóng vai kế toán, component vẫn vẽ ra nguyên văn
 *    câu hỏi của khách và tên người gửi. Nó vẫn có việc thật — một yêu cầu của vụ việc khác
 *    không lọt vào bảng này, và một vụ `restricted` vẫn khép lại với luật sư ngoài đội ngũ — nó
 *    chỉ không phải thứ đang giữ kế toán ở ngoài.
 *
 * Trước khi có `canViewForRecord()`, thứ duy nhất chặn là `Gate` của TRANG cha (`ViewMatter`
 * trả 404) cộng việc Livewire không gắn component con khi trang cha không vẽ. Không phải một vụ
 * rò rỉ sống, nhưng một cổng duy nhất nằm ở một tầng khác là đúng hình dạng mà vòng rà soát M4
 * đã lên án, và ba nút bị ẩn không phải một lời khẳng định về việc ai ĐỌC được gì.
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
    use ManagesAiDrafts;
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'clientRequests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('requests.tab.title');
    }

    /**
     * Cổng của cả TAB — xem phần "Phạm vi" ở docblock lớp. `MatterPolicy::view` trên vụ việc
     * chủ, không một điều kiện nào viết lại: nội dung cuộc trao đổi với khách là nội dung hồ sơ,
     * nên ai đọc được hồ sơ thì đọc được nó, và không ai khác.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    /**
     * M11 Task 12: mặc định của Filament ({@see RelationManager::content()}), cộng khối "Nháp trả lời
     * từ AI (n)" trước hộp thư — nháp do tool `draft_request_reply` soạn, còn đang chờ, của mọi yêu
     * cầu thuộc vụ này. Chỉ người xem được nội dung vụ thấy khối (cùng cổng với cả tab).
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                $this->aiDraftsSection(
                    'ai_drafts.reply.heading',
                    'ai_drafts.reply.description',
                    $this->replyDraftCards(),
                    ['useReplyDraft', 'discardReplyDraft'],
                ),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    /**
     * "Mở nháp" trả lời (M11 Task 12): cùng modal với nút "Trả lời" ({@see self::replyAction()} — cả
     * cuộc trao đổi vẽ trên ô nhập, cùng câu báo theo trạng thái công bố của vụ), ô nội dung điền sẵn
     * nháp. Gửi đi qua {@see UseReplyDraft}: vẫn `ReplyToClientRequest` dưới tên người bấm, cộng việc
     * đánh dấu nháp đã dùng trong cùng transaction.
     *
     * Nháp đến từ đối số `draft` — xem `UseStageLogDraftAction` cho lý do và ba cổng. Ở đây
     * `visible()` hỏi: nháp thuộc một yêu cầu của vụ này, yêu cầu chưa đóng, và
     * `ClientRequestReplyPolicy::create` với yêu cầu đó (cổng `ReplyToClientRequest` hỏi).
     */
    public function useReplyDraftAction(): Action
    {
        return Action::make('useReplyDraft')
            ->label(__('ai_drafts.actions.open'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->size(Size::Small)
            ->modalHeading(__('ai_drafts.reply.open_heading'))
            ->modalSubmitActionLabel(__('requests.tab.actions.reply_submit'))
            ->modalDescription(fn (Action $action): ?HtmlString => ($draft = $this->replyDraft($action)) === null
                ? null
                : static::renderThread($draft->request))
            ->visible(function (Action $action): bool {
                $draft = $this->replyDraft($action);

                return $draft !== null
                    && $draft->request->status !== ClientRequestStatus::Closed
                    && Gate::allows('create', [ClientRequestReply::class, $draft->request]);
            })
            ->beforeFormFilled(function (Action $action): void {
                if (! $this->replyDraft($action)?->isPending()) {
                    Notification::make()->title(__('ai_drafts.not_pending'))->warning()->send();

                    $action->cancel();
                }
            })
            ->fillForm(fn (Action $action): array => ['content' => $this->replyDraft($action)?->content])
            ->schema([
                Textarea::make('content')
                    ->label(__('requests.tab.fields.content'))
                    ->helperText(__('requests.tab.fields.content_help'))
                    ->rows(5)
                    ->columnSpanFull()
                    ->required()
                    ->maxLength(ReplyToClientRequest::CONTENT_MAX),
            ])
            ->action(function (Action $action, array $data): void {
                $draft = $this->replyDraft($action);
                $isPublished = (bool) $this->getOwnerRecord()->is_published_to_portal;

                $this->runAction($action, function () use ($draft, $data, $isPublished): void {
                    if ($draft === null) {
                        throw new AuthorizationException(__('ai_drafts.unavailable'));
                    }

                    app(UseReplyDraft::class)->handle($draft, $draft->request, Auth::user(), $data['content'] ?? '');

                    Notification::make()
                        ->title($isPublished
                            ? __('requests.tab.actions.reply_success')
                            : __('requests.tab.actions.reply_success_hidden'))
                        ->success()
                        ->send();
                });
            });
    }

    /** "Bỏ nháp" trả lời — cổng `ClientRequestReplyPolicy::create`; yêu cầu đã đóng vẫn bỏ được. */
    public function discardReplyDraftAction(): Action
    {
        return $this->discardAiDraftAction(
            'discardReplyDraft',
            fn (Action $action): ?ClientRequestReplyDraft => $this->replyDraft($action),
            fn (ClientRequestReplyDraft $draft): bool => Gate::allows('create', [ClientRequestReply::class, $draft->request]),
        );
    }

    /**
     * Nháp mang id của đối số `draft`, CHỈ khi yêu cầu của nó thuộc vụ của trang (đang chờ hay không),
     * kèm yêu cầu và các lần trả lời (cho modal).
     */
    private function replyDraft(Action $action): ?ClientRequestReplyDraft
    {
        $id = static::draftIdArgument($action);

        if ($id === null) {
            return null;
        }

        return ClientRequestReplyDraft::query()
            ->whereKey($id)
            ->whereHas('request', fn (Builder $request): Builder => $request->where('matter_id', $this->getOwnerRecord()->getKey()))
            ->with(['request.replies' => fn (HasMany $replies): HasMany => $replies->orderBy('created_at')])
            ->first();
    }

    /**
     * Thẻ của các nháp trả lời ĐANG CHỜ của vụ, cũ nhất trước. Tiêu đề là tiêu đề yêu cầu — chữ KHÁCH
     * viết, view in nó đã thoát HTML.
     *
     * @return list<array{id: int, title: ?string, meta: string, fields: list<array{label: string, value: string, internal: bool}>, note: ?string}>
     */
    private function replyDraftCards(): array
    {
        $matter = $this->getOwnerRecord();

        if (! Gate::allows('view', $matter)) {
            return [];
        }

        return ClientRequestReplyDraft::query()
            ->whereHas('request', fn (Builder $request): Builder => $request->where('matter_id', $matter->getKey()))
            ->pending()
            ->with('request')
            ->oldest('id')
            ->get()
            ->map(fn (ClientRequestReplyDraft $draft): array => [
                'id' => $draft->getKey(),
                'title' => __('ai_drafts.reply.for_request', ['subject' => $draft->request->subject]),
                'meta' => $this->draftMeta($draft),
                'fields' => [static::draftField('requests.tab.fields.content', $draft->content)],
                'note' => $draft->request->status === ClientRequestStatus::Closed ? __('ai_drafts.reply.closed') : null,
            ])
            ->all();
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
                // Nạp kèm `withTrashed()` (xem `modifyQueryUsing` bên dưới, REQ-7): một tài
                // khoản khách đã bị xoá mềm vẫn phải hiện tên người gửi. Không có nó, cột đọc ra
                // `null` và bỏ trống đúng ô mà modal trả lời ({@see self::renderThread()}) vẫn vẽ
                // ra tên — cùng luồng, hai chỗ nói khác nhau về ai đã gửi nó.
                TextColumn::make('clientUser.name')
                    ->label(__('requests.tab.columns.client_user')),
                TextColumn::make('status')
                    ->label(__('requests.tab.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (ClientRequestStatus $state): string => $state->label())
                    ->color(fn (ClientRequestStatus $state): string => static::statusColor($state)),
                // Nạp kèm `withTrashed()` (xem `modifyQueryUsing` bên dưới): một luật sư đã nghỉ
                // việc vẫn phải hiện tên. Không có nó, cột đọc ra `null` và in "Chưa ai nhận"
                // trong khi `assigned_to` vẫn giữ nguyên id của họ — một cái bảng nói sai về
                // chính cột nó đang vẽ, và người đọc không có cách nào biết.
                //
                // `formatStateUsing` thêm dấu "đã nghỉ việc" (REQ-3, phần hiển thị — phần CHẶN đã
                // ở Task 4): tên hiện ra đúng, nhưng một cái tên trơn không nói được rằng người đó
                // không còn xử lý được, và cột "Trạng thái" bên cạnh vẫn im lặng về việc đó.
                TextColumn::make('assignee.name')
                    ->label(__('requests.tab.columns.assignee'))
                    ->placeholder(__('requests.tab.unassigned'))
                    ->formatStateUsing(fn (?string $state, ClientRequest $record): ?string => static::assigneeLabel($record, $state)),
                TextColumn::make('created_at')
                    ->label(__('requests.tab.columns.created_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('last_activity_at')
                    ->label(__('requests.tab.columns.last_activity'))
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('replies_count')
                    ->label(__('requests.tab.columns.replies_count'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Mới nhất trên cùng theo HOẠT ĐỘNG GẦN NHẤT, không theo lúc khách gửi (Task 18,
            // REQ-2 — đảo lại quyết định trước đó). CÙNG cột và cùng chiều mà
            // {@see MyRequests::threads()} dùng ở đầu bên kia (fix round 1, ruling: hai màn hình
            // của một cuộc trao đổi không được sắp khác nhau). `last_activity_at` là một CỘT,
            // không một truy vấn con trên `client_request_replies`: lý do đầy đủ và ranh giới
            // chính xác của "hoạt động" ở docblock migration
            // `add_last_activity_at_to_client_requests_table` — tóm tắt là bốn nguồn (yêu cầu
            // mới, khách hỏi tiếp, nhân sự trả lời, đổi trạng thái tay), và `assign()` CHỈ tính
            // khi nó cũng đổi trạng thái (`new → in_progress`), không tính khi chỉ đổi tay.
            ->defaultSort('last_activity_at', 'desc')
            ->recordActions([
                $this->replyAction(),
                $this->assignAction(),
                $this->changeStatusAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with([
                    'clientUser' => fn (BelongsTo $clientUser): BelongsTo => $clientUser->withTrashed(),
                    'assignee' => fn (BelongsTo $assignee): BelongsTo => $assignee->withTrashed(),
                    'replies' => fn (HasMany $replies): HasMany => $replies->orderBy('created_at'),
                ])
                ->withCount('replies'));
    }

    /**
     * Tên người xử lý, kèm một dấu hiệu khi họ đã bị vô hiệu hoá hoặc xoá mềm (REQ-3, phần hiển
     * thị). "Đã nghỉ việc" gộp cả hai — cùng cách dùng chữ mà docblock của cột này và của
     * {@see self::authorNames()} đã dùng — vì SPEC không phân biệt hai lý do và người đọc cột
     * này không cần biết CÁCH người đó rời đi, chỉ cần biết họ không còn xử lý được nữa.
     *
     * Public vì cùng lý do các hàm tĩnh khác của lớp này công khai: kết quả đi thẳng vào HTML của
     * `TextColumn`, nên đo trực tiếp qua chuỗi trả về nhanh và rõ hơn dựng cả bảng rồi `assertSee`.
     */
    public static function assigneeLabel(ClientRequest $record, ?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $assignee = $record->assignee;

        if ($assignee !== null && ($assignee->trashed() || ! $assignee->is_active)) {
            return __('requests.tab.assignee_deactivated', ['name' => $name]);
        }

        return $name;
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
     *
     * **Thông báo thành công KHÔNG còn tĩnh (REQ-6).** Bản trước luôn nói "Khách đọc được ngay
     * trên cổng khách hàng" — đúng khi hồ sơ đã công bố lên cổng, sai khi chưa: câu trả lời vẫn
     * được lưu (`is_published_to_portal` không phải một điều kiện của
     * `ReplyToClientRequest`/`ClientRequestReplyPolicy` — nhân sự vẫn trả lời được vào một hồ sơ
     * đang ẩn), nhưng khách nhận 404 khi mở trang, vì `MyRequests::resolveMatter()` từ chối một
     * hồ sơ chưa công bố. Nên câu báo đọc đúng `$this->getOwnerRecord()->is_published_to_portal`
     * — vụ việc CHỦ của cả relation manager, không cần nạp lại `$record->matter` — và chọn một
     * trong hai câu.
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
            ->action(function (Action $action, ClientRequest $record, array $data): void {
                $isPublished = (bool) $this->getOwnerRecord()->is_published_to_portal;

                $this->runAction(
                    $action,
                    function () use ($record, $data, $isPublished): void {
                        app(ReplyToClientRequest::class)->handle(
                            $record,
                            Auth::user(),
                            $data['content'] ?? '',
                        );

                        Notification::make()
                            ->title($isPublished
                                ? __('requests.tab.actions.reply_success')
                                : __('requests.tab.actions.reply_success_hidden'))
                            ->success()
                            ->send();
                    },
                );
            });
    }

    /**
     * "Nhận" và "gán người xử lý" của SPEC §7.2 là MỘT nút: một yêu cầu được nhận là một yêu cầu
     * đã có người đứng tên — xem docblock {@see TriageClientRequest}.
     *
     * Ô chọn chỉ liệt kê **đội ngũ còn đi làm của vụ việc** (`matter_user`, SPEC §4.7), lấy bằng
     * `pluck('name', 'id')` — hai cột, nên `email`, `phone` và `bar_number` của đồng nghiệp không
     * đi vào HTML của một ô `<select>`. Xem {@see self::assignableUsers()}.
     *
     * Danh sách này là một tiện ích, không phải cổng: `TriageClientRequest::assign()` hỏi lại
     * **trên người được chọn** cả hai điều kiện — `MatterPolicy::update` và "tài khoản còn hiệu
     * lực" — và từ chối bằng một câu gắn vào chính ô này. Id được giải bằng `withTrashed()`
     * trước khi trao cho Action, vì `null` ở tham số đó có một nghĩa KHÁC ("gỡ người đang giữ
     * ra") và một lần giải hụt sẽ đổi lệnh của người dùng thành lệnh đó.
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
                    static::resolveAssignee($data['assigned_to'] ?? null),
                ),
            ));
    }

    /**
     * Ô chọn bày ra **ba** trạng thái, không phải bốn: `new` chỉ có mặt khi luồng đang ở `new`,
     * và khi đó chọn nó là một lần không-làm-gì. Lý lẽ đầy đủ ở docblock
     * {@see TriageClientRequest::setStatus()} — `new` là một lời khẳng định về thế giới ("chưa ai
     * trong văn phòng nhìn thấy"), không phải một bước trong quy trình. Như mọi ô chọn khác ở
     * đây, đây là tiện ích chứ không phải cổng: Action từ chối giá trị đó dù ai gửi lên bằng
     * đường nào.
     *
     * **Thông báo thành công KHÔNG còn tĩnh (Task 3 review, R-carried — REQ-3).**
     * `TriageClientRequest::setStatus()` có thể tự gỡ người đang giữ khi mở lại một luồng đã đóng
     * mà người đó không còn mở nổi hồ sơ nữa (xem docblock của nó).
     *
     * **Đọc thẳng kết quả Action trả về, không tự so hai ảnh chụp (fix round 1, minor).** Bản
     * trước so `$record->assigned_to` (đọc TRƯỚC lời gọi) với `assigned_to` của luồng SAU lời gọi
     * để SUY ra việc gỡ người có xảy ra không — dựa trên tiền đề "không Action nào khác đụng cột
     * này giữa hai lần đọc", một tiền đề không còn chắc chắn một khi có Action khác (bàn giao hàng
     * loạt) cũng ghi `assigned_to`. Bản dự phòng khi đó còn ghép nhãn "Chưa ai nhận" (placeholder
     * của Ô TRỐNG) vào chỗ một cái TÊN nếu quan hệ `assignee` không có sẵn, ra một câu vô nghĩa.
     * Nay `setStatus()` tự báo qua {@see SetClientRequestStatusResult}: `unassignedAssignee` chỉ
     * khác `null` khi Action THẬT SỰ vừa gỡ ai, và nó luôn là người đó — không cần suy, không cần
     * dự phòng cho tên.
     */
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
                    ->options(fn (ClientRequest $record): array => static::statusOptions($record))
                    ->required()
                    ->native(false),
            ])
            ->action(fn (Action $action, ClientRequest $record, array $data) => $this->runAction(
                $action,
                function () use ($record, $data): void {
                    $result = app(TriageClientRequest::class)->setStatus(
                        $record,
                        Auth::user(),
                        ClientRequestStatus::from($data['status']),
                    );

                    Notification::make()
                        ->title($result->unassignedAssignee !== null
                            ? __('requests.tab.actions.change_status_unassigned', [
                                'name' => $result->unassignedAssignee->name,
                            ])
                            : __('requests.tab.actions.change_status_success'))
                        ->success()
                        ->send();
                },
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
     * Đổi giá trị modal gửi lên thành người thật — hoặc `null`.
     *
     * **`null` ở tham số `$assignee` của `TriageClientRequest::assign()` có một nghĩa RIÊNG: "gỡ
     * người đang giữ ra".** Nên một lần giải hụt không phải một lần từ chối, nó là một lệnh
     * KHÁC: `User::query()->find()` trả `null` cho một tài khoản đã xoá mềm, và lệnh "giao cho
     * người này" lặng lẽ thành lệnh "gỡ người đang giữ ra", kèm một thông báo màu xanh báo thành
     * công. `withTrashed()` giải ra người thật và để Action từ chối bằng câu của nó.
     *
     * Một hàm có tên, công khai, vì đó là chỗ duy nhất đo được: cổng ở trên nó — luật `in:` mà
     * Filament sinh từ `assignableUsers()` — chặn một id ngoài danh sách trước khi tới đây, nên
     * qua màn hình không có đường nào làm câu này đỏ. Đo bằng mutation: đổi về `User::query()`
     * thì một test đi qua `callTableAction()` vẫn XANH.
     */
    public static function resolveAssignee(mixed $id): ?User
    {
        return filled($id) ? User::withTrashed()->find($id) : null;
    }

    /**
     * Những trạng thái ô chọn bày ra cho MỘT bản ghi. Lý lẽ ở docblock
     * {@see self::changeStatusAction()}.
     *
     * Công khai vì nó phải đo được thẳng: một ô `Select` `native(false)` không in options vào
     * HTML ban đầu (Filament dựng chúng phía trình duyệt), nên một `assertDontSee` trên trang là
     * một khẳng định RỖNG — nó xanh kể cả khi danh sách vẫn đủ bốn. Cùng lý do
     * {@see self::renderThread()} và {@see self::statusColor()} công khai.
     *
     * @return array<string, string>
     */
    public static function statusOptions(ClientRequest $record): array
    {
        return collect(ClientRequestStatus::cases())
            ->reject(fn (ClientRequestStatus $status): bool => $status === ClientRequestStatus::New
                && $record->status !== ClientRequestStatus::New)
            ->mapWithKeys(fn (ClientRequestStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * Đội ngũ của vụ việc **còn đi làm**, tên và id. Xem docblock {@see self::assignAction()} cho
     * lý do danh sách hẹp lại đúng ở đội ngũ chứ không mở ra toàn bộ nhân sự.
     *
     * Công khai vì cùng lý do với {@see self::statusOptions()}: options của một ô `Select`
     * `native(false)` không đi vào HTML, nên đây là chỗ duy nhất đo được danh sách thật.
     *
     * `where('users.is_active', true)` là nửa màn hình của cổng mà {@see TriageClientRequest}
     * dựng ở nửa nghiệp vụ: một cái tên bày ra trong ô chọn là một lời mời, và mời người ta chọn
     * một đồng nghiệp đã nghỉ việc rồi mới từ chối là một cái bẫy có thể tránh. Tài khoản đã xoá
     * mềm thì đã bị `SoftDeletingScope` của `User` loại sẵn qua quan hệ `team()` — không thêm
     * một câu nào cho nó, và cũng không dựa vào nó: Action hỏi lại cả hai điều kiện.
     *
     * @return array<int, string>
     */
    public function assignableUsers(): array
    {
        return $this->getOwnerRecord()->team()
            ->where('users.is_active', true)
            ->pluck('name', 'users.id')
            ->all();
    }
}
