<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Matter\AddTeamMember;
use App\Actions\Matter\RemoveTeamMember;
use App\Enums\MatterRole;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Tab "Đội ngũ" (SPEC §4.7, §5, §7.2; M6.5 Task 3, R6) — màn hình DUY NHẤT trong hệ thống ghi
 * vào `matter_user` ngoài `Matter::created()` (chỉ thêm lead). Trước task này không có màn hình
 * nào cho việc này, nên mọi vụ mở qua giao diện chỉ có một người — xem docblock
 * {@see AddTeamMember} cho hậu quả đầy đủ và các mã phát hiện (`intake-01`, `roles-03`,
 * `spec-gap-01`, `e2e-F4`, critical).
 *
 * Lớp này không có một dòng nghiệp vụ nào (CLAUDE.md): mọi lần ghi đi qua {@see AddTeamMember}
 * hoặc {@see RemoveTeamMember}, và không có `form()` cấp RelationManager — cả hai nút dựng schema
 * riêng của chính mình, vì không cái nào là một `CreateAction`/`EditAction` mặc định (đối tượng
 * đang thêm là một QUAN HỆ tới một `User` có sẵn, kèm một vai, không phải tạo một bản ghi mới).
 *
 * `->authorize()` trên cả hai nút hỏi thẳng `MatterPolicy::manageTeam` trên vụ việc CHỦ — không
 * dùng `isReadOnly()` (đọc lý lẽ ở {@see DeadlinesRelationManager}: `CanBeAuthorized::
 * resolveIsAuthorized()` chỉ đọc `isReadOnly()` khi KHÔNG có `->authorize()` tường minh). Vì hai
 * Action tự hỏi lại đúng ability này trên actor thật, nút ẩn/hiện ở đây chỉ là tiện ích hiển thị —
 * cổng thật nằm trong `AddTeamMember`/`RemoveTeamMember`.
 */
class TeamRelationManager extends RelationManager
{
    use ReportsActionFailures;

    protected static string $relationship = 'team';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('team.tab.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('team.tab.fields.member');
    }

    /**
     * Cổng của cả TAB, cùng thành ngữ mọi relation manager khác của `Matter`: ai đọc được vụ
     * việc thì thấy tab này — bao gồm cả người KHÔNG quản lý được đội ngũ (họ chỉ không thấy hai
     * nút ghi).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $matter = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->emptyStateHeading(__('team.tab.empty_state'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('team.tab.columns.name')),
                TextColumn::make('pivot.role_in_matter')
                    ->label(__('team.tab.columns.role'))
                    ->badge()
                    ->formatStateUsing(fn (MatterRole $state): string => $state->label()),
                TextColumn::make('pivot.created_at')
                    ->label(__('team.tab.columns.joined_at'))
                    ->date('d/m/Y'),
            ])
            ->headerActions([
                $this->addMemberAction($matter),
            ])
            ->recordActions([
                $this->removeMemberAction($matter),
            ]);
    }

    /**
     * Ô chọn NGƯỜI chỉ gồm nhân sự `is_active`, chưa có trong đội ngũ, có thể giữ ĐÚNG vai đã
     * chọn ở ô kia (Task 3 brief) — công khai vì cùng lý do
     * `DeadlinesRelationManager::responsibleOptions()`: options của một `Select` `native(false)`
     * không đi vào HTML ban đầu, Filament dựng chúng phía trình duyệt, nên đây là chỗ DUY NHẤT
     * đo được danh sách thật.
     *
     * Chỉ là một TIỆN ÍCH, không phải cổng: {@see AddTeamMember::eligibleForRole()} hỏi lại TRÊN
     * NGƯỜI ĐƯỢC CHỌN. Loại người ĐÃ trong đội ngũ khỏi danh sách vì lý do tương tự — mời một lựa
     * chọn mà Action luôn từ chối (`already_member`) là một cái bẫy có thể tránh, không phải một
     * lớp bảo vệ (Action vẫn tự hỏi lại `team()->whereKey()->exists()`).
     *
     * @return array<int, string>
     */
    public function memberOptions(?string $role): array
    {
        $matterRole = MatterRole::tryFrom($role ?? '');

        if ($matterRole === null || $matterRole === MatterRole::Lead) {
            return [];
        }

        $matter = $this->getOwnerRecord();
        $existingIds = $matter->team()->pluck('users.id');

        return User::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existingIds)
            ->get(['id', 'name'])
            ->filter(fn (User $user): bool => AddTeamMember::eligibleForRole($user, $matterRole))
            ->pluck('name', 'id')
            ->all();
    }

    private function addMemberAction(Matter $matter): Action
    {
        return Action::make('addMember')
            ->label(__('team.tab.actions.add'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('team.tab.actions.add_heading'))
            ->modalSubmitActionLabel(__('team.tab.actions.add_submit'))
            ->authorize(fn (): bool => Gate::allows('manageTeam', $matter))
            ->schema([
                // `live()` + `afterStateUpdated()`: đổi vai thì ô người phải nạp lại danh sách
                // (`memberOptions()` lọc theo vai), và một người đã chọn cho vai CŨ có thể không
                // hợp lệ cho vai MỚI — giữ nguyên giá trị đó sẽ để lọt một lựa chọn Action sẽ từ
                // chối ở lượt gửi.
                Select::make('role_in_matter')
                    ->label(__('team.tab.fields.role'))
                    ->options(collect(MatterRole::cases())
                        ->reject(fn (MatterRole $role): bool => $role === MatterRole::Lead)
                        ->mapWithKeys(fn (MatterRole $role): array => [$role->value => $role->label()])
                        ->all())
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(fn (Set $set) => $set('user_id', null)),
                Select::make('user_id')
                    ->label(__('team.tab.fields.member'))
                    ->options(fn (Get $get): array => $this->memberOptions($get('role_in_matter')))
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->successNotificationTitle(__('team.tab.actions.add_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(AddTeamMember::class)->handle(
                    matter: $matter,
                    actor: Auth::user(),
                    member: User::withTrashed()->findOrFail($data['user_id']),
                    role: MatterRole::from($data['role_in_matter']),
                ),
            ));
    }

    private function removeMemberAction(Matter $matter): Action
    {
        return Action::make('removeMember')
            ->label(__('team.tab.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('team.tab.actions.remove_heading'))
            ->modalDescription(__('team.tab.actions.remove_description'))
            ->authorize(fn (): bool => Gate::allows('manageTeam', $matter))
            // Vai `lead` chỉ đổi qua bàn giao vụ việc (M7, R6) — nút "Gỡ" không có việc gì trên
            // chính dòng đó. `RemoveTeamMember` (qua `OpenWork`) CŨNG chặn gỡ một lead của một vụ
            // ĐANG MỞ (đọc docblock của nó), nhưng ẩn nút ở đây trước để người dùng không bấm vào
            // một lời từ chối có thể đoán trước — hai lớp, không phải một lớp thừa.
            ->visible(fn (User $record): bool => $record->pivot->role_in_matter !== MatterRole::Lead)
            ->successNotificationTitle(__('team.tab.actions.remove_success'))
            ->action(fn (Action $action, User $record) => $this->runAction(
                $action,
                fn () => app(RemoveTeamMember::class)->handle($matter, Auth::user(), $record),
            ));
    }
}
