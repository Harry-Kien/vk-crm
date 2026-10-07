<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Actions\Mcp\UseStageLogDraft;
use App\Exceptions\McpDraftNotPending;
use App\Models\Matter;
use App\Models\StageLogDraft;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Mở nháp" trên khối "Nháp từ AI (n)" của tab Tiến độ (M11 Task 12): CHÍNH form "Thêm cập nhật"
 * ({@see AddUpdateAction} — cùng schema, cùng bản xem trước "đúng như khách sẽ thấy", cùng công tắc
 * công bố theo luật SPEC §7.3), điền sẵn nội dung của một nháp do AI soạn. Bấm lưu đi qua
 * {@see UseStageLogDraft}: vẫn `TransitionMatterStage` dưới tên người bấm, cộng việc đánh dấu nháp đã
 * dùng trong cùng transaction.
 *
 * Nháp đến từ ĐỐI SỐ `draft` (id) của lần mount, không từ một component gắn sẵn: nút được vẽ bằng
 * `($livewire->useStageLogDraftAction)(['draft' => id])` trong khối nháp, và action vẫn giải được khi
 * nháp vừa bị người khác dùng — để lần bấm đó nhận câu "đã dùng hoặc đã bỏ" thay vì im lặng không
 * làm gì. Ba cổng, không cổng nào tin đối số:
 *  - `visible()`: nháp có id đó thuộc ĐÚNG vụ của trang và người xem có `transitionStage` (một id
 *    của vụ khác ẩn nút, nên Filament không chạy action);
 *  - `beforeFormFilled()`: nháp đã dùng hay đã bỏ thì báo và không mở form;
 *  - `UseStageLogDraft`: hỏi lại tất cả dưới khoá.
 */
class UseStageLogDraftAction extends AddUpdateAction
{
    public static function getDefaultName(): ?string
    {
        return 'useStageLogDraft';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('ai_drafts.actions.open'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->size(Size::Small)
            ->modalHeading(__('ai_drafts.stage_log.open_heading'))
            ->successNotificationTitle(__('ai_drafts.stage_log.open_success'))
            ->visible(fn (RelationManager $livewire): bool => $this->draft($livewire->getOwnerRecord()) !== null
                && Gate::allows('transitionStage', $livewire->getOwnerRecord()))
            ->beforeFormFilled(function (Action $action, RelationManager $livewire): void {
                if (! $this->draft($livewire->getOwnerRecord())?->isPending()) {
                    Notification::make()->title(__('ai_drafts.not_pending'))->warning()->send();

                    $action->cancel();
                }
            })
            ->fillForm(fn (RelationManager $livewire): array => $this->draftFormData($livewire->getOwnerRecord()));
    }

    /** Nháp mang id của đối số `draft`, CHỈ khi nó thuộc vụ `$matter` (đang chờ hay không). */
    public function draft(Matter $matter): ?StageLogDraft
    {
        $id = $this->getArguments()['draft'] ?? null;

        if (! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
            return null;
        }

        return StageLogDraft::query()->whereKey((int) $id)->where('matter_id', $matter->getKey())->first();
    }

    /**
     * Form "Thêm cập nhật" điền từ nháp. `fillForm()` thay hẳn mặc định của các ô, nên ba ô nháp không
     * có (ngày xảy ra, công tắc công bố) và hai ô nháp có thể để trống (nội dung công bố, ngày dự
     * kiến) nhận đúng mặc định của "Thêm cập nhật": hôm nay, `is_published_to_portal` của vụ, mẫu của
     * giai đoạn, và `default_next_update_days` của giai đoạn.
     */
    protected function draftFormData(Matter $matter): array
    {
        $draft = $this->draft($matter);

        return [
            'occurred_at' => today(),
            'internal_note' => $draft?->internal_note,
            'public_content' => $draft?->public_content ?? $this->stageTemplate($matter, $matter->stage),
            'next_step' => $draft?->next_step,
            'client_action' => $draft?->client_action,
            'expected_next_update_at' => $draft?->expected_next_update_at?->toDateString()
                ?? $this->stageDefaultNextUpdateAt($matter, $matter->stage),
            'publish' => (bool) $matter->is_published_to_portal,
        ];
    }

    /**
     * Lần ghi đi qua {@see UseStageLogDraft}. `DomainException` (nháp vừa bị dùng hay bỏ, giai đoạn đã
     * trôi, vụ chưa bật portal) đi về `setUpStageUpdateAction()` như mọi lần "Thêm cập nhật"; lời từ
     * chối vì quyền thành cùng loại thông báo, giữ modal mở.
     */
    protected function submitStageUpdate(Matter $matter, array $data): void
    {
        $draft = $this->draft($matter);

        if ($draft === null) {
            throw McpDraftNotPending::make();
        }

        try {
            app(UseStageLogDraft::class)->handle(
                draft: $draft,
                matter: $matter,
                actor: Auth::user(),
                occurredAt: $data['occurred_at'],
                internalNote: $data['internal_note'] ?? null,
                publicContent: $data['public_content'] ?? null,
                nextStep: $data['next_step'] ?? null,
                clientAction: $data['client_action'] ?? null,
                expectedNextUpdateAt: $this->expectedNextUpdateAtInput($data),
                publish: (bool) ($data['publish'] ?? false),
            );
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
