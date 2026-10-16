<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Actions\Settings\UpdateBusinessSettings;
use App\Filament\Admin\Concerns\IsSettingsPage;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\MatterStaleness;
use App\Support\Settings\BusinessSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Trang "Cài đặt nghiệp vụ" (làn st, mục 2 của brief) — ba công tắc và ba thông số mà quản trị viên
 * chỉnh được ngay trên giao diện. Ý nghĩa, mặc định và chỗ nối của từng cái ở docblock
 * {@see BusinessSettings}; luật lưu (quyền, giới hạn, chỉ ghi điều đổi, audit cũ → mới) ở
 * {@see UpdateBusinessSettings}. Trang chỉ vẽ form, gọi Action, và liệt kê đọc-chỉ những giá trị theo
 * luật hay theo máy chủ (thời hạn lưu trữ hồ sơ, hạn giữ dữ liệu khách tiềm năng, các ngưỡng của SPEC)
 * — những thứ đó KHÔNG có ô nào ở đây.
 *
 * Cổng: {@see IsSettingsPage} (chỉ `settings.manage`, 404 ở mount và mọi request cập nhật);
 * {@see self::save()} và hành động "Đưa tất cả về mặc định" hỏi lại; Action hỏi lần nữa.
 *
 * Form mở với giá trị ĐANG DÙNG (đã lưu, hoặc mặc định của `.env`), và dòng gợi ý dưới mỗi ô nói giá
 * trị đó đến từ đâu. Bấm "Lưu" không đổi gì thì không ghi dòng nào (Action so giá trị đang dùng), nên
 * mở trang rồi lưu không "đóng băng" `.env` vào bảng `settings`.
 */
class BusinessSettingsPage extends Page
{
    use IsSettingsPage;

    protected string $view = 'filament.admin.pages.settings.business';

    protected static ?string $slug = 'settings/business';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getTitle(): string
    {
        return __('settings.business.title');
    }

    public function mount(): void
    {
        $this->fillWithCurrentValues();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('settings.business.sections.matters'))
                    ->id('matters')
                    ->schema([
                        $this->numberField('client_access_days'),
                        $this->toggleField('stale_matter_mail'),
                    ]),
                Section::make(__('settings.business.sections.intake'))
                    ->id('intake')
                    ->schema([
                        $this->numberField('intake_response_hours'),
                    ]),
                Section::make(__('settings.business.sections.portal'))
                    ->id('portal')
                    ->schema([
                        $this->toggleField('client_requests'),
                        $this->numberField('upload_max_mb'),
                    ]),
                Section::make(__('settings.business.sections.client_notifications'))
                    ->id('client-notifications')
                    ->schema([
                        $this->toggleField('missing_documents_mail'),
                    ]),
            ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetDefaults')
                ->label(__('settings.business.reset.label'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('settings.business.reset.heading'))
                ->modalDescription(__('settings.business.reset.body'))
                ->modalSubmitActionLabel(__('settings.business.reset.confirm'))
                ->action(function (): void {
                    abort_unless(static::canAccess(), 404);

                    /** @var User $actor */
                    $actor = Auth::user();

                    app(UpdateBusinessSettings::class)->reset($actor);

                    Notification::make()->title(__('settings.business.notifications.reset'))->success()->send();

                    $this->fillWithCurrentValues();
                }),
        ];
    }

    /**
     * Hành động THẬT: hỏi lại cổng, gọi {@see UpdateBusinessSettings}, đổi khoá lỗi của Action
     * (`client_access_days`) sang đường dẫn trạng thái của form (`data.client_access_days`), rồi điền lại
     * form bằng giá trị đang dùng.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 404);

        $data = $this->form->getState();

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $changed = app(UpdateBusinessSettings::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            $statePath = $this->getSchema('form')?->getStatePath();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    (filled($statePath) ? "{$statePath}.{$field}" : $field) => $messages,
                ])
                ->all());
        }

        $notification = Notification::make()
            ->title(__($changed === [] ? 'settings.business.notifications.unchanged' : 'settings.business.notifications.saved'));

        $changed === [] ? $notification->info() : $notification->success();

        $notification->send();

        $this->fillWithCurrentValues();
    }

    /**
     * Những giá trị theo luật hay theo máy chủ, chỉ để đọc — đọc từ chính nơi mã dùng chúng, không chép
     * tay con số.
     *
     * @return list<string>
     */
    public function fixedValues(): array
    {
        return [
            __('settings.business.fixed.retention_years', ['years' => (int) config('vkcrm.retention_years')]),
            __('settings.business.fixed.prospect_retention_months', ['months' => IntakeRequest::retentionMonths()]),
            __('settings.business.fixed.staleness', [
                'warning' => MatterStaleness::WARNING_AFTER_DAYS,
                'danger' => MatterStaleness::DANGER_AFTER_DAYS,
                'mail' => MatterStaleness::EMAIL_AFTER_DAYS,
            ]),
        ];
    }

    private function fillWithCurrentValues(): void
    {
        $settings = BusinessSettings::current();
        $state = [];

        foreach (array_keys(BusinessSettings::KEYS) as $field) {
            $state[$field] = in_array($field, BusinessSettings::TOGGLES, true)
                ? $settings->isOn($field)
                : $settings->number($field);
        }

        $this->form->fill($state);
    }

    private function numberField(string $field): TextInput
    {
        $min = BusinessSettings::NUMBERS[$field][0];
        $max = BusinessSettings::maxFor($field);

        return TextInput::make($field)
            ->label(__("settings.business.fields.{$field}.label"))
            ->suffix(__("settings.business.fields.{$field}.suffix"))
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required()
            ->helperText(fn (): string => $this->hint($field, ['min' => $min, 'max' => $max]));
    }

    private function toggleField(string $field): Toggle
    {
        return Toggle::make($field)
            ->label(__("settings.business.fields.{$field}.label"))
            ->helperText(fn (): string => $this->hint($field));
    }

    /**
     * Câu gợi ý của một ô: ý nghĩa, (với dung lượng tệp) trần của máy chủ, và nguồn của giá trị đang
     * dùng — "đang theo máy chủ" kèm mặc định, hay "đã đặt tại đây".
     *
     * @param  array<string, int>  $replace
     */
    private function hint(string $field, array $replace = []): string
    {
        $parts = [__("settings.business.fields.{$field}.hint", $replace)];

        if ($field === 'upload_max_mb') {
            $parts[] = __('settings.business.fields.upload_max_mb.ceiling', ['max' => BusinessSettings::uploadCeilingMegabytes()]);
        }

        if (BusinessSettings::current()->isStored($field)) {
            $parts[] = __('settings.business.source.screen');
        } else {
            $default = BusinessSettings::defaultFor($field);

            $parts[] = __('settings.business.source.server').' '.__('settings.business.source.default', [
                'value' => is_bool($default) ? __($default ? 'settings.state.on' : 'settings.state.off') : $default,
            ]);
        }

        return implode(' ', $parts);
    }
}
