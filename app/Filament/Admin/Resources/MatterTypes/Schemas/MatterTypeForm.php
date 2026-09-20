<?php

namespace App\Filament\Admin\Resources\MatterTypes\Schemas;

use App\Models\MatterType;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class MatterTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('matter_types.fields.code'))
                    ->required()
                    ->maxLength(10)
                    // `unique()` dựng luật `Rule::unique` của Laravel, thứ đếm CẢ dòng đã xoá
                    // mềm — cùng lỗi mà `matter_type_stages.key` từng mắc. `scopedUnique()` chạy
                    // truy vấn Eloquent nên SoftDeletes tự loại các dòng đã xoá; `whereNull` giữ
                    // lại để luật đọc được mà không phải nhớ điều đó, đúng thành ngữ
                    // `StagesRelationManager` đang dùng. Chốt chặn phủ mọi đường ghi khác nằm ở
                    // `MatterType::booted()`.
                    ->scopedUnique(
                        model: MatterType::class,
                        ignoreRecord: true,
                        modifyQueryUsing: fn (Builder $query): Builder => $query->whereNull('deleted_at'),
                    ),
                TextInput::make('name')
                    ->label(__('matter_types.fields.name'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('description')
                    ->label(__('matter_types.fields.description'))
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('matter_types.fields.is_active'))
                    ->default(true),
                TextInput::make('sort_order')
                    ->label(__('matter_types.fields.sort_order'))
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->required(),
            ]);
    }
}
