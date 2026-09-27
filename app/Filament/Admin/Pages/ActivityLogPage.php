<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\SensitivePropertyFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Trang chỉ đọc cho nhật ký hệ thống (SPEC §7.4, §10.6), gated bằng auditLog.view — chỉ admin
 * và manager có quyền này (SPEC §5). Không có action ghi/sửa/xoá nào ở đây.
 *
 * Task 20 (phát hiện "lượt rà soát cuối"): trang trước bản sửa này chỉ hiện thời điểm, loại sự
 * kiện, người làm, TÊN LỚP của đối tượng và mô tả — không có liên kết, không có `properties`
 * (lý do ghi đè xung đột, danh sách hồ sơ trùng, IP đăng nhập…). Hai bổ sung:
 *
 *  - cột "Đối tượng" có liên kết TỚI đúng trang xem/sửa của đối tượng đó, nhưng chỉ khi
 *    `Gate::forUser($viewer)` (người đang xem TRANG NHẬT KÝ, không phải người gây ra dòng đó)
 *    cho phép `view` — một manager không thấy được một vụ `restricted` thì không được một liên
 *    kết rò rỉ sự tồn tại của nó qua trang này;
 *  - action `viewProperties` mở modal hiện `properties` đã lọc qua
 *    {@see SensitivePropertyFilter} — không bao giờ `id_number` thô, hash thì được.
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
                    ->formatStateUsing(fn (?string $state): ?string => $state ? class_basename($state) : null)
                    ->url(fn (Activity $record): ?string => static::subjectUrl($record)),
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
            ->recordActions([
                Action::make('viewProperties')
                    ->label(__('activity.page.actions.view_properties'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modal()
                    ->modalHeading(__('activity.page.properties.modal_heading'))
                    ->modalContent(fn (Activity $record) => view('filament.admin.pages.activity-log-properties', [
                        'properties' => SensitivePropertyFilter::filter($record->properties?->toArray() ?? []),
                    ]))
                    ->modalSubmitAction(false),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * `null` (không liên kết) trong BA trường hợp: không có `subject` nạp được (chủ thể đã bị
     * xoá cứng, hoặc dòng này không mang chủ thể — ví dụ `login_failed` trên một email không ứng
     * với tài khoản nào), người xem trang này không qua được `Gate::forUser($viewer)->allows('view',
     * $subject)`, hoặc model của `subject` không nằm trong bảng ánh xạ dưới đây.
     *
     * Phạm vi CÓ CHỦ Ý hẹp hơn "mọi loại chủ thể có thể xuất hiện trong nhật ký": chỉ năm model
     * có TRANG RIÊNG trong panel admin (`Matter`, `Client`, `ClientUser`, `User`, `MatterType`).
     * Các chủ thể lồng trong một vụ việc (`Document`, `Deadline`, `MatterParty`, `ClientRequest`,
     * `MatterChecklistItem`, `StageLog`, …) sống trong các tab của `ViewMatter` chứ không có route
     * riêng — dẫn thẳng người xem sang đúng tab đó là việc của M7 (tab "Nhật ký" của vụ việc,
     * theo kế hoạch), không phải của trang này. Không liên kết vẫn tốt hơn một liên kết sai.
     */
    private static function subjectUrl(Activity $record): ?string
    {
        $subject = $record->subject;

        if (! ($subject instanceof Model)) {
            return null;
        }

        $viewer = Auth::user();

        if ($viewer === null || Gate::forUser($viewer)->denies('view', $subject)) {
            return null;
        }

        return match (true) {
            $subject instanceof Matter => ViewMatter::getUrl(['record' => $subject], panel: 'admin'),
            $subject instanceof Client => EditClient::getUrl(['record' => $subject], panel: 'admin'),
            $subject instanceof ClientUser => EditClientUser::getUrl(['record' => $subject], panel: 'admin'),
            $subject instanceof User => EditUser::getUrl(['record' => $subject], panel: 'admin'),
            $subject instanceof MatterType => EditMatterType::getUrl(['record' => $subject], panel: 'admin'),
            default => null,
        };
    }
}
