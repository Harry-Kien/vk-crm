<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Document\ListVisibleDocuments;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Search\LetterD;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Làn tl (2026-10-15) — trang "Tài liệu": mọi tài liệu người xem được phép thấy, XUYÊN các vụ, mới
 * nhất trước; tìm theo tên tài liệu, mã hồ sơ, tên khách; lọc theo vụ, loại vụ, nhóm, trạng thái,
 * người tải lên, khoảng ngày, "vụ của tôi". Yêu cầu của chủ văn phòng: tài liệu phải dễ tìm — trước
 * trang này, tài liệu chỉ xem được từng vụ một (tab "Tài liệu").
 *
 * # Chỉ ĐỌC và DẪN ĐƯỜNG — một đường cho mỗi việc
 *
 * Hai hành động: **Tải về** và **Mở vụ** (tab Tài liệu của vụ). Tải lên, sửa, công bố, rút lại, chuyển
 * nhóm, xoá vẫn chỉ ở tab Tài liệu (`DocumentsRelationManager`), nơi có đủ ngữ cảnh của vụ và đủ cổng
 * quyền của từng thao tác — trang này không thêm đường thứ hai cho việc nào.
 *
 * # Quyền: ở MỨC TRUY VẤN ({@see ListVisibleDocuments})
 *
 * Bảng đếm, phân trang và tìm trên truy vấn đã cắt theo quyền — không lọc sau phân trang, không hỏi
 * policy cho từng dòng. Các danh sách chọn của bộ lọc cũng cắt theo cùng luật: bộ lọc "Vụ việc" chỉ
 * liệt kê và tìm (trên máy chủ, theo mã hoặc tiêu đề — {@see self::matterOptions()}) vụ
 * `Matter::listableBy()` (không mã của vụ hạn chế nào người xem không thấy, kể cả khi ai đó đặt thẳng id
 * của vụ đó vào trạng thái bộ lọc — truy vấn gốc vẫn loại nó, và nhãn ô chọn lẫn chỉ báo của bộ lọc tra
 * trong chính tập `listableBy()`, {@see self::matterCode()}); bộ lọc "Nhóm" không có nhóm D với người
 * không có `document.viewInternal`.
 *
 * # Cổng: `matter.view`, hỏi ở MỌI request
 *
 * {@see self::canAccess()} hỏi `Gate::forUser()` — kế toán (chỉ `matter.viewAny`) và nhân viên kinh
 * doanh không có `matter.view`, SPEC §5: màn hình của họ không mang tài liệu. {@see self::boot()} chạy
 * ở mount VÀ mọi request cập nhật Livewire, trước hook của Filament vốn trả 403, nên một người mất
 * quyền khi trang còn mở nhận 404 ở lần gõ kế tiếp — cùng khuôn `Search`.
 *
 * # "Tải về": hỏi quyền LÚC BẤM, rồi chuyển tới đúng route có chữ ký
 *
 * Nút là một hành động Livewire chứ không phải một `href` ký sẵn: nó hỏi lại
 * `Gate::allows('download', $record)` lúc bấm, rồi chuyển trình duyệt tới
 * `Document::downloadUrlFor()` — route `documents.download.admin`, ký cho đúng người bấm, sống 5 phút,
 * `DocumentDownloadController` hỏi policy lần nữa và ghi `document_downloads` (SPEC §4.12, §10.4).
 * Không đường tắt nào tới tệp. Lý do không ký sẵn từng dòng như tab Tài liệu: `DocumentPolicy::download()`
 * đọc hợp đồng của vụ cho mỗi tài liệu (gói bàn giao mang bảng kê thanh toán, M9 Task 10) — trên một
 * bảng xuyên vụ, hỏi lúc vẽ là thêm truy vấn cho MỖI dòng. Hỏi lúc bấm thì số truy vấn của trang không
 * phụ thuộc số dòng (có test đếm), và một href không bao giờ hết hạn khi trang đứng yên quá 5 phút.
 * Cái giá: trợ lý thấy nút trên dòng gói bàn giao mang tiền, và bấm thì nhận một câu từ chối thay vì
 * không thấy nút.
 *
 * Menu (gộp integ-4, phán quyết controller): nhóm "Khách hàng & vụ việc", {@see self::$navigationSort} = 35
 * — ngay sau "Vụ việc" (30), trước "Tài khoản portal" (40). `tests/Feature/Filament/AdminNavigationGroupsTest.php`.
 */
class DocumentLibrary extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.document-library';

    protected static ?string $slug = 'documents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Clients;

    protected static ?int $navigationSort = 35;

    /**
     * Số vụ tối đa trong danh sách chọn của bộ lọc "Vụ việc" — mở sẵn (mới nhất trước), và mỗi lần
     * tìm trên máy chủ; cùng con số `ActivityLogPage`.
     */
    private const MATTER_OPTIONS_LIMIT = 200;

    public static function getNavigationLabel(): string
    {
        return __('document_library.navigation_label');
    }

    public function getTitle(): string
    {
        return __('document_library.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && static::canBeOpenedBy($user);
    }

    /**
     * Người này có mở được trang không (`matter.view`) — MỘT định nghĩa cho {@see self::canAccess()} và cho
     * thẻ "Trang Tài liệu" của trang tổng Cài đặt, nơi đếm số nhân sự mở được (gộp integ-4, mục C).
     */
    public static function canBeOpenedBy(User $user): bool
    {
        return Gate::forUser($user)->allows(Permission::MatterView->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Tên bộ lọc "Chờ tôi duyệt" (gộp integ-fa, rà soát chéo, mục 17) — ô số trên bảng điều khiển trỏ tới nó. */
    public const AWAITING_MY_APPROVAL = 'awaiting_my_approval';

    /**
     * Bộ nhớ của {@see self::awaitingApprovalIdsThisRequest()} — id người xem => id văn bản; không phải trạng thái Livewire.
     *
     * @var array<int, list<int>>|null
     */
    private ?array $awaitingApprovalIdsByViewer = null;

    /**
     * Id các văn bản "chờ tôi duyệt" của `$viewer` (gộp integ-fa, rà soát chéo 2026-10-18, mục 17): văn bản nhóm B
     * (`DocumentGroup::Issued`) đang "Chờ duyệt" (`DocumentStatus::PendingApproval`), trong tập trang này cho người đó
     * thấy (`ListVisibleDocuments`), mà người đó QUA ĐÚNG CỔNG của nút duyệt — `DocumentPolicy::publish`, cổng của
     * `MarkDocumentSignedFiled` ("Đánh dấu đã ký, đã nộp") và của "Trả về bản nháp". Cổng hỏi từng văn bản (MỘT luật,
     * không viết lại bằng SQL); tập văn bản đang chờ duyệt nhỏ. Bộ lọc của trang và ô số "Văn bản chờ tôi duyệt" của bảng
     * điều khiển (`MyWorkQueueWidget`) cùng đọc hàm này — bộ lọc qua {@see self::awaitingApprovalIdsThisRequest()} (một
     * lần mỗi request), ô số một lần mỗi lần dựng (`MyWorkQueueWidget::getStats()` giữ số của nó).
     *
     * @return list<int>
     */
    public static function awaitingApprovalIds(User $viewer): array
    {
        if (! static::canBeOpenedBy($viewer)) {
            return [];
        }

        $gate = Gate::forUser($viewer);

        return app(ListVisibleDocuments::class)->query($viewer)
            ->where('documents.group', DocumentGroup::Issued->value)
            ->where('documents.status', DocumentStatus::PendingApproval->value)
            ->with('matter')
            ->get()
            ->filter(fn (Document $document): bool => $gate->allows('publish', $document))
            ->map(fn (Document $document): int => (int) $document->getKey())
            ->values()
            ->all();
    }

    /**
     * {@see self::awaitingApprovalIds()} MỘT lần cho mỗi request của trang (gộp integ-fa, rà soát lại 2026-10-18):
     * Filament áp bộ lọc nhiều lần trong một request (truy vấn dòng, truy vấn đếm), và mỗi lần hỏi cổng `publish` từng
     * văn bản. Thuộc tính `private` không đi qua trạng thái Livewire, nên mỗi request (lần dựng, mỗi lần cập nhật) tính
     * lại từ đầu — không bao giờ cũ hơn request đang chạy.
     *
     * @return list<int>
     */
    private function awaitingApprovalIdsThisRequest(User $viewer): array
    {
        $this->awaitingApprovalIdsByViewer ??= [];

        return $this->awaitingApprovalIdsByViewer[$viewer->getKey()] ??= static::awaitingApprovalIds($viewer);
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->rowsQuery())
            ->description(__('document_library.intro'))
            ->emptyStateHeading(__('document_library.empty_heading'))
            ->emptyStateDescription(__('document_library.empty_description'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('document_library.columns.title'))
                    ->weight('bold')
                    ->wrap()
                    // Làn fa3: "đ" như "d" (`LetterD`), cùng cách trang Tìm kiếm.
                    ->searchable(query: fn (Builder $query, string $search): Builder => LetterD::whereContains($query, $query->qualifyColumn('title'), $search)),
                // Nhãn "Chỉ nội bộ" của nhóm D (SPEC §7.2) — cùng chuỗi tab Tài liệu dùng.
                TextColumn::make('internal_marker')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->wrap()
                    ->state(fn (Document $record): ?string => DocumentsRelationManager::internalMarkerLabel($record->group)),
                TextColumn::make('matter.code')
                    ->label(__('document_library.columns.matter_code'))
                    ->url(fn (Document $record): string => AdminUrls::document($record))
                    ->searchable(),
                TextColumn::make('matter.client.name')
                    ->label(__('document_library.columns.client'))
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::applyClientNameSearch($query, $search)),
                TextColumn::make('group')
                    ->label(__('document_library.columns.group'))
                    ->badge()
                    ->formatStateUsing(fn (DocumentGroup $state): string => $state->label())
                    ->color(fn (DocumentGroup $state): string => $state->isInternal() ? 'gray' : 'primary'),
                TextColumn::make('status')
                    ->label(__('document_library.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->label())
                    ->color(fn (DocumentStatus $state): string => match ($state) {
                        DocumentStatus::Published => 'success',
                        DocumentStatus::Retracted => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('version')
                    ->label(__('document_library.columns.version')),
                TextColumn::make('uploader_name')
                    ->label(__('document_library.columns.uploader'))
                    ->wrap()
                    ->state(fn (Document $record): string => static::uploaderName($record)),
                TextColumn::make('created_at')
                    ->label(__('document_library.columns.uploaded_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('document_library.columns.size'))
                    ->placeholder('—')
                    ->state(fn (Document $record): ?string => static::fileSize($record->getFirstMedia('file')?->size)),
            ])
            ->filters([
                SelectFilter::make('matter')
                    ->label(__('document_library.filters.matter'))
                    ->placeholder(__('document_library.filters.matter_placeholder'))
                    ->attribute('matter_id')
                    ->options(fn (): array => static::matterOptions())
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => static::matterOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::matterCode($value))
                    ->indicateUsing(fn (array $state): array => filled($code = static::matterCode($state['value'] ?? null))
                        ? [Indicator::make(__('document_library.filters.matter').': '.$code)]
                        : []),
                SelectFilter::make('matter_type')
                    ->label(__('document_library.filters.matter_type'))
                    ->options(fn (): array => MatterType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('matter', fn (Builder $matter): Builder => $matter->where('matter_type_id', (int) $data['value']))),
                SelectFilter::make('group')
                    ->label(__('document_library.filters.group'))
                    ->options(fn (): array => static::groupOptions()),
                SelectFilter::make('status')
                    ->label(__('document_library.filters.status'))
                    ->options(collect(DocumentStatus::cases())
                        ->mapWithKeys(fn (DocumentStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('uploader')
                    ->label(__('document_library.filters.uploader'))
                    ->options(fn (): array => static::uploaderOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => static::applyUploaderFilter($query, $data['value'] ?? null)),
                SelectFilter::make('involvement')
                    ->label(__('document_library.filters.involvement'))
                    ->options([
                        'lead' => __('document_library.filters.involvement_lead'),
                        'team' => __('document_library.filters.involvement_team'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => static::applyInvolvementFilter($query, $data['value'] ?? null)),
                Filter::make('uploaded_between')
                    ->label(__('document_library.filters.uploaded_between'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('document_library.filters.uploaded_from'))
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('until')
                            ->label(__('document_library.filters.uploaded_until'))
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate($q->qualifyColumn('created_at'), '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate($q->qualifyColumn('created_at'), '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? Indicator::make(__('document_library.filters.indicator_from', ['date' => CarbonImmutable::parse($data['from'])->format('d/m/Y')]))->removeField('from') : null,
                        filled($data['until'] ?? null) ? Indicator::make(__('document_library.filters.indicator_until', ['date' => CarbonImmutable::parse($data['until'])->format('d/m/Y')]))->removeField('until') : null,
                    ]))),
                // Gộp integ-fa (rà soát chéo, mục 17): văn bản nhóm B đang chờ duyệt mà CHÍNH người xem duyệt được — xem
                // {@see self::awaitingApprovalIds()}. Chỉ hiện với người có `document.publish`.
                Filter::make(self::AWAITING_MY_APPROVAL)
                    ->label(__('document_library.filters.awaiting_my_approval'))
                    ->toggle()
                    ->visible(fn (): bool => Auth::user() instanceof User && Gate::allows(Permission::DocumentPublish->value))
                    ->query(fn (Builder $query): Builder => Auth::user() instanceof User
                        ? $query->whereKey($this->awaitingApprovalIdsThisRequest(Auth::user()))
                        : $query->whereRaw('1 = 0')),
            ])
            ->recordActions([
                $this->downloadAction(),
                Action::make('open_matter')
                    ->label(__('document_library.actions.open_matter'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (Document $record): string => AdminUrls::document($record)),
            ]);
    }

    /**
     * Truy vấn gốc (đã cắt theo quyền) cộng đúng những gì các cột cần nạp sẵn: vụ và khách (cột mã,
     * khách), người tải lên (cột người tải), media (cột dung lượng, nút "Tải về"). Khách và người tải
     * lên nạp KÈM bản ghi đã xoá mềm: tài liệu của một khách đã xoá hay do một nhân sự đã nghỉ tải lên
     * vẫn phải nói được của ai.
     *
     * @return Builder<Document>
     */
    private function rowsQuery(): Builder
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        return app(ListVisibleDocuments::class)->query($viewer)->with([
            'matter.client' => fn ($client) => $client->withTrashed(),
            'uploader' => fn (MorphTo $uploader) => $uploader->constrain([
                User::class => fn (Builder $user): Builder => $user->withTrashed(),
                ClientUser::class => fn (Builder $clientUser): Builder => $clientUser->withTrashed(),
            ]),
            'media',
        ]);
    }

    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('document_library.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Document $record): bool => $record->getMedia('file')->isNotEmpty())
            // Cổng trang đã hỏi ở `boot()` của chính request bấm nút; ở đây chỉ còn câu hỏi riêng của
            // việc tải — `DocumentPolicy::download()` (xem docblock lớp, mục "Tải về").
            ->action(function (Document $record): void {
                /** @var User $viewer */
                $viewer = Auth::user();

                if (! Gate::forUser($viewer)->allows('download', $record)) {
                    Notification::make()->title(__('document_library.download_denied'))->danger()->send();

                    return;
                }

                $this->redirect($record->downloadUrlFor($viewer));
            });
    }

    /**
     * Ô tìm "Khách hàng": cùng tập khách mà cột đang hiện tên — KÈM khách đã xoá mềm (xem
     * {@see self::rowsQuery()}). Tìm quan hệ mặc định của Filament đi qua `whereHas('matter.client')`,
     * mang `SoftDeletingScope` của `Client`: cột hiện "Khách A" mà gõ "Khách A" ra 0 dòng. Quyền không
     * đổi: điều kiện này chỉ thu hẹp truy vấn gốc đã cắt theo người xem ({@see ListVisibleDocuments}).
     * So bằng `LIKE '%x%'` theo collation của `clients.name` sau khi hai vế đổi "đ"/"Đ" → "d"/"D"
     * ({@see LetterD}, làn fa3 2026-10-16) — đúng trang Tìm kiếm (SPEC §6.13): "Dang" ra "Đặng" trên MariaDB.
     */
    private static function applyClientNameSearch(Builder $query, string $search): Builder
    {
        return $query->whereHas('matter', fn (Builder $matter): Builder => $matter->whereHas(
            'client',
            fn (Builder $client): Builder => LetterD::whereContains($client->withTrashed(), $client->qualifyColumn('name'), $search),
        ));
    }

    /**
     * Vụ cho bộ lọc "Vụ việc": đúng `Matter::listableBy()` của người xem (không vụ hạn chế nào họ
     * không thấy), mới nhất trước, nhãn là mã hồ sơ. Không chuỗi tìm: {@see self::MATTER_OPTIONS_LIMIT}
     * vụ mới nhất mở sẵn. Có chuỗi tìm (người dùng gõ vào ô chọn — tìm trên máy chủ): mọi vụ người xem
     * thấy có mã hồ sơ hoặc tiêu đề chứa chuỗi đó, nên một vụ cũ ngoài danh sách mở sẵn vẫn chọn được.
     * Tiêu đề là trường người xem trang này được đọc (cổng của trang là `matter.view`).
     *
     * @return array<int, string>
     */
    private static function matterOptions(?string $search = null): array
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User) {
            return [];
        }

        $search = trim((string) $search);

        return Matter::query()
            ->listableBy($viewer)
            // Làn fa3: "đ" như "d" ở cả mã và tiêu đề (`LetterD`).
            ->when($search !== '', fn (Builder $matters): Builder => $matters->where(fn (Builder $any): Builder => LetterD::whereContains(
                LetterD::whereContains($any, $any->qualifyColumn('code'), $search),
                $any->qualifyColumn('title'),
                $search,
                'or',
            )))
            ->orderByDesc('id')
            ->limit(self::MATTER_OPTIONS_LIMIT)
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * Mã hồ sơ của một vụ đã chọn trong bộ lọc (nhãn ô chọn và chỉ báo bộ lọc) — tra trong CHÍNH tập
     * `listableBy()` của người xem: một id vụ hạn chế đặt thẳng vào trạng thái bộ lọc không ra mã nào.
     */
    private static function matterCode(mixed $id): ?string
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User || blank($id) || ! is_numeric($id)) {
            return null;
        }

        return Matter::query()->listableBy($viewer)->whereKey((int) $id)->value('code');
    }

    /** @return array<string, string> bốn nhóm, trừ nhóm D với người không đọc được nhóm D. */
    private static function groupOptions(): array
    {
        $seesInternal = Auth::user()?->can(Permission::DocumentViewInternal->value) ?? false;

        return collect(DocumentGroup::cases())
            ->reject(fn (DocumentGroup $group): bool => $group->isInternal() && ! $seesInternal)
            ->mapWithKeys(fn (DocumentGroup $group): array => [$group->value => $group->label()])
            ->all();
    }

    /**
     * "Khách hàng (nộp qua cổng)" và từng nhân sự (kể cả đã nghỉ — tài liệu họ tải lên vẫn còn). Khoá
     * mang loại người tải (`client`, `user:<id>`) để một id nhân sự không bao giờ trùng một id khách.
     *
     * @return array<string, string>
     */
    private static function uploaderOptions(): array
    {
        return ['client' => __('document_library.filters.uploader_client')]
            + User::query()->withTrashed()->orderBy('name')->pluck('name', 'id')
                ->mapWithKeys(fn (string $name, int $id): array => ['user:'.$id => $name])
                ->all();
    }

    private static function applyUploaderFilter(Builder $query, mixed $value): Builder
    {
        if ($value === 'client') {
            return $query->where($query->qualifyColumn('uploader_type'), (new ClientUser)->getMorphClass());
        }

        if (is_string($value) && preg_match('/^user:(\d+)$/', $value, $match) === 1) {
            return $query
                ->where($query->qualifyColumn('uploader_type'), (new User)->getMorphClass())
                ->where($query->qualifyColumn('uploader_id'), (int) $match[1]);
        }

        return $query;
    }

    /** "Vụ tôi phụ trách" = `lead_lawyer_id`; "vụ tôi có tên trong đội" = một dòng `matter_user` bất kỳ vai. */
    private static function applyInvolvementFilter(Builder $query, mixed $value): Builder
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User || ! in_array($value, ['lead', 'team'], true)) {
            return $query;
        }

        return $query->whereHas('matter', fn (Builder $matter): Builder => $value === 'lead'
            ? $matter->ledBy($viewer)
            : $matter->whereHas('team', fn (Builder $team): Builder => $team->whereKey($viewer->getKey())));
    }

    /** Tên người tải lên; tài khoản khách có thêm chữ "(khách hàng)". */
    public static function uploaderName(Document $document): string
    {
        $uploader = $document->uploader;

        return match (true) {
            $uploader instanceof ClientUser => __('document_library.uploader.client', ['name' => $uploader->name]),
            $uploader instanceof User => $uploader->name,
            default => __('document_library.uploader.unknown'),
        };
    }

    /** Dung lượng kiểu Việt Nam — dấu phẩy thập phân, một chữ số sau dấu phẩy từ KB trở lên. */
    public static function fileSize(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        if ($bytes < 1024) {
            return __('document_library.size.bytes', ['size' => $bytes]);
        }

        [$unit, $divisor] = match (true) {
            $bytes < 1024 ** 2 => ['kilobytes', 1024],
            $bytes < 1024 ** 3 => ['megabytes', 1024 ** 2],
            default => ['gigabytes', 1024 ** 3],
        };

        return __("document_library.size.{$unit}", ['size' => number_format($bytes / $divisor, 1, ',', '.')]);
    }
}
