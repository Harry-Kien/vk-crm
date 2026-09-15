<?php

namespace App\Filament\Admin\Resources\Matters\Tables;

use App\Enums\Permission;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MattersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('matters.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label(__('matters.fields.client'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('matterType.name')
                    ->label(__('matters.fields.matter_type'))
                    ->searchable()
                    ->sortable(),
                // Kế toán có matter.viewAny nhưng không có matter.view: danh sách rút gọn, ẩn
                // nội dung vụ việc (SPEC §5).
                TextColumn::make('title')
                    ->label(__('matters.fields.title'))
                    ->searchable()
                    ->visible(fn (): bool => (bool) Auth::user()?->can(Permission::MatterView->value)),
                TextColumn::make('summary_for_client')
                    ->label(__('matters.fields.summary_for_client'))
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) Auth::user()?->can(Permission::MatterView->value)),
                TextColumn::make('stage')
                    ->label(__('matters.fields.stage'))
                    ->badge()
                    ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage)
                    ->color(fn (Matter $record): string => $record->currentStage()?->is_terminal ? 'success' : 'info'),
                TextColumn::make('leadLawyer.name')
                    ->label(__('matters.fields.lead_lawyer'))
                    ->searchable()
                    ->sortable(),
                // "12 ngày trước", tô vàng khi > 10 ngày, đỏ khi > 14 (SPEC §7.2).
                TextColumn::make('last_client_update_at')
                    ->label(__('matters.fields.last_client_update_at'))
                    ->since()
                    ->color(function (Matter $record): ?string {
                        if (! $record->last_client_update_at) {
                            return null;
                        }

                        $daysSinceUpdate = $record->last_client_update_at->diffInDays(now());

                        return match (true) {
                            $daysSinceUpdate > 14 => 'danger',
                            $daysSinceUpdate > 10 => 'warning',
                            default => null,
                        };
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->label(__('matters.filters.stage'))
                    ->options(fn (): array => MatterTypeStage::query()->orderBy('label')->pluck('label', 'key')->all()),
                SelectFilter::make('matter_type_id')
                    ->label(__('matters.filters.matter_type'))
                    ->relationship('matterType', 'name'),
                SelectFilter::make('lead_lawyer_id')
                    ->label(__('matters.filters.lead_lawyer'))
                    ->relationship('leadLawyer', 'name'),
                TernaryFilter::make('is_published_to_portal')
                    ->label(__('matters.filters.is_published_to_portal')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
