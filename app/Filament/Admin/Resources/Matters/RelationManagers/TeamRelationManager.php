<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Matter\AddTeamMember;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Matter\SeatsNeedingReassignment;
use App\Actions\Task\TaskAssignees;
use App\Enums\Confidentiality;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Filament\Admin\Concerns\ProvidesSeatActions;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
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
    use ProvidesSeatActions;
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
                // Làn tk, Task 2 — ai trong đội chưa nhận / đã trả lại hồ sơ (`MatterUser::assignmentStatus()`).
                // Vòng sửa polish 1 (phán quyết controller (3)): ghế chờ nhận của người không còn nhận được hồ sơ
                // hiện "Cần phân công lại" (`SeatsNeedingReassignment::stuck()` — cùng luật lịch nhắc dùng để thôi nhắc).
                TextColumn::make('assignment')
                    ->label(__('tasks.seat.columns.status'))
                    ->badge()
                    ->state(fn (User $record): string => SeatsNeedingReassignment::stuck($record->pivot, $record, $matter)
                        ? __('tasks.seat.needs_reassignment')
                        : $record->pivot->assignmentStatus()->label())
                    ->color(fn (User $record): string => SeatsNeedingReassignment::stuck($record->pivot, $record, $matter)
                        ? 'danger'
                        : $record->pivot->assignmentStatus()->color())
                    ->description(fn (User $record): ?string => SeatsNeedingReassignment::stuck($record->pivot, $record, $matter)
                        ? __('tasks.seat.needs_reassignment_note')
                        : self::seatNote(
                            $record->pivot,
                            showReason: $record->is(Auth::user()) || Gate::allows('manageTeam', $matter),
                        ))
                    ->wrap(),
            ])
            ->headerActions([
                $this->addMemberAction($matter),
            ])
            ->recordActions([
                // Làn tk, Task 2: chỉ hiện trên dòng của CHÍNH người xem khi ghế đang chờ nhận.
                $this->acceptSeatAction(fn (User $record): MatterUser => $record->pivot, fn (): Matter => $matter),
                $this->declineSeatAction(fn (User $record): MatterUser => $record->pivot, fn (): Matter => $matter),
                $this->removeMemberAction($matter),
            ]);
    }

    /**
     * Dòng phụ của cột "Nhận hồ sơ" (làn tk, Task 2): ghế chờ nhận thì giao từ lúc nào; ghế trả lại thì
     * lời nhắc phân công lại (bằng "Gỡ" hay "Bàn giao" sẵn có — trả lại không tự gỡ ai), kèm LÝ DO chỉ
     * cho người quản lý được đội ngũ và chính người trả lại (`$showReason`): lý do có thể là một xung đột
     * lợi ích, không phải điều mọi thành viên khác của đội cần đọc.
     */
    private static function seatNote(MatterUser $seat, bool $showReason): ?string
    {
        return match ($seat->assignmentStatus()) {
            MatterAssignmentStatus::Pending => $seat->assigned_at === null
                ? null
                : __('tasks.seat.waiting_since', ['since' => $seat->assigned_at->format('H:i d/m/Y')]),
            MatterAssignmentStatus::Declined => $showReason
                ? __('tasks.seat.declined_note', ['reason' => (string) $seat->decline_reason])
                : __('tasks.seat.declined_note_short'),
            MatterAssignmentStatus::Accepted => null,
        };
    }

    /**
     * Ô chọn NGƯỜI chỉ gồm nhân sự `is_active`, chưa có trong đội ngũ, có thể giữ ĐÚNG vai đã
     * chọn ở ô kia (Task 3 brief) — cùng lý do `DeadlinesRelationManager::responsibleOptions()`:
     * options của một `Select` `native(false)` không đi vào HTML ban đầu, Filament dựng chúng
     * phía trình duyệt, nên đây là chỗ DUY NHẤT đo được danh sách thật (test gọi qua
     * `ReflectionMethod` — xem ghi chú `private` dưới đây).
     *
     * Chỉ là một TIỆN ÍCH, không phải cổng: {@see AddTeamMember::eligibleForRole()} hỏi lại TRÊN
     * NGƯỜI ĐƯỢC CHỌN. Loại người ĐÃ trong đội ngũ khỏi danh sách vì lý do tương tự — mời một lựa
     * chọn mà Action luôn từ chối (`already_member`) là một cái bẫy có thể tránh, không phải một
     * lớp bảo vệ (Action vẫn tự hỏi lại `team()->whereKey()->exists()`).
     *
     * **Fix round 1, finding I1 — vụ `restricted` không mời một người sẽ không bao giờ thấy được
     * vụ việc đó.** `Matter::isListableBy()` nhánh `restricted` không đọc `team()` chút nào (chỉ
     * `hasRole(Admin)` hoặc chính `lead_lawyer_id`), nên "có trong đội ngũ hay không" KHÔNG đổi
     * câu trả lời của `view()` cho một vụ `restricted` — gọi thẳng
     * `Gate::forUser($user)->allows('view', $matter)` ở đây cho kết quả ĐÚNG dù người đó chưa
     * được thêm (khác hẳn vụ THƯỜNG, nơi việc này sẽ SAI — xem lý lẽ đầy đủ ở docblock
     * `AddTeamMember::handle()`, đoạn "Vì sao SAU, không phải TRƯỚC"). Chỉ hỏi Gate khi vụ việc
     * `restricted`: với vụ thường, `eligibleForRole()` đã đảm bảo `matter.view` (bốn vai đều có),
     * nên không cần trả giá một truy vấn Gate cho MỖI ứng viên ở đường phổ biến nhất.
     *
     * **Fix round 1, "also fix" — `->with('roles')` tránh N+1 khi `eligibleForRole()` gọi
     * `hasRole()` cho từng người trong `->filter()`.**
     *
     * @return array<int, string>
     */
    private function memberOptions(?string $role): array
    {
        $matterRole = MatterRole::tryFrom($role ?? '');

        if ($matterRole === null || $matterRole === MatterRole::Lead) {
            return [];
        }

        $matter = $this->getOwnerRecord();
        $existingIds = $matter->team()->pluck('users.id');
        $isRestricted = $matter->confidentiality === Confidentiality::Restricted;

        return User::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existingIds)
            ->with('roles')
            ->get(['id', 'name'])
            ->filter(fn (User $user): bool => AddTeamMember::eligibleForRole($user, $matterRole))
            ->filter(fn (User $user): bool => ! $isRestricted || Gate::forUser($user)->allows('view', $matter))
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
            // Làn fa2, phán quyết 1: ghế còn chờ nhận thì nói rõ gỡ là rút lời giao; việc người đó đang giữ hiện trong
            // form bên dưới, kèm ô chọn người nhận thay (RemoveTeamMember từ chối gỡ khi chưa chọn).
            ->modalDescription(fn (User $record): string => __('team.tab.actions.remove_description')
                .($record->pivot?->assignmentStatus() === MatterAssignmentStatus::Pending ? ' '.__('internal_flow.team_removal.pending_seat') : ''))
            ->schema(fn (User $record): array => $this->removalTaskFields($matter, $record))
            ->authorize(fn (): bool => Gate::allows('manageTeam', $matter))
            // Vai `lead` chỉ đổi qua bàn giao vụ việc (M7, R6) — nút "Gỡ" không có việc gì trên
            // chính dòng đó. `RemoveTeamMember` CŨNG từ chối gỡ một lead — LUÔN LUÔN, không chỉ
            // khi vụ việc đang mở (fix round 1, finding S3) — nhưng ẩn nút ở đây trước để người
            // dùng không bấm vào một lời từ chối có thể đoán trước — hai lớp, không phải một lớp
            // thừa.
            ->visible(fn (User $record): bool => $record->pivot->role_in_matter !== MatterRole::Lead)
            ->successNotificationTitle(__('team.tab.actions.remove_success'))
            ->action(function (Action $action, User $record, array $data) use ($matter): void {
                $choice = $data['task_recipient_id'] ?? null;
                $leave = $choice === RemoveTeamMember::LEAVE_FOR_REASSIGNMENT;
                $recipient = $leave || blank($choice) ? null : User::query()->withTrashed()->find((int) $choice);

                $this->runAction(
                    $action,
                    fn () => app(RemoveTeamMember::class)->handle($matter, Auth::user(), $record, $recipient, $leave),
                );
            });
    }

    /**
     * Làn fa2, phán quyết 1 — việc nội bộ `$member` đang giữ (chờ nhận, đang làm) trên `$matter`, và ô chọn người nhận
     * thay: người giữ việc hợp lệ của vụ (`TaskAssignees::eligible()` — ghế giữ việc chưa trả lại, đang hoạt động, mở
     * được vụ, trừ người bấm), trừ chính người bị gỡ, cộng lựa chọn "Để Cần giao lại". Mặc định luật sư phụ trách khi
     * họ có trong danh sách. Không giữ việc nào thì không có ô nào — gỡ như trước. `private`: xem docblock lớp về
     * phương thức công khai của component Livewire.
     *
     * @return array<int, Text|Select>
     */
    private function removalTaskFields(Matter $matter, User $member): array
    {
        $held = Task::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matter->getKey())
            ->where('assignee_id', $member->getKey())
            ->held()
            ->orderBy('id')
            ->get();

        if ($held->isEmpty()) {
            return [];
        }

        $actor = Auth::user();
        $options = app(TaskAssignees::class)->eligible($matter, $actor)
            ->reject(fn (User $user): bool => $user->is($member))
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name])
            ->all();
        $lines = $held->map(fn (Task $task): string => __('internal_flow.team_removal.task_line', [
            'title' => $task->title,
            'status' => $task->status->label(),
            'due' => $task->due_date === null ? '' : __('internal_flow.team_removal.task_due', ['date' => $task->due_date->format('d/m/Y')]),
        ]));

        return [
            Text::make(__('internal_flow.team_removal.tasks_heading', ['count' => $held->count()])."\n".$lines->implode("\n")),
            Select::make('task_recipient_id')
                ->label(__('internal_flow.team_removal.recipient'))
                ->helperText(__('internal_flow.team_removal.recipient_hint'))
                ->options($options + [RemoveTeamMember::LEAVE_FOR_REASSIGNMENT => __('internal_flow.team_removal.leave')])
                ->default(array_key_exists($matter->lead_lawyer_id, $options) ? $matter->lead_lawyer_id : null)
                ->required()
                ->native(false),
        ];
    }
}
