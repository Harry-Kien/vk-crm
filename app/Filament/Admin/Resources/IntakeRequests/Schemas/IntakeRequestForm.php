<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Schemas;

use App\Actions\Intake\UpdateIntakeIdentity;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Filament\Admin\Resources\IntakeRequests\Tables\IntakeRequestsTable;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Phần DANH TÍNH của form tiếp nhận (M10 Task 3, R1) — dùng chung cho trang tạo và trang sửa. Trang
 * tạo (`CreateIntakeRequest`) thêm ô ghi nhận thông báo và câu "ô câu chuyện mở sau khi lưu"; trang
 * sửa (`EditIntakeRequest`) thêm kết quả kiểm tra, gợi ý trùng, ô câu chuyện và các hành động.
 *
 * **Nhập được nhanh trong lúc đang nghe máy** (kế hoạch): chỉ bắt buộc tên, vai dự kiến, nguồn, và số
 * điện thoại TRỪ KHI có email (`requiredWithout`) — "tên, SĐT hoặc nguồn khác". Vai bắt buộc ở FORM dù
 * Action nhận null (fix vòng 1 của Task 2, C1: người gọi lại được nhận ra theo vai; khác vai thì không
 * phải cùng người). Câu chuyện bổ sung sau, khi cổng mở.
 *
 * **`maxLength` = độ dài cột** (MariaDB strict; `IntakeRequestResourceTest` khẳng định từng số):
 * `contact_name` 200, `contact_phone` 20, `contact_email` 150, `referred_by` 200, tên bên đối lập
 * 200, SĐT bên đối lập 20. Số CCCD không có cột (chỉ lưu dấu băm): trần 30 bằng đúng luật của Action.
 * Phí đã báo: chuỗi tối đa 15 ký tự qua `Money::parse()` (cùng khuôn ô tiền M9).
 *
 * **SĐT:** `->tel()->regex(null)` + luật "`Normalizer::phone()` đọc được" — cùng bản sửa `conflict-10`
 * của `MatterForm`: regex mặc định của `tel()` từ chối `(+84) 912 345 678`, dạng mà bộ chuẩn hoá đọc
 * đúng. Thêm luật "dạng chuẩn hoá vừa cột 20" ({@see IntakeRequest::normalizedPhoneFits()}): số 0 đầu
 * thành `84` làm một số gõ đủ 20 ký tự dài 21 sau chuẩn hoá. Action kiểm lại bằng cùng hàm; luật ở form
 * để lỗi của một dòng bên đối lập nằm ở đúng ô của dòng đó (lỗi của Action chỉ gắn được vào cả danh sách).
 *
 * **Trang sửa, bản ghi còn Đỏ chờ xử lý** (fix vòng 1, rà soát Task 3 C1): SĐT, CCCD và vai ĐÃ CÓ của
 * người liên hệ khoá với người không xử lý được Đỏ, kèm câu nói vì sao — ba ô đó là thứ nhận ra người
 * này gọi lại (xem `callerKeyLock()`); ô còn trống vẫn điền được. Bản đã từ chối thì trang sửa khoá cả
 * hai phần này, với mọi người, và không khoá từng ô cũng không nói lý do khoá (fix vòng 2, R8 — xem
 * `callerKeyLocked()`): bản từ chối vì xung đột và bản từ chối vì lý do thường trông như nhau.
 */
class IntakeRequestForm
{
    /** Dùng khi Filament hỏi form của resource mà trang không tự dựng (không trang nào hôm nay). */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([static::identitySection(editing: false), static::partiesSection(editing: false)]);
    }

    public static function identitySection(bool $editing): Section
    {
        return Section::make(__('intake.sections.identity'))
            ->description(__('intake.sections.identity_description'))
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                TextInput::make('contact_name')
                    ->label(__('intake.fields.contact_name'))
                    ->required()
                    ->maxLength(200),
                static::phoneInput('contact_phone')
                    ->label(__('intake.fields.contact_phone'))
                    ->helperText(static::callerKeyHelp($editing, 'contact_phone', __('intake.fields.contact_phone_help')))
                    ->requiredWithout('contact_email')
                    ->disabled(static::callerKeyLock($editing, 'contact_phone'))
                    ->dehydrated(),
                TextInput::make('contact_email')
                    ->label(__('intake.fields.contact_email'))
                    ->email()
                    ->maxLength(150),
                TextInput::make('contact_id_number')
                    ->label(__('intake.fields.contact_id_number'))
                    ->helperText(static::callerKeyHelp($editing, 'contact_id_number', $editing ? __('intake.fields.contact_id_number_help_edit') : __('intake.fields.contact_id_number_help')))
                    ->maxLength(30)
                    ->disabled(static::callerKeyLock($editing, 'contact_id_number')),
                Select::make('contact_role')
                    ->label(__('intake.fields.contact_role'))
                    ->helperText(static::callerKeyHelp($editing, 'contact_role', __('intake.fields.contact_role_help')))
                    ->options(static::contactRoleOptions())
                    ->native(false)
                    ->required()
                    ->disabled(static::callerKeyLock($editing, 'contact_role'))
                    ->dehydrated(),
                Select::make('source')
                    ->label(__('intake.fields.source'))
                    ->options(fn (): array => collect(IntakeSource::cases())
                        ->mapWithKeys(fn (IntakeSource $source): array => [$source->value => $source->label()])
                        ->all())
                    ->default(IntakeSource::Phone->value)
                    ->native(false)
                    ->required(),
                TextInput::make('referred_by')
                    ->label(__('intake.fields.referred_by'))
                    ->maxLength(200),
                Select::make('matter_type_id')
                    ->label(__('intake.fields.matter_type_id'))
                    ->options(fn (): array => MatterType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false),
                TextInput::make('quoted_amount')
                    ->label(__('intake.fields.quoted_amount'))
                    ->maxLength(15),
                Select::make('assigned_to')
                    ->label(__('intake.fields.assigned_to'))
                    ->options(fn (): array => IntakeRequestsTable::assigneeOptions())
                    ->searchable()
                    ->native(false),
                DateTimePicker::make('received_at')
                    ->label(__('intake.fields.received_at'))
                    ->helperText(__('intake.fields.received_at_help'))
                    ->seconds(false)
                    ->maxDate(fn () => now())
                    ->visible(! $editing),
            ]);
    }

    public static function partiesSection(bool $editing): Section
    {
        return Section::make(__('intake.sections.parties'))
            ->description(__('intake.fields.parties_help'))
            ->columnSpanFull()
            ->schema([
                Repeater::make('parties')
                    ->hiddenLabel()
                    ->addActionLabel(__('intake.fields.add_party'))
                    ->defaultItems(0)
                    ->maxItems(IntakeRequest::MAX_OPPOSING_PARTIES)
                    ->reorderable(false)
                    ->columns(2)
                    ->schema([
                        Hidden::make('id'),
                        Select::make('role')
                            ->label(__('intake.fields.party_role'))
                            ->options(static::partyRoleOptions())
                            ->native(false)
                            ->required(),
                        TextInput::make('name')
                            ->label(__('intake.fields.party_name'))
                            ->required()
                            ->maxLength(200),
                        static::phoneInput('phone')
                            ->label(__('intake.fields.party_phone'))
                            ->helperText($editing ? __('intake.fields.party_identity_help_edit') : null),
                        TextInput::make('id_number')
                            ->label(__('intake.fields.party_id_number'))
                            ->maxLength(30),
                    ]),
            ]);
    }

    /**
     * Trang SỬA: ô SĐT, CCCD hay vai `$field` của người liên hệ khoá với người mà `UpdateIntakeIdentity`
     * sẽ từ chối đổi nó ({@see UpdateIntakeIdentity::guardsCallerKey()}: bản ghi đang khoá cuộc gọi lại,
     * người xem không xử lý được Đỏ, và ô đó đã có giá trị — fix vòng 1, rà soát Task 3 C1). Ô còn trống
     * thì mở: Action nhận việc thêm nó. Bản đã từ chối hay đã xong việc thì không khoá từng ô: trang sửa
     * khoá cả phần danh tính, và Action từ chối mọi lần sửa danh tính của nó trước khi hỏi tới ô nào.
     * Action là cổng thật; ô khoá chỉ để người nhập không gõ rồi mới bị từ chối. SĐT và vai vẫn gửi giá
     * trị đang có (`dehydrated()` ở chỗ dùng): một ô khoá KHÔNG gửi gì sẽ thành "xoá" ở Action, và lần lưu
     * tên/email bị từ chối oan. Ô CCCD thì không cần: trống đã nghĩa là "giữ". Trang tạo: không bao giờ khoá.
     */
    private static function callerKeyLock(bool $editing, string $field): Closure|bool
    {
        return $editing ? fn (?IntakeRequest $record): bool => static::callerKeyLocked($record, $field) : false;
    }

    /** Câu dưới ô: nói vì sao ô khoá khi nó khoá, không thì câu hướng dẫn thường. */
    private static function callerKeyHelp(bool $editing, string $field, string $help): Closure|string
    {
        return $editing
            ? fn (?IntakeRequest $record): string => static::callerKeyLocked($record, $field) ? __('intake.fields.caller_keys_locked_help') : $help
            : $help;
    }

    /**
     * Bản ghi đã từ chối hay đã xong việc ({@see IntakeRequest::isClosedToIdentityEdits()}) thì KHÔNG khoá
     * từng ô (fix vòng 2, rà soát Task 3): trang sửa đã khoá cả phần danh tính với mọi người, nên khoá
     * từng ô không thêm gì, còn câu `caller_keys_locked_help` ở đó vừa sai (trưởng phòng cũng không đổi
     * được) vừa lộ lý do: một bản từ chối VÌ XUNG ĐỘT vẫn khoá cuộc gọi lại (`locksRepeatCalls()`) dù
     * không còn Đỏ nào chờ, bản từ chối vì lý do thường thì không — câu đó sẽ cho người không có
     * `viewConflictReason` biết đó là xung đột (R8). Mọi bản đã từ chối hiện cùng những câu hướng dẫn.
     */
    private static function callerKeyLocked(?IntakeRequest $record, string $field): bool
    {
        $user = Auth::user();

        return $record !== null
            && ! $record->isClosedToIdentityEdits()
            && $user instanceof User
            && UpdateIntakeIdentity::guardsCallerKey($user, $record, $field);
    }

    private static function phoneInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->tel()
            ->regex(null)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && Normalizer::phone($value) === null) {
                    $fail(__('intake.errors.phone_invalid'));
                } elseif (is_string($value) && ! IntakeRequest::normalizedPhoneFits($value)) {
                    $fail(__('intake.errors.phone_too_long'));
                }
            })
            ->maxLength(20);
    }

    /** @return array<string, string> */
    private static function partyRoleOptions(): array
    {
        return collect(PartyRole::cases())
            ->mapWithKeys(fn (PartyRole $role): array => [$role->value => $role->label()])
            ->all();
    }

    /**
     * Vai của NGƯỜI LIÊN HỆ: mọi vai trừ luật sư đối phương (M6.5 R13f — khách hàng của văn phòng
     * không bao giờ là luật sư đối phương của chính vụ mình; Action cũng từ chối).
     *
     * @return array<string, string>
     */
    private static function contactRoleOptions(): array
    {
        return collect(PartyRole::cases())
            ->reject(fn (PartyRole $role): bool => $role === PartyRole::OpposingCounsel)
            ->mapWithKeys(fn (PartyRole $role): array => [$role->value => $role->label()])
            ->all();
    }
}
