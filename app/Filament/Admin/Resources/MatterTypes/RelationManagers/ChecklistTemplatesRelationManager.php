<?php

namespace App\Filament\Admin\Resources\MatterTypes\RelationManagers;

use App\Actions\ApplyChecklistTemplate;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Danh mục hồ sơ mẫu" trên loại vụ việc (SPEC §1, §7.4; M6.5 Task 15) — trước task này KHÔNG
 * có màn hình nào quản lý được `ChecklistTemplate`/`ChecklistTemplateItem`: `MatterTypeResource`
 * chỉ có `StagesRelationManager`, mẫu chỉ sinh ra được bằng seeder, và ba loại vụ việc không có
 * mẫu (Hình sự, Doanh nghiệp, Lao động trên dữ liệu dev) mở ra với 0 đầu mục VĨNH VIỄN — khách
 * của các vụ đó không nộp được giấy tờ nào qua cổng (finding
 * `intake-02`/`checklist-02`/`roles-06`/`spec-gap-04`, critical).
 *
 * **Quyền: chỉ `settings.manage` sửa được mẫu (task brief).** Không có `->authorize()` tường
 * minh nào ở đây — khác hẳn `ChecklistRelationManager::addItemAction()` (một `Action` thuần, mặc
 * định CHO PHÉP). `CreateAction`/`EditAction`/`DeleteAction` là ba lớp Filament có sẵn, và
 * `RelationManager::getDefaultActionAuthorizationResponse()` tự hỏi `Gate` bằng đúng ability
 * `create`/`update`/`delete` trên MODEL của quan hệ — ở đây là `ChecklistTemplate`, đã có
 * `ChecklistTemplatePolicy` (viết từ trước task này nhưng CHƯA nối vào đâu, vì không có màn hình
 * nào gọi tới) khoá cả ba ability đó vào `settings.manage`. Cùng thành ngữ với
 * `StagesRelationManager`/`MatterTypeStagePolicy` — xem docblock đôi bạn đó cho lý lẽ đầy đủ về
 * việc KHÔNG dựa một mình vào cổng của trang `EditMatterType` (chính trang đó cũng đòi
 * `settings.manage` qua `MatterTypePolicy::update()`, nên một luật sư không tới được đây theo
 * CẢ HAI đường, không chỉ một).
 *
 * **Đầu mục của một mẫu quản lý NGAY TRONG form của chính mẫu đó, bằng `Repeater::relationship()`,
 * không phải một relation manager lồng thêm.** Filament không lồng được RelationManager bên
 * trong một RelationManager khác; `Repeater::relationship()` là đường chính thống của framework
 * cho đúng hình dạng "cha có nhiều con, sửa cả hai cùng lúc trong một form" — `CreateAction` VÀ
 * `EditAction` đều gọi `$schema->saveRelationships()` như một phần bình thường của việc lấy state
 * đã validate (`Filament\Schemas\Concerns\HasState::getState()`), nên KHÔNG cần viết tay logic
 * đồng bộ thêm/sửa/xoá dòng con — Repeater tự thêm dòng mới, tự cập nhật dòng đã có (so theo
 * khoá bản ghi), và tự xoá mềm dòng bị bỏ khỏi danh sách khi form được lưu.
 *
 * **Sửa mẫu KHÔNG đổi danh mục của các vụ đã mở (task brief).** Đây không phải một luật phải cài
 * thêm ở đây — nó đã đúng từ kiến trúc của {@see ApplyChecklistTemplate}: Action đó
 * SAO CHÉP giá trị các cột vào `matter_checklist_items` đúng MỘT LẦN lúc mở vụ, không giữ khoá
 * ngoại nào trỏ ngược lại `checklist_template_items`. Sửa một `ChecklistTemplateItem` sau đó chỉ
 * đổi dữ liệu MẪU, các dòng đã sao chép trước đó không đọc lại nó bao giờ.
 */
class ChecklistTemplatesRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistTemplates';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matter_types.checklist_templates.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('matter_types.checklist_templates.fields.name'))
                    ->required()
                    // Khớp cột `checklist_templates.name` (migration `2026_09_14_000009`,
                    // `string(150)`) — MariaDB strict mode biến việc vượt quá thành lỗi 500.
                    ->maxLength(150),
                Toggle::make('is_active')
                    ->label(__('matter_types.checklist_templates.fields.is_active'))
                    ->default(true)
                    ->helperText(__('matter_types.checklist_templates.fields.is_active_help')),
                // M6.5 Task 17 (rà soát Task 15): `->relationship()` để Filament TỰ ghi các dòng
                // `checklist_template_items` (tạo/sửa/xoá) đi thẳng qua Eloquent, không qua một
                // Action nào — nên `ChecklistTemplateItemPolicy` KHÔNG được hỏi ở đây. Cổng thật
                // duy nhất là `ChecklistTemplatePolicy` (`settings.manage`) gác cả relation
                // manager này, đúng như một tài nguyên CON được biên tập cùng cha của nó.
                Repeater::make('items')
                    ->relationship()
                    ->label(__('matter_types.checklist_templates.items.title'))
                    ->addActionLabel(__('matter_types.checklist_templates.items.add'))
                    ->defaultItems(0)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('matter_types.checklist_templates.item_fields.name'))
                            ->required()
                            // Khớp cột `checklist_template_items.name` (migration
                            // `2026_09_14_000010`, `string(200)`).
                            ->maxLength(200),
                        Textarea::make('description')
                            ->label(__('matter_types.checklist_templates.item_fields.description'))
                            ->columnSpanFull(),
                        Toggle::make('is_required')
                            ->label(__('matter_types.checklist_templates.item_fields.is_required'))
                            ->default(false),
                        TextInput::make('sort_order')
                            ->label(__('matter_types.checklist_templates.item_fields.sort_order'))
                            ->numeric()
                            ->integer()
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('matter_types.checklist_templates.fields.name'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('matter_types.checklist_templates.fields.is_active'))
                    ->boolean(),
                // `items_count` đến từ `withCount('items')` ở `modifyQueryUsing()` bên dưới —
                // cùng thành ngữ `ClientRequestsRelationManager` dùng cho `replies_count`, không
                // phải một truy vấn đếm viết tay thứ hai.
                TextColumn::make('items_count')
                    ->label(__('matter_types.checklist_templates.fields.items_count')),
            ])
            ->defaultSort('name')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('items'));
    }
}
