<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\RecordMatterDestruction;
use App\Actions\Matter\RequestHandoverPackage;
use App\Actions\Matter\SetMatterAiAccess;
use App\Actions\SetMatterPortalPublication;
use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\Role as StaffRole;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
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
            $this->generateHandoverAction(),
            $this->recordDestructionAction(),
            $this->outboundMessagesAction(),
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
                // R5 (roles-05, M6.5 Task 10): 'setPortalPublication', không phải 'update' — xem
                // MatterPolicy::setPortalPublication(). Action vẫn tự kiểm tra lại (không đổi ở
                // đây), đây chỉ là ẩn nút đúng cho người không có quyền. Fix round 1 (ruling): nút
                // này BẤM MỘT LẦN LÀ ĐẢO CHIỀU, nên chiều phải hỏi Gate là chiều NGƯỢC với trạng
                // thái hiện tại (`! is_published_to_portal`) — đúng chiều mà `->action()` bên dưới
                // sẽ thật sự gọi.
                ->visible(fn (): bool => Gate::allows('setPortalPublication', [
                    $this->getRecord(),
                    ! $this->getRecord()->is_published_to_portal,
                ]))
                // Final review C-M1: chiều được CHỤP lúc mở hộp xác nhận — đúng câu người dùng đang
                // đọc — chứ không tính lại lúc bấm "Xác nhận". Nếu trong lúc đó người khác đã đổi,
                // Action từ chối (`MatterPortalPublicationChanged`) thay vì lật ngược lại.
                ->schema([Hidden::make('publish')])
                ->fillForm(fn (): array => ['publish' => ! $this->getRecord()->is_published_to_portal])
                ->action(function (Action $action, array $data): void {
                    $this->runAction($action, fn () => app(SetMatterPortalPublication::class)->handle(
                        matter: $this->getRecord(),
                        publish: (bool) ($data['publish'] ?? false),
                        actor: Auth::user(),
                    ));

                    // Final review wave 2, M-1: Action lưu trên bản ghi ĐÃ KHOÁ của chính nó —
                    // làm mới `$this->record` để nhãn/biểu tượng/màu của nút và tab Tổng quan nói
                    // trạng thái MỚI ngay, không đợi lần tải lại.
                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title(__('matters.actions.portal_publication_toggled'))
                        ->success()
                        ->send();
                }),
            $this->aiAccessAction(),
        ];
    }

    /**
     * Nút "Bật/Tắt truy cập qua AI" (M11 R9, tab Tổng quan) — đổi cờ `matters.ai_access` qua
     * {@see SetMatterAiAccess}, Action duy nhất được đổi cột đó.
     *
     * `->authorize()` hỏi đúng `update` (R9: "đổi cờ cần `matter.update`") — Filament tự ẩn nút cho
     * người không qua Gate; Action vẫn tự hỏi lại dưới khoá, không tin trang đã lọc.
     *
     * Chiều được CHỤP lúc mở hộp (`allow`, cùng luật final review C-M1 của công tắc công bố portal):
     * nếu người khác đã đổi trong lúc hộp còn mở, Action từ chối (`MatterAiAccessChanged`) thay vì
     * lật ngược lại. Chiều BẬT hiện ô tích "Khách đã đồng ý bằng văn bản cho việc này" — không đánh
     * dấu sẵn, bắt buộc tích (`accepted`). Chiều TẮT không có ô tích: rút khỏi AI chỉ thu hẹp.
     */
    private function aiAccessAction(): Action
    {
        $allowing = fn (): bool => $this->getRecord()->ai_access !== MatterAiAccess::Allowed;

        return Action::make('setAiAccess')
            ->label(fn (): string => $allowing() ? __('matters.ai_access.allow') : __('matters.ai_access.deny'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('update', $this->getRecord()))
            ->modalHeading(fn (): string => $allowing() ? __('matters.ai_access.allow_heading') : __('matters.ai_access.deny_heading'))
            ->modalDescription(fn (): string => $allowing() ? __('matters.ai_access.allow_description') : __('matters.ai_access.deny_description'))
            ->modalSubmitActionLabel(fn (): string => $allowing() ? __('matters.ai_access.allow') : __('matters.ai_access.deny'))
            ->schema([
                Hidden::make('allow'),
                Checkbox::make('client_consented')
                    ->label(__('matters.ai_access.client_consented'))
                    ->helperText(__('matters.ai_access.client_consented_help'))
                    ->visible(fn (Get $get): bool => (bool) $get('allow'))
                    ->accepted(),
            ])
            ->fillForm(fn (): array => ['allow' => $allowing(), 'client_consented' => false])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(SetMatterAiAccess::class)->handle(
                    matter: $this->getRecord(),
                    access: ($data['allow'] ?? false) ? MatterAiAccess::Allowed : MatterAiAccess::Denied,
                    actor: Auth::user(),
                    clientConsented: (bool) ($data['client_consented'] ?? false),
                ));

                // Action lưu trên bản ghi ĐÃ KHOÁ của chính nó — làm mới `$this->record` để nút và
                // tab Tổng quan nói trạng thái MỚI ngay (cùng lý do với công tắc công bố portal).
                $this->getRecord()->refresh();

                Notification::make()
                    ->title(__('matters.ai_access.saved'))
                    ->success()
                    ->send();
            });
    }

    /**
     * M7 Task 4 (R9): "Sinh gói bàn giao" / "Sinh lại gói bàn giao". Chỉ hiện với vụ ĐANG kết thúc
     * (`closed_at` có giá trị) đã có bản ghi lưu trữ, VÀ người xem có
     * `MatterArchivePolicy::generateHandover` (`document.publish` + xem được vụ). Bản ghi lưu trữ còn
     * nguyên khi admin mở lại vụ (chỉ `client_access_until` bị xoá), nên chỉ riêng "có bản ghi" sẽ
     * mời bấm một nút luôn hỏng với "chưa kết thúc". Khoá (`disabled`) khi một lần sinh đang chạy —
     * cùng định nghĩa "đang chạy" với chính Action ({@see RequestHandoverPackage::isRunning()}), nên
     * một lần sinh kẹt quá lâu tự mở nút. Action tự kiểm tra lại quyền và trạng thái dưới khoá; nút
     * chỉ là lối vào.
     */
    private function generateHandoverAction(): Action
    {
        return Action::make('generateHandoverPackage')
            ->label(fn (): string => $this->handoverArchive()?->handover_status === null
                ? __('handover.action.generate')
                : __('handover.action.regenerate'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('handover.action.modal_heading'))
            ->modalDescription(__('handover.action.modal_description'))
            ->modalSubmitActionLabel(__('handover.action.submit'))
            ->visible(fn (): bool => $this->getRecord()->isClosed()
                && ($archive = $this->handoverArchive()) !== null
                && Gate::allows('generateHandover', $archive))
            ->disabled(fn (): bool => ($archive = $this->handoverArchive()) !== null
                && RequestHandoverPackage::isRunning($archive))
            ->action(function (Action $action): void {
                $this->runAction($action, fn () => app(RequestHandoverPackage::class)->handle(
                    matterId: $this->getRecord()->getKey(),
                    actor: Auth::user(),
                ));

                // Đọc lại bản ghi lưu trữ để khối "Gói bàn giao" hiện ngay trạng thái mới.
                $this->getRecord()->unsetRelation('archive');

                Notification::make()
                    ->title(__('handover.action.queued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * M7 Task 6 (R5): "Ghi quyết định tiêu huỷ" — GHI LẠI một quyết định đã lập biên bản ngoài hệ
     * thống qua {@see RecordMatterDestruction}; không xoá gì. Chỉ hiện khi CẢ NĂM điều kiện: vụ
     * đang đóng (`closed_at`), có bản ghi lưu trữ, bản ghi đó đã quá `retention_until`
     * (`MatterArchive::isRetentionExpired()`, cùng định nghĩa với Action), chưa ghi quyết định
     * (`destroyed_at` rỗng), và người xem qua `MatterPolicy::recordDestruction` (chỉ admin). Ẩn nút
     * chỉ là lối vào: Action tự hỏi lại quyền trên người thực hiện ĐỌC LẠI từ CSDL và kiểm lại mọi
     * điều kiện dưới khoá. Admin bị gỡ vai, hay người khác đã ghi, trong lúc hộp thoại còn mở: request
     * bấm nút nạp lại người dùng và bản ghi lưu trữ, nên nút thường đã ẩn và Filament không chạy
     * action. Nếu vẫn tới được Action (người dùng trong bộ nhớ còn giữ vai cũ — đúng trường hợp test
     * Livewire dựng), Action từ chối và `runAction()` hiện câu tiếng Việt. Bản ghi lưu trữ đọc qua
     * `handoverArchive()` (tên của Task 4 — nó trả quan hệ `archive` của vụ, không riêng gói).
     *
     * `maxLength` của số biên bản bằng độ dài cột (`varchar(50)`, MariaDB strict); lý do là cột
     * `text`, trần chủ động {@see RecordMatterDestruction::REASON_MAX} như các lý do khác của dự án.
     */
    private function recordDestructionAction(): Action
    {
        return Action::make('recordDestruction')
            ->label(__('archive.destruction.action.label'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('danger')
            ->modalHeading(__('archive.destruction.action.modal_heading'))
            ->modalDescription(__('archive.destruction.action.modal_description'))
            ->modalSubmitActionLabel(__('archive.destruction.action.submit'))
            ->visible(fn (): bool => $this->getRecord()->isClosed()
                && ($archive = $this->handoverArchive()) !== null
                && $archive->destroyed_at === null
                && $archive->isRetentionExpired()
                && Gate::allows('recordDestruction', $this->getRecord()))
            ->schema([
                TextInput::make('destruction_record_no')
                    ->label(__('archive.destruction.fields.destruction_record_no'))
                    ->placeholder(__('archive.destruction.fields.destruction_record_no_hint'))
                    ->required()
                    ->maxLength(RecordMatterDestruction::RECORD_NO_MAX),
                Textarea::make('destruction_reason')
                    ->label(__('archive.destruction.fields.destruction_reason'))
                    ->helperText(__('archive.destruction.fields.destruction_reason_hint'))
                    ->rows(4)
                    ->required()
                    ->minLength(RecordMatterDestruction::REASON_MIN)
                    ->maxLength(RecordMatterDestruction::REASON_MAX)
                    ->columnSpanFull(),
            ])
            ->action(function (Action $action, array $data): void {
                $this->runAction($action, fn () => app(RecordMatterDestruction::class)->handle(
                    matterId: $this->getRecord()->getKey(),
                    actor: Auth::user(),
                    reason: (string) ($data['destruction_reason'] ?? ''),
                    recordNo: (string) ($data['destruction_record_no'] ?? ''),
                ));

                // Đọc lại bản ghi lưu trữ để khối "Lưu trữ hồ sơ" hiện ngay quyết định vừa ghi.
                $this->getRecord()->unsetRelation('archive');

                Notification::make()
                    ->title(__('archive.destruction.action.success'))
                    ->success()
                    ->send();
            });
    }

    private function handoverArchive(): ?MatterArchive
    {
        return $this->getRecord()->archive;
    }

    /**
     * Liên kết "Thư đã gửi" (M6.5 Task 13, findings `notify-8`/`spec-gap-07`): mở
     * `OutboundMessageResource` đã LỌC SẴN theo vụ việc này, qua bộ lọc `matter` mà
     * `OutboundMessagesTable` đăng ký (`SelectFilter::make('matter')`).
     *
     * `->authorize()` tự hỏi lại `OutboundMessagePolicy::viewAny()` — đúng yêu cầu "Custom pages
     * and actions check the policy themselves" của brief: một trợ lý không có `matter.view`
     * (không có ở SPEC §5, nhưng phòng khi chức danh đổi) sẽ không thấy nút này. `matter` được
     * gán tay trong bộ lọc bảng KHÔNG mở rộng những gì `OutboundMessageResource::
     * getEloquentQuery()` đã cho `visibleTo()` lọc — `OutboundMessage::scopeForMatter()` chỉ thu
     * hẹp THÊM bên trong tập đã lọc đó (xem docblock của scope này), nên nút này không phải một
     * đường vòng qua quyền hiển thị.
     *
     * Khoá query string là `filters`, KHÔNG phải `tableFilters`: `ListRecords` (Filament) khai
     * `#[Url(as: 'filters')] public ?array $tableFilters` — tên property Livewire và tên tham số
     * trên URL lệch nhau, và một liên kết dùng nhầm tên thuộc tính sẽ lặng lẽ không lọc gì (không
     * lỗi, chỉ mở ra một danh sách KHÔNG lọc — mà không lọc tức là mọi vụ việc `$user` xem được,
     * không phải mất bảo mật vì `getEloquentQuery()` vẫn còn `visibleTo()`, nhưng làm sai đúng
     * hành vi "lọc sẵn theo vụ" brief đòi).
     */
    private function outboundMessagesAction(): Action
    {
        return Action::make('outboundMessages')
            ->label(__('outbound.matter_tab.label'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('viewAny', OutboundMessage::class))
            ->url(fn (): string => OutboundMessageResource::getUrl('index', [
                'filters' => [
                    'matter' => ['value' => $this->getRecord()->getKey()],
                ],
            ], panel: 'admin'));
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
                    // Fix round 1 (minor): stage_logs.internal_note là cột TEXT (tối đa 65,535
                    // byte), không phải không giới hạn — reason.stage_log.internal_note còn ghép
                    // thêm tên hai người và câu khung quanh $reason (xem lang/vi/reassign.php).
                    // 5.000 ký tự là mức trần CHỦ ĐỘNG, không phải mức tối đa kỹ thuật của cột:
                    // đủ dài cho một lý do bàn giao thật, và chừa hẳn khoảng trống cho phần khung
                    // câu lẫn tiếng Việt nhiều byte (utf8mb4).
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ])
            ->action(function (Action $action, array $data): void {
                $matter = $this->getRecord();
                $wasPublishedToPortal = $matter->is_published_to_portal;

                $this->runAction($action, fn () => $this->submitReassign($data));

                Notification::make()
                    ->title(__('reassign.action.success'))
                    ->success()
                    ->send();

                // Spec gap (fix round 1) — SPEC §6.11 bước 4: một GỢI Ý trên giao diện, KHÔNG BAO
                // GIỜ tự soạn hay tự gửi (xem docblock lớp `ReassignMatter`, mục "Deferred"). Chỉ
                // có nghĩa khi khách ĐÃ thấy được vụ việc này trên cổng.
                if ($wasPublishedToPortal) {
                    Notification::make()
                        ->title(__('reassign.action.suggest_introduction_title'))
                        ->body(__('reassign.action.suggest_introduction_body'))
                        ->info()
                        ->persistent()
                        ->send();
                }

                // Minor (fix round 1): một vụ `restricted` mà lead cũ TỰ bàn giao và không được
                // giữ lại làm associate (công tắc đó bị ẩn trên vụ hạn chế) khiến chính người đang
                // đứng trên trang này mất quyền xem nó ngay lập tức. Ở lại là một trang 404 chờ
                // sẵn ở lần re-render kế tiếp; điều hướng về danh sách vụ việc thay vì để họ tự
                // khám phá ra điều đó.
                if (Gate::forUser(Auth::user())->denies('view', $matter->fresh())) {
                    $this->redirect(MatterResource::getUrl('index', panel: 'admin'));
                }
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
     *
     * Fix round 1 (finding 2): `handle()` giờ trả về `ReassignMatterResult` (không còn `StageLog`
     * trần) để M7 Task 2 gộp được `$movedDeadlineIds`/`$movedRequestIds` của nhiều vụ thành một
     * payload — CỐ Ý bỏ qua giá trị trả về ở đây: màn hình MỘT vụ này không cần gộp gì cả, và thư
     * tổng hợp của chính vụ này đã tự xếp hàng bên trong `handle()` (`$sendDigest` mặc định
     * `true`).
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
