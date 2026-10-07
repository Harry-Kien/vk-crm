<?php

namespace App\Filament\Admin\Resources\MatterTypes\RelationManagers;

use App\Models\MatterTypeStage;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Giai đoạn là dữ liệu con của loại vụ việc, không phải quan hệ nhiều-nhiều: không có
 * associate/dissociate, xoá loại vụ việc kéo theo xoá giai đoạn (cascadeOnDelete).
 *
 * `key` không còn unique ở DB (M1 để lại lỗi: unique tính cả dòng đã xoá mềm, MariaDB không có
 * unique một phần) — duy nhất được kiểm tra ở đây, chỉ tính các dòng còn sống của cùng loại vụ
 * việc, để xoá mềm một giai đoạn rồi tạo lại cùng `key` không bị chặn.
 */
class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matter_types.stages.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label(__('matter_types.stage_fields.key'))
                    ->required()
                    ->maxLength(40)
                    ->scopedUnique(
                        model: MatterTypeStage::class,
                        ignoreRecord: true,
                        modifyQueryUsing: fn (Builder $query, RelationManager $livewire): Builder => $query
                            ->where('matter_type_id', $livewire->getOwnerRecord()->getKey())
                            ->whereNull('deleted_at'),
                    )
                    // Task 19: tầng form của MatterTypeStage::isKeyInUse() — bắt đúng lúc admin
                    // BẤM nút sửa (không chỉ ẩn/khoá ô), gắn lỗi vào đúng ô `key` thay vì một
                    // notification trôi nổi. `$record?->key` là giá trị CŨ (form chưa lưu gì),
                    // nên so sánh $value === $record->key loại đúng trường hợp lưu lại không đổi
                    // (không trip guard), khớp cặp dương "lets a stage save again without
                    // changing its key". Model::booted() (static::saving) là chốt chặn thứ hai,
                    // phủ mọi đường ghi không qua form này (Action, artisan, seeder, factory).
                    //
                    // Task 19, vòng sửa 1 (Critical): kiểm `referencingStageLabels()` TRƯỚC —
                    // đây là nhánh của `isKeyInUse()` cần một câu NÊU TÊN giai đoạn đang trỏ tới,
                    // không phải câu chung chung `key_locked`. Không tách nhánh này thì
                    // `isKeyInUse()` vẫn từ chối đúng (nó đã gộp mọi điều kiện), nhưng người
                    // bấm chỉ đọc được "đang có hồ sơ hoặc dòng tiến độ dùng" — sai lý do thật.
                    //
                    // M9 Task 6: cùng cách cho nhánh đợt thanh toán đang chờ giai đoạn này — một
                    // câu NÊU SỐ ĐỢT (`instalmentsAwaitingHereCount()`, cùng hàm đếm với chốt ở
                    // model và luật xoá ở policy), để admin biết phải sửa lịch thu, không phải đi
                    // tìm hồ sơ hay dòng tiến độ nào.
                    ->rule(fn (?MatterTypeStage $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null || $value === $record->key) {
                            return;
                        }

                        $referencingLabels = $record->referencingStageLabels();

                        if ($referencingLabels->isNotEmpty()) {
                            $fail(__('matter_types.stage_fields.key_locked_allowed_next', [
                                'labels' => $referencingLabels->implode(', '),
                            ]));

                            return;
                        }

                        $awaitingInstalments = $record->instalmentsAwaitingHereCount();

                        if ($awaitingInstalments > 0) {
                            $fail(__('matter_types.stage_fields.key_locked_instalments', ['count' => $awaitingInstalments]));

                            return;
                        }

                        if ($record->isKeyInUse()) {
                            $fail(__('matter_types.stage_fields.key_locked'));
                        }
                    }),
                TextInput::make('label')
                    ->label(__('matter_types.stage_fields.label'))
                    ->required()
                    ->maxLength(120),
                TextInput::make('client_label')
                    ->label(__('matter_types.stage_fields.client_label'))
                    ->required()
                    ->maxLength(120),
                Textarea::make('client_description')
                    ->label(__('matter_types.stage_fields.client_description'))
                    ->columnSpanFull(),
                TextInput::make('sort_order')
                    ->label(__('matter_types.stage_fields.sort_order'))
                    ->numeric()
                    ->integer()
                    // Task 19: cột DB là unsignedInteger (migration 2026_09_14_000004) — một giá
                    // trị âm qua thẳng form là một lỗi 500 trên MariaDB strict, SQLite của bộ test
                    // không thấy (xem intake/intake-08, cùng bài học).
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_terminal')
                    ->label(__('matter_types.stage_fields.is_terminal'))
                    // Final review X9: tầng form của chốt chặn `MatterTypeStage::booted()` — đổi
                    // cờ này khi còn hồ sơ đứng ở giai đoạn đổi nghĩa `closed_at` của họ.
                    ->rule(fn (?MatterTypeStage $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null || (bool) $value === (bool) $record->is_terminal) {
                            return;
                        }

                        $standing = $record->mattersStandingHereCount();

                        if ($standing > 0) {
                            $fail(__('matter_types.stage_fields.is_terminal_locked', ['count' => $standing]));
                        }
                    })
                    ->default(false),
                Select::make('allowed_next')
                    ->label(__('matter_types.stage_fields.allowed_next'))
                    ->multiple()
                    ->options(function (RelationManager $livewire, ?MatterTypeStage $record): array {
                        return $livewire->getOwnerRecord()->stages()
                            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                            ->pluck('label', 'key')
                            ->all();
                    })
                    ->columnSpanFull(),
                TextInput::make('default_next_update_days')
                    ->label(__('matter_types.stage_fields.default_next_update_days'))
                    ->numeric()
                    ->integer()
                    ->default(14)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('key')
                    ->label(__('matter_types.stage_fields.key'))
                    ->searchable(),
                TextColumn::make('label')
                    ->label(__('matter_types.stage_fields.label'))
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label(__('matter_types.stage_fields.sort_order'))
                    ->sortable(),
                IconColumn::make('is_terminal')
                    ->label(__('matter_types.stage_fields.is_terminal'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                // Task 19: authorizationNotification() giữ nút hiển thị khi MatterTypeStagePolicy::delete()
                // từ chối kèm lý do (Response::deny()) — không có nó, CanBeHidden mặc định ẩn hẳn
                // nút và admin không hiểu vì sao (cùng kỹ thuật EditClient::getHeaderActions()).
                DeleteAction::make()
                    ->authorizationNotification(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Task 19 (controller decision, C1-class bulk-action hole): không có
                    // authorizeIndividualRecords(), MatterTypeStagePolicy::deleteAny() (cổng thô)
                    // chỉ quyết định nút có bấm được không — Filament vẫn xoá MỌI dòng đã chọn mà
                    // không hỏi lại delete() cho từng dòng, bỏ qua thẳng luật "còn hồ sơ đứng ở
                    // giai đoạn" / "còn nằm trong allowed_next" per-record.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords('forceDelete'),
                    RestoreBulkAction::make()
                        ->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]));
    }
}
