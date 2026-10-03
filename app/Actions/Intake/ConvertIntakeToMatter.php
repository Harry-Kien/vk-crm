<?php

namespace App\Actions\Intake;

use App\Actions\Client\CreateClient;
use App\Actions\Client\FindClientByIdentifier;
use App\Actions\OpenMatter;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Exceptions\ExistingClientConfirmationRequired;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Intake\IntakeConversionResult;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use BackedEnum;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chuyển một lần tiếp nhận thành vụ việc (M10 Task 4, R3) — không gõ lại dữ liệu nào, trừ số căn
 * cước thô nếu văn phòng cần nó trên hồ sơ của một khách MỚI (lúc tiếp nhận chỉ lưu dấu băm, R7).
 *
 *  1. **Quyền:** `IntakeRequestPolicy::convert` — `intake.convert` VÀ `matter.create`, và thấy được
 *     bản ghi. Trước mọi lần tra hay ghi nào.
 *  2. **Bản ghi còn chuyển đổi được** ({@see self::refusal()}), đọc từ dòng vừa khoá: chưa chuyển
 *     đổi, chưa gộp/ẩn danh, đang ở một trạng thái còn mở (`new` … `quoted`), không còn Đỏ chờ
 *     quản lý/admin, và không bị giữ như một cuộc gọi lại của người có lần gọi khác đang khoá
 *     ({@see IntakeRequest::isHeldByRepeatCallLock()} — fix vòng 1, C1; đọc thẳng, không đợi một lần
 *     "Kiểm tra lại" đặt dấu Đỏ chờ; fix vòng 2, N1: một ghi đè trên chính bản này chỉ che những khoá
 *     mà lần kiểm tra gần nhất của nó đã thấy, không che một lần gọi bắt đầu khoá sau đó). R1: Đỏ chỉ
 *     có hai cách xử lý — từ chối hoặc ghi đè kèm lý do; chuyển đổi không phải cách thứ ba. Không thì
 *     `ValidationException` khoá `intake`.
 *  3. **Dữ liệu:** các ô của vụ việc (cùng luật cột với `matters`), loại khách, và số căn cước thô
 *     tuỳ chọn — phải có chữ số; nếu bản ghi đã lưu dấu băm CCCD của người liên hệ thì số gõ phải
 *     băm ra ĐÚNG dấu đó (gõ nhầm một số là ghi sai định danh lên hồ sơ khách, thứ mọi lần kiểm tra
 *     sau dựa vào).
 *  4. **Khách hàng — tra trước, tạo sau, không bao giờ theo tên** (R3, R4, M6.5 R4):
 *     `FindClientByIdentifier` với số căn cước vừa gõ (định danh mạnh hơn: một số máy có thể dùng
 *     chung), rồi với SĐT người liên hệ (dạng đã gõ lúc tiếp nhận). Không khớp thì
 *     `CreateClient::resolve()` dựng một hồ sơ MỚI CHƯA LƯU (hoặc trả hồ sơ trùng thấy được — luật
 *     của Action đó). Mỗi lần tra trừ một suất của `ClientLookupThrottle` (20/giờ, dùng chung — giá
 *     đã chấp nhận ở M6.5); một lần chuyển đổi tốn tới ba suất mỗi lượt gửi. `ClientLookupThrottled`,
 *     `DuplicateClientNotVisible` (khách chỉ biết qua vụ `restricted` — câu trung lập, không tên) và
 *     `DuplicateClientDetected` đi ra nguyên vẹn cho màn hình dịch.
 *     **Một hồ sơ ĐÃ CÓ chỉ được gắn khi** ({@see self::guardExistingClient()}, fix vòng 1, I1/I2):
 *     (a) số căn cước của nó không mâu thuẫn với số đã biết của người liên hệ (số vừa gõ, không thì
 *     dấu băm lúc tiếp nhận) — mâu thuẫn là hai người dùng chung một số máy, không gắn; (b) người bấm
 *     không gõ một số căn cước mà hồ sơ đó không có — chuyển đổi không sửa hồ sơ của khách đã có (không
 *     có Action sửa khách nào để đi qua; lần sửa còn đồng bộ lại các bên `is_our_client`, việc của màn
 *     hình Khách hàng), nên số gõ sẽ mất âm thầm; và (c) người bấm đã XÁC NHẬN đúng hồ sơ đó
 *     (`$confirmedClientId` bằng id của nó) sau khi màn hình hiện mã + tên — không thì
 *     `ExistingClientConfirmationRequired` mang hồ sơ ra ngoài. (a) và (b) là `ValidationException`
 *     khoá `client_id_number`. Ngoài dòng nhật ký `client_lookup` và suất tra của chính các lần tra,
 *     không gì được ghi trước bước này.
 *  5. **Mở vụ bằng ĐÚNG `OpenMatter`**: nó tự lấy khoá `conflict-check`, tự chạy lại kiểm tra xung
 *     đột (kết quả lúc tiếp nhận đã cũ), tự áp hai lượt xác nhận/ghi đè (`$acknowledged`,
 *     `$overrideReason` — cùng hợp đồng), và chỉ lưu khách mới SAU khi kiểm tra cho qua (A-M7). Action
 *     này KHÔNG bọc nó trong khoá hay transaction nào (khoá cache không tái nhập; transaction ngoài
 *     biến bước kiểm tra thành savepoint). Các bên đối lập sang `matter_parties` qua `identify()`:
 *     SĐT đã chuẩn hoá (luỹ đẳng), dấu băm CCCD qua đường có kiểm soát `id_number_hash`
 *     (`MatterParty::identifyWithKnownHash()`). Bản ghi đang chuyển đổi được loại khỏi nguồn dò thứ
 *     hai (`$excludeIntakeId`) để không tự khớp chính nó. Dòng `conflict_check_run` của lần kiểm tra
 *     đó mang chủ thể là bản ghi cho tới khi vụ việc có id (`$checkSubject` của `OpenMatter`, Task 7
 *     fix vòng 1): một lần chuyển đổi KHÔNG thành vụ — bị chặn Đỏ, chưa xác nhận (lượt đầu của trang
 *     chuyển đổi luôn như vậy), bước 6 từ chối, lưu hỏng — để dòng đó, với tên người liên hệ và các bên
 *     đối lập, ở lại với bản ghi, nơi việc ẩn danh bản ghi (`AnonymiseProspect`) tìm thấy nó. Thành vụ
 *     thì nó sang vụ việc như mọi lần mở vụ.
 *  6. **Liên kết hai chiều + `won` + khoá — TRONG transaction lưu của `OpenMatter`** (`$beforeCommit`):
 *     khoá lại dòng bản ghi (sau dòng `matters` — thứ tự khoá của dự án), hỏi lại
 *     {@see self::refusal()}, rồi đặt `status = won`, `matter_id`, `client_id`. Vì sao ở đó chứ không
 *     sau khi `OpenMatter` trả về: khoá dòng ở bước 2 đã nhả trước khi `OpenMatter` chạy (nó commit
 *     riêng), nên hai người bấm chuyển đổi cùng lúc đều qua bước 2. Hỏi lại trong CÙNG transaction
 *     với vụ việc nghĩa là lần thứ hai thấy `matter_id` của lần thứ nhất và ném — cả bước lưu của nó
 *     rollback: không vụ thứ hai, không khách mồ côi. Cùng lý do cho mọi thay đổi xen giữa (bản ghi
 *     bị từ chối, bị gộp): không bao giờ có "vụ đã tạo mà bản ghi tiếp nhận chưa cập nhật".
 *     `matter_id` unique là lưới cuối. Nhật ký `intake_converted` (mã vụ KHÔNG ghi — chỉ id, như
 *     nhật ký tự động của model) với causer = actor; nhật ký tự động tắt cho lần lưu này. Cùng
 *     transaction: mọi bản đã gộp vào bản ghi (trực tiếp hay qua bản khác,
 *     `IntakeRequest::mergedFromTreeIds()`) mất `retention_until` — người đó vừa thành khách, nên các
 *     bản ấy không còn bị ẩn danh hết hạn (Task 7 fix vòng 1; R7c dọc chuỗi gộp,
 *     `IntakeRequest::convertedMergeTarget()`).
 *
 * Sau chuyển đổi bản ghi tự thành chỉ đọc (`IntakeRequest::isClosedToChanges()`: `won`/`matter_id`)
 * và rời nguồn dò thứ hai (`scopeOpenForConflictCheck()`). `quoted_amount` không đi vào vụ việc: form
 * soạn hợp đồng M9 đọc nó làm gợi ý (`IntakeRequest::quotedAmountFor()`). Câu chuyện (`summary`) đi
 * vào `description_internal` chỉ khi màn hình điền sẵn nó và người bấm giữ nguyên — Action nhận ô đó
 * như mọi ô khác. `first_response_at` (R5) là việc của Task 5: chuyển đổi từ `new` cũng là lần rời
 * `new`, nên Task 5 gắn phép đo vào cả Action này.
 */
class ConvertIntakeToMatter
{
    /** Cùng trần byte với ô câu chuyện (`UpdateIntakeSummary`): cột `text` 65.535 byte. */
    public const DESCRIPTION_MAX_BYTES = 60000;

    /**
     * @param  array<string, mixed>  $attributes  Vụ việc: `title`, `matter_type_id`, `lead_lawyer_id`,
     *                                            `client_role`, `opened_at`, `confidentiality`, tuỳ chọn `court_name`, `case_number`,
     *                                            `description_internal`. Khách hàng: `client_type`, tuỳ chọn `client_id_number` (số
     *                                            căn cước thô). Khoá khác bị bỏ qua.
     * @param  string|null  $overrideReason  Lý do ghi đè mức đỏ — chuyển nguyên cho `OpenMatter`.
     * @param  ConflictLevel|null  $acknowledged  Mức đã hiện cho người bấm và được xác nhận — chuyển
     *                                            nguyên cho `OpenMatter`.
     * @param  int|null  $confirmedClientId  Id của hồ sơ khách ĐÃ CÓ mà người bấm đã thấy (mã + tên, từ
     *                                       `ExistingClientConfirmationRequired` của một lượt trước) và
     *                                       xác nhận đúng người. Chỉ có nghĩa khi bước 4 tìm ra ĐÚNG hồ
     *                                       sơ đó; khác đi thì hỏi lại.
     */
    public function handle(
        User $actor,
        IntakeRequest $intake,
        array $attributes,
        ?string $overrideReason = null,
        ?ConflictLevel $acknowledged = null,
        ?int $confirmedClientId = null,
    ): IntakeConversionResult {
        // Bước 1.
        Gate::forUser($actor)->authorize('convert', $intake);

        // Bước 2: câu đầu tiên của transaction là lần đọc có khoá.
        $locked = DB::transaction(fn (): IntakeRequest => IntakeRequest::query()
            ->whereKey($intake->getKey())
            ->lockForUpdate()
            ->firstOrFail());

        $this->refuseUnlessConvertible($locked);

        // Bước 3.
        $data = $this->validated($attributes, $locked);

        // Bước 4.
        $client = $this->resolveClient($actor, $locked, $data, $confirmedClientId);
        $clientCreated = ! $client->exists;

        // Bước 5 + 6.
        $opening = app(OpenMatter::class)->handle(
            actor: $actor,
            attributes: [
                'client_role' => $data['client_role'],
                'matter_type_id' => $data['matter_type_id'],
                'title' => $data['title'],
                'lead_lawyer_id' => $data['lead_lawyer_id'],
                'opened_at' => $data['opened_at'],
                'confidentiality' => $data['confidentiality'],
                'court_name' => $data['court_name'] ?? null,
                'case_number' => $data['case_number'] ?? null,
                'description_internal' => $data['description_internal'] ?? null,
                'is_published_to_portal' => false,
            ],
            parties: $this->opposingParties($locked),
            overrideReason: $overrideReason,
            acknowledged: $acknowledged,
            // Một hồ sơ ĐÃ CÓ thì `OpenMatter` dùng thẳng làm `client_id` (đầu `handle()` của nó); một hồ
            // sơ MỚI CHƯA LƯU thì nó chỉ lưu sau khi kiểm tra cho qua (A-M7).
            newClient: $client,
            excludeIntakeId: $locked->getKey(),
            beforeCommit: fn (Matter $matter) => $this->link($actor, $locked->getKey(), $matter, $clientCreated),
            // Lần chuyển đổi không thành để dòng kiểm tra của nó ở lại với bản ghi — xem bước 5.
            checkSubject: $locked,
        );

        return new IntakeConversionResult(
            IntakeRequest::query()->findOrFail($locked->getKey()),
            $opening,
            $clientCreated,
        );
    }

    /**
     * Vì sao bản ghi này KHÔNG chuyển thành vụ việc được — câu tiếng Việt — hoặc null nếu được. MỘT
     * định nghĩa cho Action (bước 2 và bước 6) và cho màn hình (nút chỉ hiện khi null; trang chuyển
     * đổi đưa người dùng về trang bản ghi kèm câu này). Câu về trạng thái dùng nhãn trạng thái, nên
     * một bản bị từ chối vì xung đột nói đúng như một bản bị từ chối thường (R8).
     */
    public static function refusal(IntakeRequest $intake): ?string
    {
        return match (true) {
            $intake->status === IntakeStatus::Won || $intake->matter_id !== null => __('intake.errors.convert_already'),
            $intake->isClosedToWrites() => __('intake.errors.record_final'),
            ! in_array($intake->status, ChangeIntakeStatus::OPEN, true) => __('intake.errors.convert_status', [
                'status' => $intake->status->label(),
            ]),
            $intake->hasUnresolvedRed() => __('intake.errors.convert_red_pending'),
            // Một câu cho mọi lý do lần gọi kia khoá (Đỏ chờ, hay từ chối vì xung đột — R8), và cho một
            // ghi đè trên bản này mà lần kiểm tra của nó chưa thấy khoá đó (fix vòng 2, N1).
            $intake->isHeldByRepeatCallLock() => __('intake.errors.convert_caller_locked'),
            default => null,
        };
    }

    private function refuseUnlessConvertible(IntakeRequest $intake): void
    {
        $reason = static::refusal($intake);

        if ($reason !== null) {
            throw ValidationException::withMessages(['intake' => [$reason]]);
        }
    }

    /**
     * Luật dữ liệu của Action, không tin màn hình (lệnh console, job hay một lối vào sau này gọi thẳng).
     * Độ dài bằng cột của `matters` (MariaDB strict: vượt là lỗi 1406).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes, IntakeRequest $intake): array
    {
        $attributes = array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $attributes);

        return Validator::make($attributes, [
            'title' => ['required', 'string', 'max:250'],
            'matter_type_id' => ['required', 'integer', Rule::exists('matter_types', 'id')],
            'lead_lawyer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'client_role' => ['required', Rule::enum(PartyRole::class)],
            'opened_at' => ['required', 'date'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'court_name' => ['nullable', 'string', 'max:200'],
            'case_number' => ['nullable', 'string', 'max:80'],
            'description_internal' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::DESCRIPTION_MAX_BYTES) {
                    $fail(__('intake.errors.description_too_long'));
                }
            }],
            'client_type' => ['required', Rule::enum(ClientType::class)],
            'client_id_number' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($intake): void {
                $hash = Normalizer::idNumberHash(is_string($value) ? $value : null);

                if ($hash === null) {
                    $fail(__('intake.errors.id_number_invalid'));
                } elseif ($intake->contact_id_number_hash !== null && $hash !== $intake->contact_id_number_hash) {
                    $fail(__('intake.errors.convert_id_number_mismatch'));
                }
            }],
        ])->validate();
    }

    /**
     * Bước 4 — xem docblock lớp. Trả một hồ sơ ĐÃ CÓ (gắn vào — chỉ sau
     * {@see self::guardExistingClient()}) hoặc một hồ sơ MỚI CHƯA LƯU (`OpenMatter` lưu nó sau khi
     * kiểm tra cho qua).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveClient(User $actor, IntakeRequest $intake, array $data, ?int $confirmedClientId): Client
    {
        $finder = app(FindClientByIdentifier::class);
        $idNumber = filled($data['client_id_number'] ?? null) ? (string) $data['client_id_number'] : null;
        $client = null;

        // Định danh trống: `FindClientByIdentifier` trả null ngay, không tra, không trừ suất.
        foreach ([$idNumber, $intake->contact_phone] as $identifier) {
            $client = $finder->handle($actor, $identifier);

            if ($client !== null) {
                break;
            }
        }

        $client ??= app(CreateClient::class)->resolve($actor, [
            'type' => $data['client_type'],
            'name' => $intake->contact_name,
            'id_number' => $idNumber,
            'phone' => $intake->contact_phone,
            'email' => $intake->contact_email,
        ]);

        if ($client->exists) {
            $this->guardExistingClient($client, $intake, $idNumber, $confirmedClientId);
        }

        return $client;
    }

    /**
     * Ba điều kiện để GẮN người liên hệ vào một hồ sơ khách ĐÃ CÓ — (a), (b), (c) ở bước 4 của docblock
     * lớp. Số căn cước "đã biết" của người liên hệ là số vừa gõ (bước 3 đã buộc nó khớp dấu băm lúc
     * tiếp nhận, nếu có), không thì dấu băm lúc tiếp nhận. Hồ sơ không có số căn cước thì không có gì
     * để mâu thuẫn (a), nhưng cũng không nhận được số vừa gõ (b).
     */
    private function guardExistingClient(Client $client, IntakeRequest $intake, ?string $idNumber, ?int $confirmedClientId): void
    {
        $knownHash = Normalizer::idNumberHash($idNumber) ?? $intake->contact_id_number_hash;
        $clientHash = Normalizer::idNumberHash($client->id_number);
        $names = ['code' => $client->code, 'name' => $client->name];

        if ($knownHash !== null && $clientHash !== null && $clientHash !== $knownHash) {
            throw ValidationException::withMessages(['client_id_number' => [__('intake.errors.convert_client_id_differs', $names)]]);
        }

        if ($idNumber !== null && $clientHash === null) {
            throw ValidationException::withMessages(['client_id_number' => [__('intake.errors.convert_id_number_not_carried', $names)]]);
        }

        if ($confirmedClientId !== $client->getKey()) {
            throw ExistingClientConfirmationRequired::make($client);
        }
    }

    /**
     * Các bên đối lập của bản ghi theo hình dạng `$parties` của `OpenMatter`: KHÔNG phải khách hàng
     * của văn phòng; SĐT đã chuẩn hoá đưa vào ô `phone` (`Normalizer::phone()` luỹ đẳng); dấu băm CCCD
     * qua khoá `id_number_hash` (không số thô nào tồn tại — R7).
     *
     * @return array<int, array<string, mixed>>
     */
    private function opposingParties(IntakeRequest $intake): array
    {
        return $intake->parties()->orderBy('id')->get()
            ->map(fn (IntakeParty $party): array => [
                'role' => $party->role,
                'name' => $party->name,
                'is_our_client' => false,
                'phone' => $party->phone_normalized,
                'id_number_hash' => $party->id_number_hash,
            ])
            ->all();
    }

    /** Bước 6 — xem docblock lớp. Chạy trong transaction lưu của `OpenMatter`. */
    private function link(User $actor, int $intakeId, Matter $matter, bool $clientCreated): void
    {
        $locked = IntakeRequest::query()->whereKey($intakeId)->lockForUpdate()->firstOrFail();

        $this->refuseUnlessConvertible($locked);

        $locked->status = IntakeStatus::Won;
        $locked->matter_id = $matter->getKey();
        $locked->client_id = $matter->client_id;
        $locked->blameOn($actor);
        $locked->disableLogging()->save();
        $locked->enableLogging();

        // Task 7, fix vòng 1 (rà soát Task 7, I1): người liên hệ vừa thành khách, nên mọi bản đã gộp vào
        // bản này — trực tiếp hay qua bản khác — mất hạn lưu như chính nó: R7b chỉ cho người KHÔNG thành
        // khách. Câu UPDATE thẳng (không `updated_at`, không sự kiện): không ai sửa các bản đó.
        $merged = $locked->mergedFromTreeIds();

        if ($merged !== []) {
            IntakeRequest::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($merged)
                ->toBase()
                ->update(['retention_until' => null]);
        }

        Audit::record('intake_converted', $locked, [
            'matter_id' => $matter->getKey(),
            'client_id' => $matter->client_id,
            'client_created' => $clientCreated,
        ], $actor);
    }
}
