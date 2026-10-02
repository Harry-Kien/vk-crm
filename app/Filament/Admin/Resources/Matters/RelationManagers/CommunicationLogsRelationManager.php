<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Communication\DeleteCommunicationLog;
use App\Actions\Communication\LogCommunication;
use App\Enums\CommunicationType;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\CommunicationLog;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tab "Liên lạc" (SPEC §7.2, M7 Task 8) — màn hình đầu tiên ghi được một dòng `communication_logs`
 * (bảng có từ M1, nhưng trước tab này không ai ghi được một cuộc gọi).
 *
 * # "Dưới 15 giây"
 *
 * SPEC đặt một ràng buộc THỜI GIAN, không phải một ràng buộc tính năng: "ghi nhanh một cuộc gọi
 * trong dưới 15 giây, vì nếu mất lâu hơn thì không ai ghi". Form vì vậy chỉ có hai thứ phải làm:
 * **một lần chạm** chọn kênh (nút bấm nằm ngang, không phải một ô chọn phải mở ra) và **một ô**
 * nội dung. Thời điểm (mặc định bây giờ), người liên lạc (mặc định tên khách của vụ) và thời
 * lượng nằm trong một khối thu gọn — đã điền sẵn, chỉ mở ra khi khác. Người ghi là người đang
 * đăng nhập, không có ô.
 *
 * # Không có công tắc "khách thấy được"
 *
 * Cột `is_visible_to_client` không lên form: không màn hình portal nào đọc bảng này (SPEC §8.3,
 * phán quyết 3 của M5), và một công tắc không làm gì sẽ khiến luật sư tin rằng khách đã thấy.
 * `LogCommunication` ép `false` dù payload gửi lên gì.
 *
 * # Bằng chứng: không sửa, xoá có lý do
 *
 * Không có nút "Sửa". "Xoá" là xoá mềm kèm lý do bắt buộc qua {@see DeleteCommunicationLog}, để
 * lại một dòng audit; dòng vẫn còn trong CSDL.
 *
 * # Cổng
 *
 * Cả tab: `MatterPolicy::view` trên vụ việc chủ (kế toán có `matter.viewAny` nhưng không có
 * `matter.view`, nên tab không tồn tại cho họ). Nút ghi: `CommunicationLogPolicy::create` với
 * vụ việc; nút xoá: `CommunicationLogPolicy::delete`. Action hỏi lại cả hai dưới khoá.
 */
class CommunicationLogsRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'communicationLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('communications.tab.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('communications.label');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('communications.plural_label');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    /**
     * `maxLength()` bằng đúng trần của Action ({@see LogCommunication}): `counterpart` là
     * `string(200)`; `summary` là `TEXT` 65.535 byte nên trần ký tự bảo đảm vừa là 16.383.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ToggleButtons::make('type')
                    ->label(__('communications.tab.fields.type'))
                    ->options(collect(CommunicationType::cases())
                        ->mapWithKeys(fn (CommunicationType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->inline()
                    ->required(),
                Textarea::make('summary')
                    ->label(__('communications.tab.fields.summary'))
                    ->placeholder(__('communications.tab.fields.summary_placeholder'))
                    ->required()
                    ->maxLength(LogCommunication::SUMMARY_MAX_LENGTH)
                    ->rows(3)
                    ->autofocus(),
                Section::make(__('communications.tab.fields.details'))
                    ->description(__('communications.tab.fields.details_description'))
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label(__('communications.tab.fields.occurred_at'))
                            ->seconds(false)
                            ->default(fn () => now())
                            ->required(),
                        TextInput::make('counterpart')
                            ->label(__('communications.tab.fields.counterpart'))
                            ->default(fn (): ?string => $this->getOwnerRecord()->client?->name)
                            ->maxLength(LogCommunication::COUNTERPART_MAX_LENGTH),
                        TextInput::make('duration_minutes')
                            ->label(__('communications.tab.fields.duration_minutes'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(LogCommunication::DURATION_MAX_MINUTES),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('counterpart')
            ->emptyStateHeading(__('communications.tab.empty_state'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('communications.tab.columns.occurred_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('communications.tab.columns.type'))
                    ->badge()
                    ->formatStateUsing(fn (CommunicationType $state): string => $state->label()),
                TextColumn::make('counterpart')
                    ->label(__('communications.tab.columns.counterpart'))
                    ->wrap(),
                TextColumn::make('summary')
                    ->label(__('communications.tab.columns.summary'))
                    ->wrap(),
                TextColumn::make('duration_minutes')
                    ->label(__('communications.tab.columns.duration_minutes'))
                    ->placeholder('—'),
                TextColumn::make('author.name')
                    ->label(__('communications.tab.columns.author'))
                    ->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                $this->addAction(),
            ])
            ->recordActions([
                $this->deleteAction(),
            ])
            // `author` nạp kèm tài khoản đã xoá mềm: người ghi đã nghỉ việc vẫn phải hiện tên —
            // nhật ký là bằng chứng về AI đã nói với khách.
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with(['author' => fn (BelongsTo $author): BelongsTo => $author->withTrashed()]));
    }

    /**
     * Nhãn và tiêu đề modal viết thẳng tiếng Việt (tiêu đề mặc định của Filament viết hoa từng
     * chữ — xem ghi chú ở `DeadlinesRelationManager::addAction()`).
     */
    private function addAction(): CreateAction
    {
        return CreateAction::make()
            ->icon(Heroicon::OutlinedPhone)
            ->label(__('communications.tab.actions.add'))
            ->modalHeading(__('communications.tab.actions.add_heading'))
            ->modalSubmitActionLabel(__('communications.tab.actions.add_submit'))
            ->createAnother(false)
            ->authorize(fn (): bool => Gate::allows('create', [CommunicationLog::class, $this->getOwnerRecord()]))
            ->using(function (CreateAction $action, array $data): CommunicationLog {
                $created = null;

                $this->runAction($action, function () use (&$created, $data): void {
                    $type = CommunicationType::tryFrom((string) ($data['type'] ?? ''));

                    if ($type === null) {
                        throw ValidationException::withMessages([
                            'type' => [__('validation.required', ['attribute' => __('communications.tab.fields.type')])],
                        ]);
                    }

                    $duration = $data['duration_minutes'] ?? null;

                    $created = app(LogCommunication::class)->handle(
                        matter: $this->getOwnerRecord(),
                        actor: Auth::user(),
                        type: $type,
                        summary: (string) ($data['summary'] ?? ''),
                        counterpart: $data['counterpart'] ?? null,
                        occurredAt: $data['occurred_at'] ?? null,
                        durationMinutes: filled($duration) ? (int) $duration : null,
                    );
                });

                // `runAction()` kết thúc bằng một exception ở MỌI nhánh từ chối, nên tới đây là
                // Action đã trả về một bản ghi.
                return $created;
            })
            ->successNotificationTitle(__('communications.tab.actions.add_success'));
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('communications.tab.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalHeading(__('communications.tab.actions.delete_heading'))
            ->modalDescription(__('communications.tab.actions.delete_description'))
            ->authorize(fn (CommunicationLog $record): bool => Gate::allows('delete', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('communications.tab.fields.delete_reason'))
                    ->required()
                    ->maxLength(DeleteCommunicationLog::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('communications.tab.actions.delete_success'))
            ->action(fn (Action $action, CommunicationLog $record, array $data) => $this->runAction(
                $action,
                fn () => app(DeleteCommunicationLog::class)->handle(
                    log: $record,
                    actor: Auth::user(),
                    reason: (string) ($data['reason'] ?? ''),
                ),
            ));
    }
}
