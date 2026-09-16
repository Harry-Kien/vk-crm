<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema;
use App\Models\Matter;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Nút "Thêm cập nhật" (SPEC §6.3): CÙNG một `TransitionMatterStage`, gọi với `to_stage` bằng
 * đúng giai đoạn hiện tại — không có Select chọn giai đoạn vì không có gì để chọn. Nội dung công
 * bố mặc định lấy ngay `matter_type_stages.client_description` của giai đoạn hiện tại (không cần
 * `afterStateUpdated` như `TransitionStageAction`, vì giai đoạn đích ở đây không đổi trong lúc
 * điền form).
 */
class AddUpdateAction extends Action
{
    use BuildsStageUpdateSchema;

    public static function getDefaultName(): ?string
    {
        return 'addUpdate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('matters.actions.add_update'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('gray')
            ->modalHeading(__('matters.transition_form.add_update_heading'))
            ->setUpStageUpdateAction('matters.transition_form.add_update_success');
    }

    protected function resolveToStage(Matter $matter, array $data): string
    {
        return $matter->stage;
    }

    protected function buildSchema(Matter $matter): array
    {
        $stageKey = $matter->stage;

        return [
            $this->occurredAtField(),
            $this->internalNoteField(),
            $this->publicContentField($this->stageTemplate($matter, $stageKey)),
            $this->nextStepField(),
            $this->clientActionField(),
            $this->expectedNextUpdateAtField($this->stageDefaultNextUpdateAt($matter, $stageKey)),
            $this->publishToggleField($matter),
            $this->previewField(fn (Get $get): array => [
                'stageLabel' => $matter->currentStage()?->client_label,
                'publicContent' => $get('public_content'),
                'nextStep' => $get('next_step'),
                'clientAction' => $get('client_action'),
                'expectedNextUpdateAt' => $get('expected_next_update_at'),
                'willPublish' => (bool) $get('publish'),
            ]),
        ];
    }
}
