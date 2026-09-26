<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\SetMatterPortalPublication;
use App\Filament\Admin\Resources\Matters\MatterResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Trang chi tiết vụ việc (SPEC §7.2): bảy tab — Tổng quan (nội dung của chính trang này,
 * infolist ở MatterInfolist), Tiến độ, Danh mục hồ sơ, Tài liệu, Các bên, Yêu cầu từ khách và
 * Mốc thời hạn (StageLogsRelationManager / ChecklistRelationManager / DocumentsRelationManager /
 * PartiesRelationManager / ClientRequestsRelationManager / DeadlinesRelationManager, đăng ký ở
 * MatterResource::getRelations()) — hiển thị chung một dải tab nhờ
 * hasCombinedRelationManagerTabsWithContent(). Các tab M7 (Liên lạc, Nhật ký) chưa xây.
 *
 * Nhãn "Khách đã xem lúc …" / "Khách chưa xem" mà SPEC §7.2 đòi trên mỗi dòng tiến độ đã công bố
 * **không** nằm ở trang này: nó được vẽ ở nơi các dòng tiến độ được vẽ, tức
 * StageLogsRelationManager::readReceiptLabel(). Nói ra ở đây vì kế hoạch M5 Task 6 đoán nhầm vị
 * trí của nó.
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
     *
     * Việc lưu thật đi qua `App\Actions\SetMatterPortalPublication` (fix round 2 review, important
     * finding: trang này từng gọi thẳng `$record->update()`, vi phạm CLAUDE.md "Filament resource/
     * controller/job chỉ gọi Action") — xem docblock của Action đó cho lý do (audit có cấu trúc,
     * seam cho M6). `->visible()` ở đây chỉ là ẩn nút trên giao diện; Action vẫn tự kiểm tra lại
     * qua Gate, không tin trang đã lọc đúng.
     */
    protected function getHeaderActions(): array
    {
        return [
            // Lối vào "Sửa vụ việc" (M6.5 Task 5, EditMatter). Cổng mặc định của EditAction là
            // ability `update` trên model — đúng MatterPolicy::update() đã có, không cần khai báo
            // lại. Đứng NGOÀI dataset của HeaderActionsAreReachableTest (chỉ xét trang List/Edit,
            // xem docblock test đó) nên tên `edit` không phải khớp một phương thức policy tên
            // `edit` — nó vẫn đi qua đúng ability `update`.
            EditAction::make(),
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

                    app(SetMatterPortalPublication::class)->handle(
                        matter: $record,
                        publish: ! $record->is_published_to_portal,
                        actor: Auth::user(),
                    );

                    Notification::make()
                        ->title(__('matters.actions.portal_publication_toggled'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
