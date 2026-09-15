<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Matters\MatterResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Trang chi tiết vụ việc (SPEC §7.2): ba tab của M3 — Tổng quan (nội dung của chính trang này,
 * infolist ở MatterInfolist), Tiến độ và Các bên (StageLogsRelationManager /
 * PartiesRelationManager, đăng ký ở MatterResource::getRelations()) — hiển thị chung một dải tab
 * nhờ hasCombinedRelationManagerTabsWithContent(). Các tab M4/M6/M7 (Danh mục hồ sơ, Tài liệu,
 * Mốc thời hạn, Liên lạc, Yêu cầu từ khách, Nhật ký) không thuộc phạm vi task này.
 */
class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    public function getContentTabLabel(): ?string
    {
        return __('matters.tabs.overview');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    /**
     * Công tắc "công bố cho khách" của SPEC §7.2 tab Tổng quan. Đặt ở đây (page header action)
     * thay vì bên trong infolist: Infolist là schema chỉ đọc, không có điều khiển tương tác thật
     * sự (không phải Toggle của form) — một Action nằm trong Actions::make() của infolist vẫn khả
     * thi nhưng phức tạp hơn để kiểm thử mà không có lợi ích rõ ràng so với một header action tiêu
     * chuẩn của Filament, vốn đã có sẵn ở EditMatterType cho DeleteAction/RestoreAction. Chỉ ai có
     * matter.update mới thao tác được (SPEC §7.2, MatterPolicy::update).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('togglePortalPublication')
                ->label(fn (): string => $this->getRecord()->is_published_to_portal
                    ? __('matters.actions.unpublish_from_portal')
                    : __('matters.actions.publish_to_portal'))
                ->icon(fn (): Heroicon => $this->getRecord()->is_published_to_portal
                    ? Heroicon::OutlinedEyeSlash
                    : Heroicon::OutlinedEye)
                ->color(fn (): string => $this->getRecord()->is_published_to_portal ? 'gray' : 'success')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()))
                ->action(function (): void {
                    $record = $this->getRecord();
                    $record->update(['is_published_to_portal' => ! $record->is_published_to_portal]);

                    Notification::make()
                        ->title(__('matters.actions.portal_publication_toggled'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
