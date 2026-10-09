<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers\Concerns;

use App\Actions\Mcp\DiscardDraft;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\ClientRequestReplyDraft;
use App\Models\StageLogDraft;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Phần dùng chung của hai khối nháp do AI soạn trên trang vụ việc (M11 Task 12): "Nháp từ AI (n)"
 * của tab Tiến độ ({@see StageLogsRelationManager})
 * và "Nháp trả lời từ AI (n)" của tab Yêu cầu từ khách
 * ({@see ClientRequestsRelationManager}).
 *
 *  - {@see self::aiDraftsSection()}: khối đứng TRÊN bảng của tab (trong `content()`), chỉ hiện khi
 *    có ít nhất một nháp ĐANG CHỜ; thẻ vẽ bằng view `filament.admin.ai-drafts`.
 *  - {@see self::discardAiDraftAction()}: "Bỏ nháp" — lý do bắt buộc, qua {@see DiscardDraft}.
 *  - {@see self::draftIdArgument()}: đọc đối số `draft` của một lần mount, chặt (chỉ số nguyên dương).
 *
 * Lớp dùng trait này phải dùng {@see ReportsActionFailures} (lỗi của Action thành câu tiếng Việt).
 */
trait ManagesAiDrafts
{
    /**
     * @param  list<array{id: int, title: ?string, meta: string, fields: list<array{label: string, value: string, internal: bool}>, note: ?string}>  $cards
     * @param  list<string>  $actionNames  tên action CỦA COMPONENT vẽ dưới mỗi thẻ
     */
    protected function aiDraftsSection(string $headingKey, string $descriptionKey, array $cards, array $actionNames): Section
    {
        return Section::make(__($headingKey, ['count' => count($cards)]))
            ->description(__($descriptionKey))
            ->icon(Heroicon::OutlinedSparkles)
            ->collapsible()
            ->visible($cards !== [])
            ->schema([
                View::make('filament.admin.ai-drafts')->viewData([
                    'cards' => $cards,
                    'actions' => $actionNames,
                ]),
            ]);
    }

    /** "Soạn qua AI bởi … lúc …" — tên đọc kèm tài khoản đã nghỉ việc, chỉ cột `name`. */
    protected function draftMeta(StageLogDraft|ClientRequestReplyDraft $draft): string
    {
        return __('ai_drafts.created_by', [
            'name' => User::withTrashed()->whereKey($draft->created_by)->value('name') ?? __('ai_drafts.unknown_author'),
            'at' => $draft->created_at?->format('H:i d/m/Y') ?? '—',
        ]);
    }

    /** Id nháp trong đối số `draft`, hoặc `null` nếu đối số không phải một số nguyên dương. */
    protected static function draftIdArgument(Action $action): ?int
    {
        $id = $action->getArguments()['draft'] ?? null;

        if (is_int($id) && $id > 0) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * "Bỏ nháp": modal một ô lý do (bắt buộc, trần bằng {@see DiscardDraft::REASON_MAX_LENGTH}), gọi
     * {@see DiscardDraft} dưới tên người bấm.
     *
     * `$resolve(Action)` trả nháp mang id của đối số CHỈ khi nó thuộc trang đang mở (đang chờ hay
     * không); `$canDiscard(nháp)` là cổng của màn hình — Action hỏi lại dưới khoá. Nháp đã dùng hay
     * đã bỏ: mở modal thì báo và không mở; bấm trên một modal đã mở thì {@see DiscardDraft} từ chối
     * bằng cùng câu.
     *
     * @param  Closure(Action): (StageLogDraft|ClientRequestReplyDraft|null)  $resolve
     * @param  Closure(StageLogDraft|ClientRequestReplyDraft): bool  $canDiscard
     */
    protected function discardAiDraftAction(string $name, Closure $resolve, Closure $canDiscard): Action
    {
        return Action::make($name)
            ->label(__('ai_drafts.actions.discard'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading(__('ai_drafts.actions.discard_heading'))
            ->modalDescription(__('ai_drafts.actions.discard_description'))
            ->modalSubmitActionLabel(__('ai_drafts.actions.discard_submit'))
            ->visible(function (Action $action) use ($resolve, $canDiscard): bool {
                $draft = $resolve($action);

                return $draft !== null && $canDiscard($draft);
            })
            ->beforeFormFilled(function (Action $action) use ($resolve): void {
                if (! $resolve($action)?->isPending()) {
                    Notification::make()->title(__('ai_drafts.not_pending'))->warning()->send();

                    $action->cancel();
                }
            })
            ->schema([
                Textarea::make('reason')
                    ->label(__('ai_drafts.fields.reason'))
                    ->required()
                    ->maxLength(DiscardDraft::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('ai_drafts.actions.discard_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(DiscardDraft::class)->handle(
                    $resolve($action) ?? throw new AuthorizationException(__('ai_drafts.unavailable')),
                    Auth::user(),
                    (string) ($data['reason'] ?? ''),
                ),
            ));
    }

    /** Một trường của thẻ nháp; trường trống hiện "(trống)" để người đọc biết AI không viết gì. */
    protected static function draftField(string $labelKey, ?string $value, bool $internal = false): array
    {
        return [
            'label' => __($labelKey),
            'value' => filled($value) ? $value : __('ai_drafts.fields.empty'),
            'internal' => $internal,
        ];
    }
}
