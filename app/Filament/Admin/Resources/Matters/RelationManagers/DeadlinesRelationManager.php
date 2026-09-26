<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Deadline\SetDeadlinePublication;
use App\Enums\DeadlineSeverity;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Deadline;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Tab "Mốc thời hạn" (SPEC §7.2) — **màn hình duy nhất trong hệ thống tạo ra một hàng
 * `deadlines`** (đường ghi duy nhất trước nó là `MatterSeeder`, tức dữ liệu mẫu).
 *
 * # Vì sao nó được dựng ở M6 chứ không ở milestone nào trước
 *
 * Bảng có từ M1, policy từ M2, cổng khách đọc nó từ M5 — nhưng không có một màn hình nào để tạo
 * một mốc hạn, và chỗ trống đó chỉ lộ ra ở lần rà soát toàn hệ thống ngày 22/09/2026.
 *
 * **Thứ giấu nó suốt ba milestone là dữ liệu mẫu.** `MatterSeeder::deadlines()` dựng sẵn một mốc
 * đã công bố cho mọi hồ sơ mẫu (và một mốc `critical` cho hai hồ sơ trong số đó), nên trên máy
 * lập trình viên cái bảng này LUÔN có dữ liệu và khối "Mốc thời hạn sắp tới" của cổng khách luôn
 * có nội dung. Ở văn phòng thì không, và sẽ không bao giờ có, vì không ai gõ được một mốc vào
 * đâu cả.
 *
 * Nó chặn `CheckDeadlines` (Task 6) theo hình dạng xấu nhất mà một lỗi có thể mang: job sẽ chạy
 * đúng mỗi sáng, xanh trong mọi bài kiểm tra chạy trên dữ liệu mẫu, và không nhắc một ai. Với
 * một văn phòng luật thì đây cũng là chỗ rủi ro nghề nghiệp cao nhất trong cả hệ thống — một hạn
 * tố tụng bị bỏ lỡ không phải một phiền toái.
 *
 * # Lớp này không có một dòng nghiệp vụ nào
 *
 * Mọi lần ghi đi qua {@see AddMatterDeadline}, {@see SetDeadlineCompletion} hoặc
 * {@see SetDeadlinePublication} (CLAUDE.md: nghiệp vụ chỉ ở `app/Actions/`; M3 đã phải tách
 * `SetMatterPortalPublication` ra khỏi `ViewMatter` vì đúng chuyện này). Những gì ở đây là: cột
 * nào hiện, nút nào hiện cho ai, một dòng quá hạn TRÔNG như thế nào, và một lời từ chối của
 * Action đến được mắt người dùng bằng tiếng Việt thay vì thành trang 500 — xem
 * {@see ReportsActionFailures}.
 *
 * # Hai cổng khác nhau trên mỗi nút, cố ý tách rời
 *
 * `->authorize()` hỏi `Gate` về QUYỀN (`DeadlinePolicy::update`, tức `MatterPolicy::update` — nên
 * kế toán không thấy nút nào ở đây); `->visible()` hỏi về TRẠNG THÁI bản ghi. Không cổng nào ở
 * đây là cổng thật: cả ba Action tự hỏi lại tất cả và không tin màn hình đã lọc. Cùng thành ngữ
 * {@see ChecklistRelationManager} và {@see ClientRequestsRelationManager} dùng.
 *
 * **`->authorize()` trên `CreateAction` KHÔNG phải trang trí, và nó làm một việc mà
 * `isReadOnly()` không làm.** `DeadlinePolicy::create()` nhận `User` mà KHÔNG nhận vụ việc (xem
 * báo cáo task): nó chỉ hỏi được "người này có quyền `matter.update` nói chung không", không hỏi
 * được "trên HỒ SƠ NÀY" — nên một luật sư ngoài đội ngũ của một vụ `restricted` vẫn qua được nó.
 * Vì vậy cái nút hỏi thẳng `MatterPolicy::update` trên vụ việc chủ. Cùng lỗ hổng mà
 * `PartiesRelationManager` đã ghi lại cho `MatterPartyPolicy::create()`.
 *
 * **Và vì `->authorize()` được đặt, `isReadOnly()` không còn nói được gì ở lớp này** — đó là một
 * sự thật ĐO ĐƯỢC trong vendor, không phải một phỏng đoán: `CanBeAuthorized::resolveIsAuthorized()`
 * chỉ hỏi `getDefaultActionAuthorizationResponse()` (nơi duy nhất `isReadOnly()` được đọc) khi
 * `$this->authorization === null`. Nên không có `isReadOnly(): false` ở đây, và test
 * "hides the add button from someone who cannot write to the matter" là thứ ghim hành vi thật
 * thay cho một phương thức không được gọi.
 *
 * # Quá hạn tô đỏ, sắp đến hạn tô vàng — bằng `style=`, và đó là bắt buộc
 *
 * Không có bước dựng CSS trong dự án (CLAUDE.md): panel nạp `vendor/filament/filament/dist/theme.css`
 * đã biên dịch sẵn và tệp đó chỉ chứa các lớp `fi-*` của chính Filament, nên một lớp tiện ích
 * Tailwind viết tay ở đây TÔ RA ĐÚNG SỐ KHÔNG. Hai tính năng của M3 (`bg-gray-100` cho nền ghi
 * chú nội bộ, `text-amber-600` cho nhãn "Khách chưa xem") đã sống hai milestone ở trạng thái vô
 * hình vì đúng chuyện này. Xem {@see self::renderDueDate()}.
 */
class DeadlinesRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'deadlines';

    /**
     * Ngưỡng "sắp đến hạn" của MÀN HÌNH: trong ngần này ngày thì dòng chuyển sang vàng.
     *
     * Bằng đúng cửa sổ 7 ngày của widget trang chủ (SPEC §7.1 mục 2, "Mốc thời hạn 7 ngày tới"),
     * để hai chỗ trong cùng một panel không gọi hai tập hợp khác nhau là "sắp đến hạn".
     *
     * Nó KHÔNG phải cùng một con số với các bậc nhắc của `CheckDeadlines` (SPEC §6.8 — 7/3/1/quá
     * hạn, thêm bậc 14 cho `critical`), và hai thứ đó không nên bị gộp: ở đây là một câu hỏi về
     * MÀU SẮC trên một bảng, ở kia là một câu hỏi về việc có gửi thư hay không.
     */
    public const DUE_SOON_DAYS = 7;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('deadlines.tab.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('deadlines.label');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('deadlines.plural_label');
    }

    /**
     * Cổng của cả TAB. `MatterPolicy::view` trên vụ việc chủ, không một điều kiện nào viết lại:
     * mốc thời hạn là nội dung hồ sơ, nên ai đọc được hồ sơ thì đọc được nó, và không ai khác.
     * Kế toán có `matter.viewAny` nhưng không có `matter.view` (SPEC §5), nên tab không tồn tại
     * cho họ — `ScopesToVisibleMatters` một mình KHÔNG giữ họ ở ngoài, vì
     * `Matter::scopeListableBy` không ràng buộc gì với người có `matter.viewAny` (đo được ở vòng
     * rà soát 21/09/2026 trên tab "Yêu cầu từ khách").
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    /**
     * Form "thêm nhanh" của SPEC §7.2.
     *
     * **Hai ô phải GÕ, hai ô đã chọn sẵn, một công tắc mặc định tắt.** Một luật sư thêm một mốc
     * trong lúc đang đọc một quyết định của toà, không trong lúc điền một biểu mẫu: nếu nó tốn
     * hơn vài giây thì nó không được làm, và một mốc không được ghi là một mốc không ai nhắc.
     * Người dùng chỉ gõ **tên** và **ngày**; mức độ và người phụ trách đã mang sẵn giá trị của
     * trường hợp thường gặp (`normal`, luật sư phụ trách hồ sơ).
     *
     * Hai ô chọn sẵn ấy VẪN `required()`, và đó không mâu thuẫn với câu trên: mặc định là thứ
     * người dùng không phải điền, `required()` là thứ chặn họ xoá trắng nó rồi lưu. Một mốc
     * không có người phụ trách là một mốc `CheckDeadlines` (Task 6) không biết gửi thư cho ai.
     *
     * Mọi `required()` ở đây là nửa "cho người dùng thấy"; nửa gác cổng thật nằm trong
     * {@see AddMatterDeadline} và nó hỏi lại tất cả, kể cả độ dài tên.
     */
    public function form(Schema $schema): Schema
    {
        $matter = $this->getOwnerRecord();
        $responsibleOptions = $this->responsibleOptions();

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('deadlines.tab.fields.name'))
                    ->helperText(__('deadlines.tab.fields.name_help'))
                    ->required()
                    ->maxLength(AddMatterDeadline::NAME_MAX_LENGTH)
                    ->columnSpanFull(),
                DatePicker::make('due_date')
                    ->label(__('deadlines.tab.fields.due_date'))
                    ->required()
                    ->native(false),
                Select::make('severity')
                    ->label(__('deadlines.tab.fields.severity'))
                    ->helperText(__('deadlines.tab.fields.severity_help'))
                    ->options(collect(DeadlineSeverity::cases())
                        ->mapWithKeys(fn (DeadlineSeverity $severity): array => [$severity->value => $severity->label()])
                        ->all())
                    ->default(DeadlineSeverity::Normal->value)
                    ->required()
                    ->native(false),
                Select::make('responsible_user_id')
                    ->label(__('deadlines.tab.fields.responsible'))
                    ->helperText(__('deadlines.tab.fields.responsible_help'))
                    ->options($responsibleOptions)
                    // Mặc định là luật sư phụ trách — nhưng CHỈ khi người đó còn nằm trong danh
                    // sách bày ra. Một `default()` trỏ vào một giá trị ngoài `options()` bị luật
                    // `in:` của Filament chặn ngay lần gửi đầu, nên một luật sư phụ trách đã nghỉ
                    // việc sẽ biến ô này thành một cái bẫy im lặng: ô hiện trống, người dùng bấm
                    // lưu, và lỗi nói về một giá trị họ chưa từng chọn. Để trống thì `required()`
                    // buộc họ chọn một người thật.
                    ->default(fn (): ?int => array_key_exists($matter->lead_lawyer_id, $responsibleOptions)
                        ? $matter->lead_lawyer_id
                        : null)
                    ->required()
                    ->native(false),
                Toggle::make('is_published')
                    ->label(__('deadlines.tab.fields.is_published'))
                    // Mặc định TẮT, và không bao giờ theo trạng thái của vụ việc: cột này là thứ
                    // duy nhất đứng giữa một mốc nội bộ và mắt khách hàng (SPEC §8.3 khối 6).
                    ->default(false)
                    // Khoá hẳn khi vụ việc chưa lên cổng, cùng thành ngữ
                    // `BuildsStageUpdateSchema::publishToggleField()`: một công tắc bật được rồi
                    // mới nhận về một lời từ chối là một lời hứa sai. Đây là cổng phía MÁY CHỦ —
                    // `disabled()` gọi `saved(false)` và một trường không `isSaved()` bị
                    // `dehydrateState()` XOÁ khỏi `$data`, nên một payload Livewire dàn dựng cũng
                    // không gửi được `true` qua đây.
                    ->disabled(! $matter->is_published_to_portal)
                    ->helperText($matter->is_published_to_portal
                        ? __('deadlines.tab.fields.is_published_help')
                        : __('deadlines.tab.fields.is_published_disabled_hint')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->emptyStateHeading(__('deadlines.tab.empty_state'))
            ->columns([
                // Cột đầu tiên, và là cột duy nhất mang màu: mắt người đọc bảng này đi tìm đúng
                // một thứ — cái gì sắp hết giờ.
                TextColumn::make('due_date')
                    ->label(__('deadlines.tab.columns.due_date'))
                    ->html()
                    ->formatStateUsing(fn (Deadline $record): HtmlString => static::renderDueDate($record))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('deadlines.tab.columns.name'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('severity')
                    ->label(__('deadlines.tab.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (DeadlineSeverity $state): string => $state->label())
                    ->color(fn (DeadlineSeverity $state): string => static::severityColor($state)),
                TextColumn::make('responsible.name')
                    ->label(__('deadlines.tab.columns.responsible'))
                    ->placeholder('—'),
                IconColumn::make('is_published')
                    ->label(__('deadlines.tab.columns.is_published'))
                    ->boolean(),
                TextColumn::make('completed_at')
                    ->label(__('deadlines.tab.columns.completed_at'))
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date')
            ->headerActions([
                $this->addAction(),
            ])
            ->recordActions([
                $this->changeResponsibleAction(),
                $this->completeAction(),
                $this->reopenAction(),
                $this->publishAction(),
                $this->unpublishAction(),
            ])
            // `responsible` nạp KÈM cả tài khoản đã xoá mềm: một luật sư đã nghỉ việc vẫn phải
            // hiện tên. Không có nó, cột đọc ra `null` và in "—" trong khi `responsible_user_id`
            // vẫn giữ nguyên id của họ — một cái bảng nói sai về chính cột nó đang vẽ, và người
            // đọc không có cách nào biết. Cùng lý do `ClientRequestsRelationManager` làm vậy với
            // `assignee`.
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with(['responsible' => fn (BelongsTo $responsible): BelongsTo => $responsible->withTrashed()]));
    }

    /** Màu badge của từng mức độ. Chỉ hiển thị — không luật nghiệp vụ nào đọc nó. */
    public static function severityColor(DeadlineSeverity $severity): string
    {
        return match ($severity) {
            DeadlineSeverity::Normal => 'gray',
            DeadlineSeverity::Critical => 'danger',
        };
    }

    /**
     * Ô ngày đến hạn như nó hiện ra: ngày, và ngay dưới là **còn bao lâu**, tô theo mức gấp.
     * Tách static để test được mà không dựng cả bảng, cùng thành ngữ
     * {@see StageLogsRelationManager::renderInternalNote()} và
     * {@see ChecklistRelationManager::progressBar()}.
     *
     * **Phép trừ ngày nằm ở đây chứ không trong đầu người đọc.** Một cột chỉ in "22/09/2026" bắt
     * mỗi người dùng tự tính lấy khoảng cách tới hôm nay, mỗi dòng một lần — và đó đúng là phép
     * tính mà một người đang vội làm sai.
     *
     * **Kiểu dáng viết thẳng bằng `style=`, không bằng lớp Tailwind** — xem docblock lớp cho lý
     * do, và `DeadlinesRelationManagerTest` cho phép đo: nó lấy từng tên biến màu ra khỏi markup
     * thật rồi hỏi `FilamentColor` xem sắc độ đó có được đăng ký không, vì một `var(--danger-650)`
     * gõ nhầm cũng tô ra đúng số không.
     *
     * Ba mức, và ranh giới của chúng là một quyết định:
     *
     *  - **Đỏ** (`--danger-600`) cho đã quá hạn VÀ cho đúng hôm nay. Hôm nay không phải "sắp đến":
     *    hết giờ làm việc là hết, và một sắc vàng ở đó là một lời trấn an sai.
     *  - **Vàng** (`--warning-600`) cho 1 tới `self::DUE_SOON_DAYS` ngày.
     *  - **Xám** cho phần còn lại, và cho mọi mốc ĐÃ XONG kể cả khi ngày của nó đã trôi qua: màu
     *    đỏ ở đây là một lời gọi hành động, và một dòng đỏ không còn việc gì để làm dạy người ta
     *    bỏ qua màu đỏ.
     */
    public static function renderDueDate(Deadline $deadline): HtmlString
    {
        ['text' => $text, 'colour' => $colour] = static::timing($deadline);

        return new HtmlString(sprintf(
            '<span style="font-weight:600">%s</span>'
            .'<span style="display:block;font-size:0.75rem;font-weight:600;color:%s">%s</span>',
            e($deadline->due_date->format('d/m/Y')),
            $colour,
            e($text),
        ));
    }

    /**
     * Câu "còn bao lâu" và màu của nó.
     *
     * **`private`, cố ý.** Nó không phải một bề mặt cho ai khác dùng lại: các bậc nhắc của
     * `CheckDeadlines` (SPEC §6.8 — 7/3/1/quá hạn, thêm 14 cho `critical`) là một tập hợp KHÁC,
     * và một hàm công khai tên "timing" nằm trong một relation manager là một lời mời để hai luật
     * khác nhau bị gộp làm một. Phép đo đi qua {@see self::renderDueDate()} — markup thật, đúng
     * thứ trình duyệt nhận — nên không có lời khẳng định nào mất chỗ đứng vì nó đóng lại.
     *
     * @return array{text: string, colour: string}
     */
    private static function timing(Deadline $deadline): array
    {
        $muted = 'color-mix(in srgb, var(--gray-500) 90%, transparent)';

        if ($deadline->is_completed) {
            return ['text' => __('deadlines.tab.timing.done'), 'colour' => $muted];
        }

        // `today()` và `due_date` (cast `date`) đều ở 00:00, nên hiệu số là một số ngày nguyên;
        // `false` giữ dấu âm cho những mốc đã trôi qua.
        $daysLeft = (int) today()->diffInDays($deadline->due_date, false);

        return match (true) {
            $daysLeft < 0 => [
                'text' => __('deadlines.tab.timing.overdue', ['count' => abs($daysLeft)]),
                'colour' => 'var(--danger-600)',
            ],
            $daysLeft === 0 => [
                'text' => __('deadlines.tab.timing.due_today'),
                'colour' => 'var(--danger-600)',
            ],
            $daysLeft === 1 => [
                'text' => __('deadlines.tab.timing.due_tomorrow'),
                'colour' => 'var(--warning-600)',
            ],
            $daysLeft <= self::DUE_SOON_DAYS => [
                'text' => __('deadlines.tab.timing.due_in_days', ['count' => $daysLeft]),
                'colour' => 'var(--warning-600)',
            ],
            default => [
                'text' => __('deadlines.tab.timing.due_in_days', ['count' => $daysLeft]),
                'colour' => $muted,
            ],
        };
    }

    /**
     * Đội ngũ của vụ việc **còn đi làm**, tên và id — danh sách người phụ trách bày ra trong ô
     * chọn. Cùng hình dạng {@see ClientRequestsRelationManager::assignableUsers()}, và công khai
     * vì cùng lý do: options của một `Select` `native(false)` không đi vào HTML ban đầu (Filament
     * dựng chúng phía trình duyệt), nên đây là chỗ duy nhất đo được danh sách thật.
     *
     * `pluck('name', 'users.id')` — hai cột, nên `email`, `phone` và `bar_number` của đồng nghiệp
     * không đi vào HTML của một ô `<select>`.
     *
     * Đây là một tiện ích, không phải cổng: {@see AddMatterDeadline} hỏi lại **trên người được
     * chọn** cả hai điều kiện — `MatterPolicy::update` và "tài khoản còn hiệu lực" — và từ chối
     * bằng một câu gắn vào chính ô này. Lần lọc `Gate` ở đây chỉ để không mời người ta chọn một
     * cái bẫy: với một vụ `restricted` (SPEC §4.6) thì cả đội ngũ trừ luật sư phụ trách và quản
     * trị viên đều không ghi được vào hồ sơ.
     *
     * @return array<int, string>
     */
    public function responsibleOptions(): array
    {
        $matter = $this->getOwnerRecord();

        return $matter->team()
            ->where('users.is_active', true)
            ->get(['users.id', 'users.name'])
            ->filter(fn ($user): bool => Gate::forUser($user)->allows('update', $matter))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * "Thêm nhanh" của SPEC §7.2.
     *
     * Nhãn nút và tiêu đề modal viết thẳng, không mượn nhãn model: câu mặc định của Filament dựng
     * từ tên lớp, và ngay cả khi đặt `getModelLabel()` tiếng Việt thì tiêu đề vẫn bị
     * `Str::ucwords()` biến thành "Tạo Mốc Thời Hạn" — tiếng Việt không viết hoa từng chữ như
     * vậy. Cùng ghi chú `PartiesRelationManager` để lại.
     */
    private function addAction(): CreateAction
    {
        return CreateAction::make()
            ->icon(Heroicon::OutlinedCalendarDays)
            ->label(__('deadlines.tab.actions.add'))
            ->modalHeading(__('deadlines.tab.actions.add_heading'))
            ->modalSubmitActionLabel(__('deadlines.tab.actions.add_submit'))
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->using(function (CreateAction $action, array $data): Deadline {
                $created = null;

                $this->runAction($action, function () use (&$created, $data): void {
                    $created = app(AddMatterDeadline::class)->handle(
                        matter: $this->getOwnerRecord(),
                        actor: Auth::user(),
                        name: $data['name'] ?? '',
                        dueDate: $data['due_date'] ?? '',
                        severity: DeadlineSeverity::tryFrom($data['severity'] ?? '') ?? DeadlineSeverity::Normal,
                        responsible: static::resolveResponsible($data['responsible_user_id'] ?? null),
                        // Công tắc bị khoá KHÔNG được dehydrate, nên khoá này vắng mặt hẳn khỏi
                        // `$data` khi vụ việc chưa lên cổng — `?? false` là chỗ biến "vắng mặt"
                        // thành "không công bố". Xem ô `is_published` ở `form()`.
                        isPublished: (bool) ($data['is_published'] ?? false),
                    );
                });

                // `runAction()` kết thúc bằng một exception ở MỌI nhánh từ chối (`Halt` hoặc
                // `ValidationException`), nên tới được dòng này nghĩa là Action đã trả về một bản
                // ghi.
                return $created;
            })
            ->successNotificationTitle(__('deadlines.tab.actions.add_success'));
    }

    /**
     * Đổi giá trị ô chọn thành người thật — hoặc `null`.
     *
     * `withTrashed()` cùng lý do {@see ClientRequestsRelationManager::resolveAssignee()} ghi lại:
     * `User::query()->find()` trả `null` cho một tài khoản đã xoá mềm, và ở đây `null` có một
     * nghĩa KHÁC hẳn ("để hệ thống tự điền luật sư phụ trách"). Một lần giải hụt sẽ lặng lẽ đổi
     * lệnh của người dùng thành lệnh đó, và mốc được ghi cho một người họ không chọn.
     *
     * Đo bằng mutation, không bằng mắt: qua màn hình, luật `in:` mà Filament sinh từ
     * `responsibleOptions()` chặn một id ngoài danh sách trước khi tới đây, nên đổi về
     * `User::query()` KHÔNG làm test nào đỏ — nói thẳng ra thay vì để nó trông như một cổng.
     */
    public static function resolveResponsible(mixed $id): ?User
    {
        return filled($id) ? User::withTrashed()->find($id) : null;
    }

    /**
     * "Đổi người phụ trách" (fix round 1, CRITICAL) — đường ghi thứ hai vào `responsible_user_id`
     * qua {@see ChangeDeadlineResponsible}. Xem docblock của Action đó cho lý do nó phải tồn tại:
     * không có nút này, một người không phải lead vẫn còn đứng tên mốc chưa xong không bao giờ
     * nghỉ việc được.
     *
     * Cổng: cùng `DeadlinePolicy::update` với bốn nút còn lại của tab. Ô chọn dùng lại
     * {@see self::responsibleOptions()} — CÙNG danh sách với form "thêm nhanh" (đội ngũ còn đi
     * làm, qua được `matter.update`) — dù `ChangeDeadlineResponsible` tự nới nhẹ hơn ở tầng Action
     * (chỉ đòi `view`, xem docblock Action đó): danh sách này là một tập CON của những gì Action
     * chấp nhận, nên không khoá ai đúng ra sẽ được nhận qua đây.
     */
    private function changeResponsibleAction(): Action
    {
        return Action::make('changeResponsible')
            ->label(__('deadlines.tab.actions.change_responsible'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('gray')
            ->modalHeading(__('deadlines.tab.actions.change_responsible_heading'))
            ->modalSubmitActionLabel(__('deadlines.tab.actions.change_responsible_submit'))
            ->authorize(fn (Deadline $record): bool => Gate::allows('update', $record))
            // Minor (fix round 2): một mốc ĐÃ HOÀN THÀNH không còn "việc" nào để đổi người phụ
            // trách nữa — ẩn nút, cùng chỗ `ChangeDeadlineResponsible::handle()` tự chặn Ở TẦNG
            // ACTION (lớp phòng thủ thật, không chỉ ẩn nút).
            ->visible(fn (Deadline $record): bool => ! $record->is_completed)
            ->fillForm(fn (Deadline $record): array => ['responsible_user_id' => $record->responsible_user_id])
            ->schema([
                Select::make('responsible_user_id')
                    ->label(__('deadlines.tab.fields.responsible'))
                    ->options(fn (): array => $this->responsibleOptions())
                    ->required()
                    ->native(false),
            ])
            ->successNotificationTitle(__('deadlines.tab.actions.change_responsible_success'))
            ->action(fn (Action $action, Deadline $record, array $data) => $this->runAction(
                $action,
                fn () => app(ChangeDeadlineResponsible::class)->handle(
                    deadline: $record,
                    actor: Auth::user(),
                    newResponsible: User::query()->findOrFail($data['responsible_user_id'] ?? null),
                ),
            ));
    }

    private function completeAction(): Action
    {
        return Action::make('complete')
            ->label(__('deadlines.tab.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('deadlines.tab.actions.complete_heading'))
            ->modalDescription(__('deadlines.tab.actions.complete_description'))
            ->authorize(fn (Deadline $record): bool => Gate::allows('update', $record))
            ->visible(fn (Deadline $record): bool => ! $record->is_completed)
            ->successNotificationTitle(__('deadlines.tab.actions.complete_success'))
            ->action(fn (Action $action, Deadline $record) => $this->runAction(
                $action,
                fn () => app(SetDeadlineCompletion::class)->handle($record, true, Auth::user()),
            ));
    }

    /**
     * Đường lùi của cái nút trên. Nó tồn tại vì một mốc đánh dấu nhầm là một mốc mà
     * `CheckDeadlines` (Task 6) thôi nhắc — tức một hạn tố tụng im lặng cho tới ngày nó trôi qua.
     */
    private function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('deadlines.tab.actions.reopen'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('deadlines.tab.actions.reopen_heading'))
            ->modalDescription(__('deadlines.tab.actions.reopen_description'))
            ->authorize(fn (Deadline $record): bool => Gate::allows('update', $record))
            ->visible(fn (Deadline $record): bool => (bool) $record->is_completed)
            ->successNotificationTitle(__('deadlines.tab.actions.reopen_success'))
            ->action(fn (Action $action, Deadline $record) => $this->runAction(
                $action,
                fn () => app(SetDeadlineCompletion::class)->handle($record, false, Auth::user()),
            ));
    }

    /**
     * **Nút này CỐ Ý vẫn hiện khi vụ việc chưa lên cổng khách**, khác hẳn công tắc cùng tên trong
     * form thêm nhanh — và sự bất đối xứng đó là một quyết định, không phải một chỗ bỏ sót.
     *
     * Trong form, khoá công tắc lại không làm mất gì của người dùng: họ vẫn lưu được mốc, chỉ là
     * chưa gửi khách. Ở đây thì ngược lại — ẩn hoặc khoá cái nút sẽ để người dùng đứng trước một
     * hồ sơ không có đường nào gửi mốc cho khách và KHÔNG một câu nào nói vì sao. Bấm vào thì
     * {@see SetDeadlinePublication} từ chối bằng `MatterNotPublishedToPortal::forDeadline()`, và
     * câu đó nói thẳng việc cần làm tiếp theo (bật "Công bố portal" ở tab Tổng quan) — đúng thứ
     * SPEC §8.4 đòi ở một lời từ chối. `ReportsActionFailures` đưa nó ra thành một thông báo đỏ
     * `persistent()`.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('deadlines.tab.actions.publish'))
            ->icon(Heroicon::OutlinedEye)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('deadlines.tab.actions.publish_heading'))
            ->modalDescription(__('deadlines.tab.actions.publish_description'))
            // R5 (roles-05, M6.5 Task 10): 'publish', không phải 'update' — xem DeadlinePolicy::publish().
            // Nút này LUÔN bật (visible() chỉ hiện khi ! is_published), nên chiều hỏi Gate luôn là
            // `true` — fix round 1 (ruling): chỉ chiều BẬT đòi stageLog.publish.
            ->authorize(fn (Deadline $record): bool => Gate::allows('publish', [$record, true]))
            ->visible(fn (Deadline $record): bool => ! $record->is_published)
            ->successNotificationTitle(__('deadlines.tab.actions.publish_success'))
            ->action(fn (Action $action, Deadline $record) => $this->runAction(
                $action,
                fn () => app(SetDeadlinePublication::class)->handle($record, true, Auth::user()),
            ));
    }

    private function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label(__('deadlines.tab.actions.unpublish'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('deadlines.tab.actions.unpublish_heading'))
            ->modalDescription(__('deadlines.tab.actions.unpublish_description'))
            // R5 (roles-05, M6.5 Task 10): 'publish', không phải 'update' — xem DeadlinePolicy::publish().
            // Nút này LUÔN gỡ (visible() chỉ hiện khi is_published), nên chiều hỏi Gate luôn là
            // `false` — fix round 1 (ruling): chiều GỠ chỉ cần matter.update, không cần stageLog.publish.
            ->authorize(fn (Deadline $record): bool => Gate::allows('publish', [$record, false]))
            ->visible(fn (Deadline $record): bool => (bool) $record->is_published)
            ->successNotificationTitle(__('deadlines.tab.actions.unpublish_success'))
            ->action(fn (Action $action, Deadline $record) => $this->runAction(
                $action,
                fn () => app(SetDeadlinePublication::class)->handle($record, false, Auth::user()),
            ));
    }
}
