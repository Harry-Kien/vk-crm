<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\Matter\ReassignMatter;
use App\Actions\SetMatterPortalPublication;
use App\Enums\Confidentiality;
use App\Enums\Role as StaffRole;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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
    use ReportsActionFailures;

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
            $this->reassignAction(),
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

    /**
     * Header action "Bàn giao" (SPEC §6.11; M6.5 Task 4, R7): đổi lead qua
     * {@see ReassignMatter}, Action DUY NHẤT được đổi `lead_lawyer_id` (R6).
     *
     * `->authorize()` dùng ĐÚNG `manageTeam` — R5 nhóm "quản lý đội ngũ và bàn giao" lại làm một
     * câu, và `MatterPolicy::manageTeam()` đã đúng hình dạng đó (lead của chính vụ việc này,
     * manager được xem vụ, hoặc admin — trừ trợ lý). Không authorize thì Filament tự ẩn nút cho
     * người không qua được Gate (`CanBeAuthorized::resolveIsAuthorizedOrNotHiddenWhenUnauthorized()`);
     * `ReassignMatter::handle()` vẫn tự hỏi lại Gate đó, không tin trang đã lọc đúng.
     *
     * Công tắc "giữ luật sư cũ trong đội ngũ" bị ẨN HẲN trên vụ `restricted` (xem docblock
     * `ReassignMatter` — một associate không phải admin sẽ không bao giờ mở lại được vụ hạn chế),
     * nên mặc định gửi lên là `false` khi ẩn (trường ẩn không được Filament dehydrate — cùng cơ chế
     * `BuildsStageUpdateSchema::publishToggleField()` đã ghi).
     */
    private function reassignAction(): Action
    {
        return Action::make('reassignMatter')
            ->label(__('reassign.action.label'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading(__('reassign.action.modal_heading'))
            ->modalSubmitActionLabel(__('reassign.action.submit'))
            ->authorize(fn (): bool => Gate::allows('manageTeam', $this->getRecord()))
            ->schema(fn (): array => [
                Select::make('new_lead_id')
                    ->label(__('reassign.fields.new_lead_id'))
                    ->options(fn (): array => self::reassignCandidateOptions($this->getRecord()))
                    ->searchable()
                    ->native(false)
                    ->required(),
                Toggle::make('keep_old_lead_as_associate')
                    ->label(__('reassign.fields.keep_old_lead_as_associate'))
                    ->helperText(__('reassign.fields.keep_old_lead_as_associate_hint'))
                    ->default(true)
                    ->visible(fn (): bool => $this->getRecord()->confidentiality !== Confidentiality::Restricted),
                Textarea::make('reason')
                    ->label(__('reassign.fields.reason'))
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => $this->submitReassign($data));

                Notification::make()
                    ->title(__('reassign.action.success'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Thân thật của "Bàn giao", tách khỏi `->action()` — cùng lý do `PartiesRelationManager::
     * createParty()`: một phương thức riêng là chỗ một test "gửi payload giả" (bỏ qua tầng
     * dehydrate của Filament — ví dụ ép `keep_old_lead_as_associate = true` cho một vụ `restricted`
     * dù ô đó đã bị `visible()` false và KHÔNG BAO GIỜ dehydrate được giá trị đó qua form thật) có
     * thể gọi thẳng qua `Closure::bind`, đo đúng lớp phòng thủ NẰM DƯỚI Filament, không phải một
     * chi tiết dehydrate của framework. `ReassignMatter::handle()` vẫn là nơi quyết định thật; hàm
     * này chỉ dịch `$data` của form sang tham số của Action — không tự thêm luật nào.
     */
    private function submitReassign(array $data): void
    {
        app(ReassignMatter::class)->handle(
            matter: $this->getRecord(),
            actor: Auth::user(),
            newLead: User::query()->findOrFail($data['new_lead_id']),
            reason: $data['reason'] ?? '',
            keepOldLeadAsAssociate: (bool) ($data['keep_old_lead_as_associate'] ?? false),
        );
    }

    /**
     * Ứng viên lead mới: nhân sự đang hoạt động, giữ vai luật sư hoặc trưởng phòng (hai vai duy
     * nhất `ReassignMatter`/SPEC §1 coi là "đứng tên phụ trách" được — cùng tập vai
     * `AddTeamMember::eligibleForRole()` chấp nhận cho `associate`), trừ chính lead hiện tại.
     *
     * KHÔNG `public`: cùng gotcha `TeamRelationManager::memberOptions()` (Task 3) đã ghi — một
     * phương thức `public` trên một trang Filament (cũng là một component Livewire) là một điểm
     * cuối GỌI ĐƯỢC TỪ XA, độc lập với việc ô `Select` có hiện nó ra hay không.
     *
     * @return array<int, string>
     */
    private static function reassignCandidateOptions(Matter $matter): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($matter->lead_lawyer_id)
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole(StaffRole::Lawyer->value) || $user->hasRole(StaffRole::Manager->value))
            ->pluck('name', 'id')
            ->all();
    }
}
