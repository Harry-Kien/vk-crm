<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema;
use App\Models\Matter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Nút "Chuyển giai đoạn" (SPEC §7.3, §6.2). Chỉ liệt kê các giai đoạn hợp lệ theo `allowed_next`
 * của giai đoạn hiện tại (`stageOptions()`, tách static để test trực tiếp không cần dựng cả
 * Livewire) — KHÔNG tự thêm giai đoạn hiện tại vào danh sách, đó là việc của `AddUpdateAction`
 * (SPEC §6.3). Chọn xong, `afterStateUpdated` gợi ý mẫu nội dung công bố từ
 * `matter_type_stages.client_description` và dự kiến ngày tin tiếp theo từ
 * `default_next_update_days` của giai đoạn ĐÍCH — lại chọn giai đoạn khác thì gợi ý được tính lại
 * (ghi đè bản đã gõ dở cho lựa chọn cũ); đây là đánh đổi có chủ đích, không phải sơ suất — xem báo
 * cáo Task 9.
 */
class TransitionStageAction extends Action
{
    use BuildsStageUpdateSchema;

    public static function getDefaultName(): ?string
    {
        return 'transitionStage';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('matters.actions.transition_stage'))
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('primary')
            ->modalHeading(__('matters.transition_form.transition_heading'))
            ->setUpStageUpdateAction('matters.transition_form.transition_success');
    }

    /** @return array<string, string> giai đoạn hợp lệ (key => nhãn nội bộ) theo `allowed_next`. */
    public static function stageOptions(Matter $matter): array
    {
        $current = $matter->currentStage();

        if ($current === null) {
            return [];
        }

        return collect($current->allowed_next)
            ->mapWithKeys(fn (string $key): array => [$key => $matter->matterType->stage($key)?->label ?? $key])
            ->all();
    }

    protected function resolveToStage(Matter $matter, array $data): string
    {
        return $data['to_stage'];
    }

    protected function buildSchema(Matter $matter): array
    {
        return [
            Select::make('to_stage')
                ->label(__('matters.transition_form.to_stage'))
                ->options(fn (): array => static::stageOptions($matter))
                ->live()
                ->required()
                ->afterStateUpdated(function (Set $set, ?string $state) use ($matter): void {
                    $set('public_content', $this->stageTemplate($matter, $state));
                    $set('expected_next_update_at', $this->stageDefaultNextUpdateAt($matter, $state));
                }),
            $this->occurredAtField(),
            $this->internalNoteField(),
            $this->publicContentField(null),
            $this->nextStepField(),
            $this->clientActionField(),
            $this->expectedNextUpdateAtField(null),
            $this->publishToggleField($matter),
            $this->previewField(fn (Get $get): array => [
                'stageLabel' => $this->stageClientLabel($matter, $get('to_stage')),
                'publicContent' => $get('public_content'),
                'nextStep' => $get('next_step'),
                'clientAction' => $get('client_action'),
                'expectedNextUpdateAt' => $get('expected_next_update_at'),
                'willPublish' => (bool) $get('publish'),
            ]),
        ];
    }
}
