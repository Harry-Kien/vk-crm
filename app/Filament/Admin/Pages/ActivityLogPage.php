<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/**
 * Trang chỉ đọc cho nhật ký hệ thống (SPEC §7.4, §10.6), gated bằng auditLog.view — chỉ admin
 * và manager có quyền này (SPEC §5). Không có action ghi/sửa/xoá nào ở đây.
 */
class ActivityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.activity-log-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('activity.page.navigation_label');
    }

    public function getTitle(): string
    {
        return __('activity.page.title');
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can(Permission::AuditLogView->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity.page.columns.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('log_name')
                    ->label(__('activity.page.columns.log_name'))
                    ->badge(),
                TextColumn::make('event')
                    ->label(__('activity.page.columns.event'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __('activity.events.'.$state) : '—'),
                TextColumn::make('causer.name')
                    ->label(__('activity.page.columns.causer'))
                    ->default(__('activity.page.system_causer')),
                TextColumn::make('subject_type')
                    ->label(__('activity.page.columns.subject'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? class_basename($state) : null),
                TextColumn::make('description')
                    ->label(__('activity.page.columns.description'))
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('activity.page.columns.log_name'))
                    ->options(fn (): array => Activity::query()->distinct()->pluck('log_name', 'log_name')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
