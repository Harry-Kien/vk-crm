<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Pages;

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\IntakeSummaryGate;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeIdentity;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\IntakeRequests\Concerns\TranslatesIntakeFailures;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Schemas\IntakeRequestForm;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Normalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Trang làm việc của MỘT lần tiếp nhận (M10 Task 3) — biểu mẫu hai phần theo R1. Trang không có
 * nghiệp vụ riêng: mọi thao tác gọi một Action ở `app/Actions/Intake`, và mọi lời từ chối thành lỗi
 * tiếng Việt (form chính: {@see TranslatesIntakeFailures}; modal: `ReportsActionFailures`).
 *
 * **Phần danh tính** (form chính, nút Lưu): `UpdateIntakeIdentity` — sửa xong là kiểm tra lại ngay
 * nếu danh tính đổi. Ô CCCD và định danh của bên đối lập để trống nghĩa là GIỮ số đã lưu (chỉ dấu
 * băm được lưu, R7). Sau khi lưu, form nạp lại từ bản ghi (dòng bên đối lập mới nhận `id` thật; ô
 * câu chuyện đang gõ dở được giữ nguyên).
 *
 * **Phần câu chuyện** (khối "Câu chuyện", nút "Lưu câu chuyện"): ô `summary` KHÔNG BAO GIỜ đi theo
 * nút Lưu của form (`dehydrated(false)`) — chỉ `UpdateIntakeSummary` ghi nó, và cổng thật nằm ở đó
 * (`IntakeSummaryGate`). Ô bị khoá (`disabled`) khi cổng đóng, và khối nói BẰNG LỜI vì sao (chưa
 * ghi nhận thông báo / chưa kiểm tra / cần xác nhận / Đỏ, chờ quản lý / đã từ chối) và phải làm gì.
 * Nút "Lưu câu chuyện" vẫn bấm được khi ô khoá: một request sửa tay gửi thẳng `data.summary` đi tới
 * đúng Action, và Action từ chối (lỗi gắn vào ô câu chuyện) — cổng không phụ thuộc vào việc ô bị khoá.
 *
 * **Khối "Kiểm tra xung đột lợi ích":** ngày giờ lần kiểm tra gần nhất (không bao giờ chữ "đã kiểm
 * tra"), bảng kết quả đã lưu (`conflict_result`, cùng ranh giới `ConflictMatch`), và ba nút:
 * "Kiểm tra lại" (`RerunIntakeConflictCheck`), "Xác nhận đã xem các khớp" (`AcknowledgeIntakeConflict`,
 * chỉ hiện khi cổng đòi xác nhận — không hiện khi Đỏ đang chờ), "Xử lý mức đỏ"
 * (`ResolveIntakeRedConflict`, hiện theo `hasUnresolvedRed()` — Đỏ DÍNH, không theo `conflict_level` — và
 * chỉ cho `resolveConflict`: quản lý/admin). Modal ghi đè nói rõ khi bản ghi từng ra Đỏ mà lần chạy
 * gần nhất không còn Đỏ.
 *
 * **Hành động trên đầu trang:** "Đổi trạng thái" (`ChangeIntakeStatus`, chỉ các bước người ta tự đặt),
 * "Từ chối" (`DeclineIntake`; công tắc "vì xung đột" chỉ hiện với `resolveConflict`), "Gộp vào bản ghi
 * khác" (`MergeIntake`; chỉ các bản còn mở người dùng xem được). "Xoá dữ liệu theo yêu cầu" (R7c) là
 * của Task 7 — Action ẩn danh chưa có.
 *
 * **R8 — lý do từ chối vì xung đột:** chỉ người qua `viewConflictReason` thấy lý do và chữ "vì xung
 * đột"; người khác thấy "Văn phòng từ chối" và câu trả lời ra ngoài. `mutateFormDataBeforeFill()`
 * chỉ đưa đúng các ô của form vào trạng thái Livewire — mặc định Filament gửi MỌI thuộc tính của model
 * xuống trình duyệt, kể cả `decline_reason`, `conflict_override_reason` và các dấu băm.
 *
 * **Bản ghi đã xong việc** (`isClosedToChanges()`: đã gộp, đã ẩn danh, đã chuyển thành vụ): cả form chỉ
 * đọc, không nút Lưu, không hành động nào.
 *
 * **Bản ghi đã từ chối** (`isClosedToIdentityEdits()`, fix vòng 1 — rà soát Task 3, C1): phần danh tính
 * và phần bên đối lập chỉ đọc, không nút Lưu (nút đó chỉ lưu danh tính) — với mọi người, vì mọi lý do
 * từ chối (R8). `UpdateIntakeIdentity` là cổng thật; ở đây chỉ để không ai gõ rồi mới bị từ chối. Các
 * khối khác và các hành động trên đầu trang (gộp đi, theo luật của `MergeIntake`) giữ nguyên.
 *
 * Không transaction ngoài (`hasDatabaseTransactions()` false): các Action tự quản transaction dưới khoá
 * `conflict-check`.
 */
class EditIntakeRequest extends EditRecord
{
    use ReportsActionFailures;
    use TranslatesIntakeFailures;

    protected static string $resource = IntakeRequestResource::class;

    /**
     * Gợi ý trùng của lần ghi nhận vừa xong (R4), chỉ id và cờ — `CreateIntakeRequest` cất vào phiên,
     * trang này đọc MỘT lần lúc mở. `#[Locked]`: một payload sửa tay không được tự thêm id để trang
     * hiện tên một bản ghi (dù hàng hiển thị vẫn được lọc lại qua `visibleTo()`).
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $duplicates = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->duplicates = session()->pull(CreateIntakeRequest::duplicatesSessionKey($this->intake()->getKey()));
    }

    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (): bool => $this->intake()->isClosedToChanges())
            ->components([
                Section::make(__('intake.sections.decision'))
                    ->columnSpanFull()
                    ->visible(fn (): bool => in_array($this->intake()->status, [IntakeStatus::Declined, IntakeStatus::Merged], true))
                    ->schema([View::make('filament.intake.decision')->viewData(fn (): array => $this->decisionViewData())]),
                // `dehydrated()`: khối khoá vẫn gửi giá trị đang có, để một lần lưu lọt tới (request sửa
                // tay, hay bản ghi vừa bị gộp/từ chối ở tab khác) nhận đúng lời từ chối của Action, không
                // phải lỗi "thiếu tên" vì các ô bị bỏ khỏi dữ liệu.
                IntakeRequestForm::identitySection(editing: true)
                    ->disabled(fn (): bool => $this->intake()->isClosedToIdentityEdits())
                    ->dehydrated(),
                IntakeRequestForm::partiesSection(editing: true)
                    ->disabled(fn (): bool => $this->intake()->isClosedToIdentityEdits())
                    ->dehydrated(),
                Section::make(__('intake.sections.privacy'))
                    ->columnSpanFull()
                    ->schema([
                        Text::make(__('intake.privacy_notice.text')),
                        Text::make(fn (): string => $this->privacyStatus()),
                        Actions::make([$this->recordPrivacyNoticeAction()])->key('privacyActions'),
                    ]),
                Section::make(__('intake.sections.check'))
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.intake.check-panel')->viewData(fn (): array => $this->checkPanelViewData()),
                        Actions::make([$this->rerunAction(), $this->acknowledgeAction(), $this->resolveRedAction()])->key('checkActions'),
                    ]),
                Section::make(__('intake.sections.duplicates'))
                    ->columnSpanFull()
                    ->visible(fn (): bool => $this->hasDuplicateHints())
                    ->schema([View::make('filament.intake.duplicates')->viewData(fn (): array => $this->duplicatesViewData())]),
                Section::make(__('intake.sections.story'))
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.intake.gate-status')->viewData(fn (): array => $this->gateViewData()),
                        Textarea::make('summary')
                            ->label(__('intake.fields.summary'))
                            ->helperText(__('intake.fields.summary_help_open'))
                            ->rows(8)
                            // Cột `text` (65.535 BYTE); `UpdateIntakeSummary` chặn ở 60.000 byte. 20.000 ký
                            // tự × tối đa 3 byte một ký tự tiếng Việt vừa dưới trần đó.
                            ->maxLength(20000)
                            ->dehydrated(false)
                            ->disabled(fn (): bool => ! IntakeSummaryGate::isOpen($this->intake())),
                        Actions::make([$this->saveSummaryAction()])->key('storyActions'),
                    ]),
            ]);
    }

    /**
     * CHỈ các ô của form (R8, R7) — xem docblock lớp. Số CCCD và định danh bên đối lập luôn rỗng: số
     * gốc không được lưu, ô để trống nghĩa là giữ.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $intake = $this->intake();

        return [
            'contact_name' => $intake->contact_name,
            'contact_phone' => $intake->contact_phone,
            'contact_email' => $intake->contact_email,
            'contact_id_number' => null,
            'contact_role' => $intake->contact_role?->value,
            'source' => $intake->source?->value,
            'referred_by' => $intake->referred_by,
            'matter_type_id' => $intake->matter_type_id,
            'quoted_amount' => $intake->quoted_amount === null ? null : Money::formatForInput($intake->quoted_amount),
            'assigned_to' => $intake->assigned_to,
            'summary' => $intake->summary,
            'parties' => $intake->parties()->orderBy('id')->get()
                ->map(fn (IntakeParty $party): array => [
                    'id' => $party->getKey(),
                    'role' => $party->role?->value,
                    'name' => $party->name,
                    'phone' => null,
                    'id_number' => null,
                ])
                ->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->actor();

        $this->translatingIntakeFailures(fn () => app(UpdateIntakeIdentity::class)->handle($actor, $record, $data, $data['parties'] ?? []));

        return $record->refresh();
    }

    /** Nạp lại form từ bản ghi (id thật cho dòng bên đối lập mới), giữ câu chuyện đang gõ dở. */
    protected function afterSave(): void
    {
        $summary = $this->data['summary'] ?? null;

        $this->fillForm();

        $this->data['summary'] = $summary;
    }

    /** @return array<int, Action> */
    protected function getFormActions(): array
    {
        return $this->intake()->isClosedToIdentityEdits() ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        return [$this->changeStatusAction(), $this->declineAction(), $this->mergeAction()];
    }

    private function intake(): IntakeRequest
    {
        /** @var IntakeRequest $record */
        $record = $this->getRecord();

        return $record;
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function isOpenStep(): bool
    {
        return ! $this->intake()->isClosedToChanges() && in_array($this->intake()->status, ChangeIntakeStatus::OPEN, true);
    }

    private function afterIntakeAction(string $message): void
    {
        $this->intake()->refresh();

        Notification::make()->title($message)->success()->send();
    }

    // ---------------------------------------------------------------- hành động

    private function saveSummaryAction(): Action
    {
        return Action::make('saveSummary')
            ->label(__('intake.actions.save_summary'))
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges())
            ->action(function (): void {
                $summary = $this->data['summary'] ?? null;

                $this->translatingIntakeFailures(fn () => app(UpdateIntakeSummary::class)->handle(
                    $this->actor(), $this->intake(), is_string($summary) ? $summary : null,
                ));

                $this->afterIntakeAction(__('intake.actions.summary_saved'));
            });
    }

    private function recordPrivacyNoticeAction(): Action
    {
        return Action::make('recordPrivacyNotice')
            ->label(__('intake.actions.record_privacy_notice'))
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges()
                && ($this->intake()->privacy_notice_acknowledged_at === null
                    || $this->intake()->privacy_notice_version !== (string) __('intake.privacy_notice.version')))
            ->modalDescription(__('intake.privacy_notice.text'))
            ->schema([
                Checkbox::make('privacy_notice')
                    ->label(__('intake.fields.privacy_notice'))
                    ->helperText(__('intake.fields.privacy_notice_help'))
                    ->default(false),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(RecordPrivacyNotice::class)->handle(
                    $this->actor(), $this->intake(), (bool) ($data['privacy_notice'] ?? false),
                ));

                $this->afterIntakeAction(__('intake.actions.privacy_notice_recorded'));
            });
    }

    private function rerunAction(): Action
    {
        return Action::make('rerun')
            ->label(__('intake.actions.rerun'))
            ->color('gray')
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges())
            ->action(function (Action $action): void {
                $this->runAction($action, fn () => app(RerunIntakeConflictCheck::class)->handle($this->actor(), $this->intake()));

                $this->afterIntakeAction(__('intake.actions.rerun_done'));
            });
    }

    private function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')
            ->label(__('intake.actions.acknowledge'))
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('intake.actions.acknowledge_description'))
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges()
                && in_array(IntakeSummaryBlocker::ConflictAcknowledgement, IntakeSummaryGate::blockers($this->intake()), true))
            ->action(function (Action $action): void {
                $intake = $this->intake();

                $this->runAction($action, fn () => app(AcknowledgeIntakeConflict::class)->handle(
                    $this->actor(), $intake, $intake->conflict_level ?? ConflictLevel::Green,
                ));

                $this->afterIntakeAction(__('intake.actions.acknowledged'));
            });
    }

    private function resolveRedAction(): Action
    {
        return Action::make('resolveRed')
            ->label(__('intake.actions.resolve_red'))
            ->color('danger')
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges()
                && $this->intake()->status !== IntakeStatus::Declined
                && $this->intake()->hasUnresolvedRed()
                && Gate::allows('resolveConflict', $this->intake()))
            ->modalDescription(fn (): string => $this->intake()->conflict_level === ConflictLevel::Red
                ? __('intake.actions.resolve_red_description')
                : __('intake.actions.resolve_red_description').' '.__('intake.gate.red_was_shown'))
            ->schema([
                Textarea::make('override_reason')
                    ->label(__('intake.fields.override_reason'))
                    ->required()
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(ResolveIntakeRedConflict::class)->handle(
                    $this->actor(), $this->intake(), (string) ($data['override_reason'] ?? ''),
                ));

                $this->afterIntakeAction(__('intake.actions.red_resolved'));
            });
    }

    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('intake.actions.change_status'))
            ->color('gray')
            ->visible(fn (): bool => $this->isOpenStep())
            ->schema([
                Select::make('status')
                    ->label(__('intake.fields.new_status'))
                    ->options(fn (): array => collect(ChangeIntakeStatus::TARGETS)
                        ->reject(fn (IntakeStatus $status): bool => $status === $this->intake()->status)
                        ->mapWithKeys(fn (IntakeStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->native(false)
                    ->required(),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(ChangeIntakeStatus::class)->handle(
                    $this->actor(), $this->intake(), IntakeStatus::from((string) $data['status']),
                ));

                $this->afterIntakeAction(__('intake.actions.status_changed'));
            });
    }

    private function declineAction(): Action
    {
        return Action::make('decline')
            ->label(__('intake.actions.decline'))
            ->color('danger')
            ->visible(fn (): bool => $this->isOpenStep())
            ->schema([
                Textarea::make('decline_reason')
                    ->label(__('intake.fields.decline_reason'))
                    ->required()
                    ->maxLength(2000)
                    ->rows(4),
                Toggle::make('decline_for_conflict')
                    ->label(__('intake.fields.decline_for_conflict'))
                    ->helperText(__('intake.fields.decline_for_conflict_help'))
                    ->default(false)
                    ->visible(fn (): bool => Gate::allows('resolveConflict', $this->intake())),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(DeclineIntake::class)->handle(
                    $this->actor(),
                    $this->intake(),
                    (string) ($data['decline_reason'] ?? ''),
                    (bool) ($data['decline_for_conflict'] ?? false),
                ));

                $this->afterIntakeAction(__('intake.actions.declined'));
            });
    }

    private function mergeAction(): Action
    {
        return Action::make('merge')
            ->label(__('intake.actions.merge'))
            ->color('gray')
            ->visible(fn (): bool => ! $this->intake()->isClosedToChanges())
            ->schema([
                Select::make('merge_target')
                    ->label(__('intake.fields.merge_target'))
                    ->helperText(__('intake.fields.merge_target_help'))
                    ->options(fn (): array => $this->mergeTargetOptions())
                    ->searchable()
                    ->native(false)
                    ->required(),
            ])
            ->action(function (Action $action, array $data): void {
                $target = IntakeRequest::query()->visibleTo($this->actor())->find($data['merge_target'] ?? null);

                $this->runAction($action, fn () => app(MergeIntake::class)->handle(
                    $this->actor(), $this->intake(), $target ?? abort(404),
                ));

                $this->afterIntakeAction(__('intake.actions.merged'));
            });
    }

    /**
     * Các bản có thể gộp vào: còn mở (chưa gộp, chưa chuyển đổi, chưa ẩn danh — `openForConflictCheck`),
     * người dùng xem được, khác bản này; mới nhất trước, tối đa 50.
     *
     * @return array<int, string>
     */
    private function mergeTargetOptions(): array
    {
        return IntakeRequest::query()
            ->visibleTo($this->actor())
            ->openForConflictCheck()
            ->whereKeyNot($this->intake()->getKey())
            ->orderByDesc('received_at')
            ->limit(50)
            ->get(['id', 'code', 'contact_name'])
            ->mapWithKeys(fn (IntakeRequest $intake): array => [$intake->getKey() => "{$intake->code} — {$intake->contact_name}"])
            ->all();
    }

    // ---------------------------------------------------------------- dữ liệu cho các view

    private function privacyStatus(): string
    {
        $intake = $this->intake();

        return $intake->privacy_notice_acknowledged_at === null
            ? __('intake.privacy.not_recorded')
            : __('intake.privacy.recorded', [
                'version' => $intake->privacy_notice_version,
                'at' => $intake->privacy_notice_acknowledged_at->format('d/m/Y H:i'),
            ]);
    }

    /** @return array<string, mixed> */
    private function gateViewData(): array
    {
        $blockers = IntakeSummaryGate::blockers($this->intake());

        return [
            'open' => $blockers === [],
            'lockedHeading' => __('intake.gate.locked'),
            'blockers' => array_map(fn (IntakeSummaryBlocker $blocker): array => [
                'label' => $blocker->label(),
                'hint' => __('intake.gate.hint_'.$blocker->value),
            ], $blockers),
        ];
    }

    /** @return array<string, mixed> */
    private function checkPanelViewData(): array
    {
        $intake = $this->intake();
        $result = is_array($intake->conflict_result) ? $intake->conflict_result : null;

        return [
            'checkedAt' => $intake->conflict_checked_at?->format('d/m/Y H:i'),
            'result' => $result === null ? null : [
                ...static::conflictTableData($result),
                // Mức đã lưu vẫn là Đỏ sau khi ghi đè (ghi đè không đổi kết quả); câu tiêu đề không được
                // nói 'đang khoá' khi cổng đã mở. Lý do ghi đè KHÔNG hiện ở đây (R8, rà soát Task 2 I1).
                'headingRed' => $intake->hasConflictOverride() ? __('intake.check.heading_red_overridden') : __('intake.check.heading_red'),
                'headingAttention' => __('intake.check.heading_attention'),
                'headingClear' => __('intake.check.heading_clear'),
                'intro' => __('intake.check.intro'),
            ],
            'carried' => $result !== null && $this->hasCarriedMatches($intake, $result),
        ];
    }

    /**
     * Cùng phép dịch `CreateMatter::conflictResultViewData()` (đúng tám khoá của `ConflictMatch::toArray()`,
     * không thêm gì), cho kết quả ĐÃ LƯU của một lần tiếp nhận. Khớp của nguồn thứ hai mang nhãn "Đã
     * liên hệ văn phòng ngày …" ở cột loại vụ việc và mã `TN-…` ở cột mã hồ sơ (Task 2).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function conflictTableData(array $result): array
    {
        $level = $result['level'] ?? ConflictLevel::Green->value;
        $incomplete = $result['incomplete_parties'] ?? [];

        $format = fn (array $match, bool $alreadyConfirmed): array => [
            'matter_code' => $match['matter_code'],
            'matter_type_name' => $match['matter_type_name'],
            'party_role' => PartyRole::from($match['party_role'])->label(),
            'party_name' => $match['party_name'],
            'tier' => ConflictMatchTier::from($match['tier'])->label(),
            'level' => ConflictLevel::from($match['level'])->label(),
            'our_party_role' => PartyRole::from($match['our_party_role'])->label(),
            'our_party_name' => $match['our_party_name'],
            'already_confirmed' => $alreadyConfirmed,
        ];

        return [
            'level' => $level,
            'requiresAttention' => $level !== ConflictLevel::Green->value || $incomplete !== [],
            'matches' => [
                ...array_map(fn (array $match): array => $format($match, false), $result['matches'] ?? []),
                ...array_map(fn (array $match): array => $format($match, true), $result['confirmed_matches'] ?? []),
            ],
            'incompleteParties' => $incomplete,
        ];
    }

    /**
     * Có khớp nào mà "bên phía mình" KHÔNG phải người liên hệ hay một bên đối lập của chính bản ghi
     * này — tức bên đối lập mà cùng người đã khai ở một lần gọi trước, được `RunConflictCheck` mang
     * sang (fix vòng 1 của Task 2, C1). Màn hình nói điều đó bằng chữ, không thêm cột vào bảng.
     *
     * @param  array<string, mixed>  $result
     */
    private function hasCarriedMatches(IntakeRequest $intake, array $result): bool
    {
        $own = collect([$intake->contact_name, ...$intake->parties()->pluck('name')->all()])
            ->map(fn (?string $name): ?string => Normalizer::name($name))
            ->filter()
            ->all();

        return collect([...($result['matches'] ?? []), ...($result['confirmed_matches'] ?? [])])
            ->contains(fn (array $match): bool => ! in_array(Normalizer::name($match['our_party_name'] ?? null), $own, true));
    }

    private function hasDuplicateHints(): bool
    {
        $data = $this->duplicatesViewData();

        return $data['sameIdentity'] !== [] || $data['hasHidden'] || $data['sameName'] !== []
            || $data['isClient'] || $data['lookupUnavailable'];
    }

    /**
     * Hàng hiển thị của gợi ý trùng (R4), dựng lại từ id qua `visibleTo()` — kể cả khi id đến từ
     * phiên của chính người này. Trùng TÊN chỉ dựng khi người xem có `intake.viewAny`.
     *
     * @return array<string, mixed>
     */
    private function duplicatesViewData(): array
    {
        $data = $this->duplicates ?? [];
        $actor = $this->actor();

        $rows = fn (array $ids): array => $ids === [] ? [] : IntakeRequest::query()
            ->visibleTo($actor)
            ->whereKey($ids)
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (IntakeRequest $intake): array => [
                'code' => $intake->code,
                'name' => $intake->contact_name,
                'received_at' => $intake->received_at?->format('d/m/Y H:i'),
                'url' => IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'),
            ])
            ->all();

        return [
            'sameIdentity' => $rows($data['same_identity'] ?? []),
            'hasHidden' => (bool) ($data['has_hidden'] ?? false),
            'sameName' => $actor->can(Permission::IntakeViewAny->value) ? $rows($data['same_name'] ?? []) : [],
            'isClient' => (bool) ($data['is_client'] ?? false),
            'lookupUnavailable' => (bool) ($data['lookup_unavailable'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function decisionViewData(): array
    {
        $intake = $this->intake();
        $declined = $intake->status === IntakeStatus::Declined;
        $mayKnowConflict = Gate::allows('viewConflictReason', $intake);
        $target = $intake->merged_into_id === null ? null : IntakeRequest::query()->find($intake->merged_into_id);

        return [
            'declined' => $declined,
            'declinedLabel' => IntakeStatus::Declined->label(),
            'forConflict' => $declined && $intake->decline_reason_is_conflict && $mayKnowConflict,
            'reason' => $declined && (! $intake->decline_reason_is_conflict || $mayKnowConflict) ? $intake->decline_reason : null,
            'mergedInto' => $target === null ? null : [
                'code' => $target->code,
                'url' => Gate::allows('view', $target) ? IntakeRequestResource::getUrl('edit', ['record' => $target], panel: 'admin') : null,
            ],
        ];
    }
}
