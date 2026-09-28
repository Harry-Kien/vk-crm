<?php

namespace App\Filament\Admin\Resources\Matters\Actions\Concerns;

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Actions\TransitionMatterStage;
use App\Models\Matter;
use Closure;
use DomainException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Phần dùng chung của hai Action "Chuyển giai đoạn" và "Thêm cập nhật" (SPEC §7.3, §6.2, §6.3):
 * cả hai đều gọi CÙNG MỘT `TransitionMatterStage` (xem docblock của Action đó — "thêm cập nhật"
 * chỉ là gọi Action với `to_stage` bằng giai đoạn hiện tại), cùng gate theo `matter.transitionStage`,
 * và có cùng bộ trường phụ (ngày xảy ra, ghi chú nội bộ, nội dung công bố, tiếp theo, cần làm gì,
 * dự kiến tin tiếp theo, công tắc công bố, bản xem trước sống). Điểm khác nhau duy nhất giữa hai
 * Action là GIAI ĐOẠN ĐÍCH: `TransitionStageAction` có thêm một `Select` để chọn, `AddUpdateAction`
 * cố định bằng giai đoạn hiện tại — mỗi lớp tự cài `resolveToStage()` và `buildSchema()`.
 */
trait BuildsStageUpdateSchema
{
    /** Nối phần dùng chung vào Action: quyền, khổ modal, schema (do lớp con cung cấp), và submit. */
    protected function setUpStageUpdateAction(string $successMessageKey): void
    {
        $this->size(Size::Large)
            ->modalWidth(Width::FourExtraLarge)
            ->visible(fn (RelationManager $livewire): bool => Gate::allows('transitionStage', $livewire->getOwnerRecord()))
            ->schema(fn (RelationManager $livewire): array => $this->buildSchema($livewire->getOwnerRecord()))
            ->successNotificationTitle(__($successMessageKey))
            ->action(function (array $data, RelationManager $livewire): void {
                /** @var Matter $matter */
                $matter = $livewire->getOwnerRecord();

                try {
                    app(TransitionMatterStage::class)->handle(
                        matter: $matter,
                        actor: Auth::user(),
                        toStage: $this->resolveToStage($matter, $data),
                        occurredAt: $data['occurred_at'],
                        internalNote: $data['internal_note'] ?? null,
                        publicContent: $data['public_content'] ?? null,
                        nextStep: $data['next_step'] ?? null,
                        clientAction: $data['client_action'] ?? null,
                        // ?: chứ không phải ??: DatePicker rỗng gửi lên chuỗi rỗng, không phải null —
                        // để lọt qua '' thì StageLog (cast 'date') sẽ ném lỗi phân tích ngày tháng,
                        // thay vì để TransitionMatterStage tự tính lại theo default_next_update_days
                        // như thiết kế.
                        expectedNextUpdateAt: $data['expected_next_update_at'] ?: null,
                        publish: (bool) ($data['publish'] ?? false),
                    );
                } catch (DomainException $exception) {
                    // M6.5 Task 10, fix round 1 (C1, Critical): bắt CHUNG mọi `DomainException` mà
                    // `TransitionMatterStage::handle()` có thể ném, không chỉ
                    // `MatterNotPublishedToPortal` như bản trước. `MatterStageChanged` (stage-05:
                    // giai đoạn đã đổi dưới chân người dùng — hai tab, bấm hai lần, hai người cùng
                    // sửa một vụ việc) trước đây thoát thẳng ra ngoài `try/catch` này thành một
                    // trang 500 không ai bắt (SPEC §10.10 cấm điều này) — đúng lỗ hổng "double-submit"
                    // Review Focus 4 nói tới, chỉ khác là nó xảy ra ở màn hình, không phải ở Action
                    // (Action đã tự đúng từ commit trước: nó NÉM lỗi thay vì âm thầm ghi sai).
                    //
                    // Bắt theo LỚP CHA thay vì liệt kê từng lớp con: đây chính là "lớp phòng thủ thứ
                    // hai" `MatterNotPublishedToPortal` đã có từ trước (tắt/disable công tắc publish
                    // đã chặn đường chính, đây là lưới an toàn) — cùng nguyên tắc, mở rộng cho MỌI
                    // đường TransitionMatterStage từ chối bằng một DomainException, kể cả những
                    // đường chưa lường trước sau này (ví dụ InvalidStageTransition lọt qua được nếu
                    // ai đó forge request bỏ qua ràng buộc `in:` của Select `to_stage`).
                    //
                    // Thông điệp lấy THẲNG từ exception (không đổi thành một câu chung như
                    // `actions.unauthorized`): mỗi lớp DomainException ở đây tự viết câu của mình
                    // bằng lang/vi (xem MatterNotPublishedToPortal::make(), MatterStageChanged::make(),
                    // InvalidStageTransition::make()), và với MatterStageChanged câu đó ĐÃ nói rõ việc
                    // cần làm tiếp theo ("Hãy tải lại trang..." — đúng yêu cầu review "nếu không giữ
                    // được nội dung đã gõ thì phải nói rõ cần tải lại trang").
                    //
                    // `halt()` (không đổi): giữ modal MỞ thay vì đóng lại, nên nội dung luật sư đã gõ
                    // (ghi chú nội bộ, nội dung công bố…) không mất — chỉ `to_stage`/kết quả submit
                    // là không áp dụng được nữa.
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $this->halt();
                }
            });
    }

    /** Giai đoạn đích thật sự gửi cho Action — khác nhau giữa hai lớp con. */
    abstract protected function resolveToStage(Matter $matter, array $data): string;

    /** Toàn bộ schema của modal — khác nhau giữa hai lớp con (có/không có Select to_stage). */
    abstract protected function buildSchema(Matter $matter): array;

    /**
     * `maxDate(today())` chỉ là lớp tiện lợi cho người dùng (thông điệp ngay tại ô, không cần
     * submit mới biết sai) — cổng thật chặn ngày tương lai nằm ở
     * `TransitionMatterStage::handle()`, vì Action là API công khai và là nơi duy nhất không thể
     * bị vòng qua (fix round 2 review, important finding).
     */
    protected function occurredAtField(): DatePicker
    {
        return DatePicker::make('occurred_at')
            ->label(__('matters.transition_form.occurred_at'))
            ->default(today())
            ->maxDate(today())
            ->native(false)
            ->required();
    }

    protected function internalNoteField(): Textarea
    {
        return Textarea::make('internal_note')
            ->label(__('matters.transition_form.internal_note'))
            ->helperText(__('matters.transition_form.internal_note_hint'))
            ->rows(3)
            ->columnSpanFull();
    }

    /**
     * `$default` là gợi ý mẫu lấy từ `matter_type_stages.client_description` (SPEC §7.3).
     *
     * Fix round 1, finding 1: `TransitionMatterStage::handle()` tự ném `ValidationException` với
     * khoá THÔ (`'public_content'`) khi `publish = true` và nội dung dưới 30 ký tự — nhưng khoá đó
     * không khớp state path đầy đủ của một trường trong action đang mount
     * (`mountedActions.{index}.data.public_content`), nên lỗi không bao giờ hiện lên đúng ô: modal
     * chỉ lặng lẽ rollback, không có gì báo cho luật sư. `required()`/`minLength()` ở đây là validate
     * THẬT của chính schema (không phải exception thủ công), nên Filament tự gắn đúng vào trường —
     * đây là nửa "cho người dùng thấy" của luật; nửa "gác cổng thật" vẫn ở TransitionMatterStage,
     * không đổi.
     */
    protected function publicContentField(?string $default): Textarea
    {
        return Textarea::make('public_content')
            ->label(__('matters.transition_form.public_content'))
            ->live()
            ->default($default)
            ->rows(4)
            ->columnSpanFull()
            ->required(fn (Get $get): bool => (bool) $get('publish'))
            ->minLength(fn (Get $get): ?int => $get('publish') ? 30 : null)
            ->helperText(fn (Get $get): ?string => $get('publish')
                ? __('matters.transition_form.public_content_publish_hint')
                : null);
    }

    protected function nextStepField(): Textarea
    {
        return Textarea::make('next_step')
            ->label(__('matters.transition_form.next_step'))
            ->live()
            ->rows(2)
            ->columnSpanFull();
    }

    protected function clientActionField(): Textarea
    {
        return Textarea::make('client_action')
            ->label(__('matters.transition_form.client_action'))
            ->helperText(__('matters.transition_form.client_action_hint'))
            ->live()
            ->rows(2)
            ->columnSpanFull();
    }

    protected function expectedNextUpdateAtField(?string $default): DatePicker
    {
        return DatePicker::make('expected_next_update_at')
            ->label(__('matters.transition_form.expected_next_update_at'))
            ->live()
            ->default($default)
            ->native(false);
    }

    /**
     * Mặc định BẬT khi vụ việc đã bật portal, tắt khi chưa (SPEC §7.3, test bắt buộc). Fix round 1,
     * finding 2: khi vụ CHƯA bật portal, `disabled()` khoá hẳn công tắc thay vì chỉ để mặc định tắt
     * — nếu không luật sư vẫn tự bật được rồi gặp `MatterNotPublishedToPortal` không ai báo trước.
     *
     * Fix round 2 review, task 5 (docblock cũ nói sai hành vi thật): `disabled()` KHÔNG "vẫn
     * dehydrate giá trị mặc định bình thường". Đọc thẳng
     * `vendor/filament/schemas/src/Components/Concerns/CanBeDisabled.php::disabled()`: nó tự gọi
     * `$this->saved(fn ($component) => ! $component->evaluate($condition))`, tức GẮN LUÔN một
     * điều kiện "saved" phủ định điều kiện disabled. Rồi
     * `vendor/.../Concerns/HasState.php::isDehydrated()` tính
     * `evaluate($this->isDehydrated) ?? $this->isSaved()` — vì trường này chưa từng gọi
     * `dehydrated()` tường minh, `$this->isDehydrated` vẫn `null`, nên `??` rơi về `isSaved()`.
     * Kết quả: khi công tắc bị khoá (`! $matter->is_published_to_portal` đúng), trường này KHÔNG
     * được dehydrate — khoá `publish` biến mất KHỎI `$data` gửi lên, không phải "có mặt với giá
     * trị false". Đây chính là điều `dehydrateState()` làm: khi `isDehydrated()` sai, nó XOÁ hẳn
     * state path đó khỏi mảng, kể cả khi client cố tình sửa giá trị Livewire ngầm để né UI khoá —
     * máy chủ tự tính lại `isDehydrated()` từ `$matter` (không tin giá trị client gửi), nên việc
     * xoá diễn ra vô điều kiện phía server. Vì vậy `(bool) ($data['publish'] ?? false)` ở
     * `setUpStageUpdateAction()` mới là chỗ thật sự biến "khoá không có mặt" thành `false` — không
     * phải Filament tự gửi `false`. Kết luận an toàn: một công tắc bị khoá KHÔNG THỂ dehydrate một
     * `true` mà người dùng chưa từng chọn — hành vi thật còn chặt hơn cả mô tả sai trong docblock
     * cũ, không phải lỗ hổng.
     */
    protected function publishToggleField(Matter $matter): Toggle
    {
        return Toggle::make('publish')
            ->label(__('matters.transition_form.publish'))
            ->live()
            ->default($matter->is_published_to_portal)
            ->disabled(! $matter->is_published_to_portal)
            ->helperText($matter->is_published_to_portal
                ? null
                : __('matters.transition_form.publish_disabled_hint'));
    }

    /**
     * Task 7 (R12, phát hiện `stage/stage-06` — nửa "luật sư không biết khách không được báo"):
     * `NotifyClientOfStageUpdate::handle()` âm thầm bỏ qua một dòng công bố khi khách chưa có tài
     * khoản cổng đủ điều kiện nhận thư (`recipientsFor()` rỗng) — không lỗi, không cảnh báo, chỉ
     * để `notified_at` trống. Luật sư bấm "Chuyển giai đoạn"/"Thêm cập nhật", thấy thông báo
     * thành công CỐ ĐỊNH (`setUpStageUpdateAction()` ở trên), và tin rằng khách đã được báo.
     *
     * Dùng LẠI đúng `NotifyClientOfStageUpdate::hasEligibleRecipient()` — một nơi duy nhất đọc
     * "ai đủ điều kiện nhận thư" (R12: `is_active` + `activated_at` không null + khách chưa xoá
     * mềm) — để cảnh báo này không bao giờ lệch với chính Action gửi thư thật.
     *
     * Dùng `Filament\Schemas\Components\Text` với `->color('warning')` thay vì một Blade view tự
     * viết: dự án không có bước dựng CSS (CLAUDE.md), và một lớp Tailwind tự viết sẽ không có tác
     * dụng gì trên `theme.css` biên dịch sẵn (xem `StageLogsRelationManager::renderInternalNote()`
     * và phát hiện `stage/stage-07`). Component CÓ SẴN của Filament thì khác: nó render qua view
     * nội bộ của chính gói, dùng các lớp `fi-*` đã có trong `theme.css` phục vụ, nên không cần
     * style nội tuyến ở đây.
     */
    protected function noActivatedAccountWarning(Matter $matter): Text
    {
        return Text::make(__('matters.transition_form.no_activated_account_warning'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->columnSpanFull()
            ->visible(fn (): bool => $matter->is_published_to_portal
                && ! app(NotifyClientOfStageUpdate::class)->hasEligibleRecipient($matter));
    }

    /**
     * Bản xem trước đúng như khách sẽ thấy (SPEC §7.3): một `View` component đọc trạng thái các
     * trường `live()` khác qua `$get()` (viewData closure — xem
     * vendor/filament/schemas/docs/09-custom-components.md, "Accessing the state of another
     * component"), KHÔNG bao giờ đọc `internal_note` — đây chính là cơ chế "rẻ nhất" SPEC nói tới:
     * nếu luật sư dán nhầm ghi chú nội bộ vào đúng ô của nó, bản xem trước không đổi; chỉ khi dán
     * (nhầm) vào ô công bố thì mới hiện ra ở đây, và người dùng tự thấy ngay.
     */
    protected function previewField(Closure $viewDataResolver): View
    {
        return View::make('filament.client-preview')
            ->viewData($viewDataResolver)
            ->columnSpanFull();
    }

    protected function stageTemplate(Matter $matter, ?string $stageKey): ?string
    {
        if ($stageKey === null) {
            return null;
        }

        return $matter->matterType->stage($stageKey)?->client_description;
    }

    /** Nhãn giai đoạn DÀNH CHO KHÁCH (`client_label`) — dùng ở bản xem trước, không phải `label` nội bộ. */
    protected function stageClientLabel(Matter $matter, ?string $stageKey): ?string
    {
        if ($stageKey === null) {
            return null;
        }

        return $matter->matterType->stage($stageKey)?->client_label;
    }

    /**
     * Fix round 1, finding E (minor): công thức `now()->addDays($stage->default_next_update_days)`
     * CỐ Ý trùng với bước 4 của `TransitionMatterStage::handle()` (xem docblock lớp đó) — hàm này
     * chỉ tính để GỢI Ý/prefill trên form, không phải luật; `TransitionMatterStage` mới là nơi tính
     * lại thật sự khi `expected_next_update_at` gửi lên rỗng. Hai bên có thể trôi lệch nếu chỉ một
     * bên đổi công thức — không rút thành helper dùng chung vì `TransitionMatterStage` không nên
     * phụ thuộc ngược vào tầng Filament chỉ vì một phép tính ngày, nhưng nếu công thức đổi thì phải
     * sửa CẢ HAI nơi.
     */
    protected function stageDefaultNextUpdateAt(Matter $matter, ?string $stageKey): ?string
    {
        $stage = $stageKey === null ? null : $matter->matterType->stage($stageKey);

        return $stage === null ? null : now()->addDays($stage->default_next_update_days)->toDateString();
    }
}
