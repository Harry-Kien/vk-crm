<?php

namespace App\Actions;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\Role;
use App\Events\MatterStageChanged as MatterStageChangedEvent;
use App\Events\StageLogPublished;
use App\Exceptions\InvalidStageTransition;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Exceptions\MatterStageChanged;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Chuyển giai đoạn vụ việc, HOẶC thêm một dòng cập nhật không đổi giai đoạn — cùng một Action
 * (SPEC §6.2 và §6.3). Dòng cập nhật không đổi giai đoạn (§6.3) là trường hợp `toStage` bằng
 * đúng giai đoạn hiện tại của `$matter`; khi đó bước 1 (kiểm tra `allowed_next`) KHÔNG áp dụng —
 * cả `from_stage` lẫn `to_stage` của `StageLog` đều bằng giai đoạn hiện tại.
 *
 * Tám bước SPEC §6.2, tất cả trong một transaction:
 *  1. `toStage` phải nằm trong `allowed_next` của giai đoạn hiện tại, TRỪ khi bằng giai đoạn
 *     hiện tại (§6.3). Vai trò `admin` được phép bỏ qua vế `allowed_next`, nhưng không bỏ qua
 *     việc `toStage` phải ứng với một giai đoạn có cấu hình thật của loại vụ việc.
 *  2. Kiểm tra quyền qua `MatterPolicy::transitionStage` — Action tự kiểm tra, không tin caller.
 *  3. `occurred_at` không được ở tương lai (fix round 2 review, important finding): `stage_logs`
 *     append-only nên một ngày sai ghi vào đây không bao giờ sửa lại được, và khi giai đoạn thật
 *     sự đổi, giá trị này ghi thẳng vào `matters.stage_entered_at` — đúng cột SPEC §6.4 (SLA 14
 *     ngày) và widget quá hạn §7.1 đọc để tính "đã kẹt bao lâu"; một ngày tương lai sẽ lặng lẽ
 *     đẩy vụ việc ra khỏi danh sách quá hạn. `DatePicker` phía Filament chỉ chặn bằng
 *     `maxDate()` — tiện lợi hiển thị, không phải cổng thật — nên Action, nơi duy nhất không thể
 *     bị vòng qua, phải tự kiểm tra lại. So theo NGÀY (không giờ) ở múi giờ ứng dụng
 *     (`config('app.timezone')`, PHP default timezone được `LoadConfiguration` đặt từ đó), vì
 *     form chỉ gửi ngày, không có giờ.
 *  4. `publish = true` đòi thêm ba điều kiện: (a) `matter.is_published_to_portal = true` — nếu
 *     không, một dòng công bố sẽ nằm im rồi lộ nguyên backlog ra portal ngay khi ai đó bật công
 *     tắc portal sau này, nên bị chặn từ gốc bằng `MatterNotPublishedToPortal` thay vì chỉ chặn
 *     tác dụng phụ ở bước 7; (b) `actor` phải có `stageLog.publish` — `StageLogPolicy::publish`
 *     được hỏi thật qua Gate, không giả định nó trùng với `matter.transitionStage` dù ma trận
 *     quyền hiện seed trùng nhau; (c) `public_content` tối thiểu 30 ký tự (mb_strlen, không phải
 *     byte) — ném lỗi xác thực, không cắt bớt.
 *  5. Tạo `StageLog`; `expected_next_update_at` để trống thì tự tính từ `default_next_update_days`
 *     của giai đoạn MỚI (giai đoạn hiện tại, trong trường hợp cùng giai đoạn). `created_by` /
 *     `updated_by` được gán TƯỜNG MINH từ `$actor` — Action nhận actor rõ ràng để kiểm tra quyền,
 *     nên dòng trong sổ pháp lý append-only này phải ghi đúng actor đó, không suy luận (có thể
 *     sai, hoặc rỗng) từ `auth()` ambient như `HasBlameable` mặc định làm.
 *  6. Cập nhật `matters.stage`; `stage_entered_at` CHỈ đổi khi giai đoạn thật sự thay đổi — một
 *     dòng cập nhật không đổi giai đoạn (§6.3) không được phép tua lại "đã ở giai đoạn này bao
 *     lâu", vì SPEC §6.4 (SLA 14 ngày) và widget quá hạn ở §7.1 đọc tín hiệu đó. Cùng điều kiện
 *     "giai đoạn thật sự thay đổi" còn ghi `closed_at` (R8, M6.5 Task 5): VÀO một giai đoạn
 *     `is_terminal` đặt `closed_at = now()`; RỜI một giai đoạn `is_terminal` (đường bỏ qua của
 *     admin — `is_terminal` thường không khai báo `allowed_next` quay ra) xoá nó về `null`. Đây
 *     là chỗ DUY NHẤT trong `app/` ghi cột này; `Matter::scopeOpen()` đọc lại nó.
 *     `App\Support\MatterStaleness` (widget "quá hạn cập nhật" và cột danh sách) đọc chung định
 *     nghĩa "đang mở" qua scope đó.
 *  7. Chỉ khi `publish` VÀ `matter.is_published_to_portal`: cập nhật `last_client_update_at` và
 *     dispatch `StageLogPublished` (listener + job gửi thông báo thuộc M6, không viết ở đây).
 *     Bước 6 và 7 dùng CHUNG một lệnh `update()` để không tạo hai dòng "updated" riêng của
 *     spatie/laravel-activitylog cho một thao tác của luật sư (xem bước 8).
 *  8. Ghi activity log — luôn ghi, kể cả khi không có gì bất thường, và ghi rõ nếu bước 1 đã bị
 *     một admin bỏ qua (`bypassed_allowed_next`), để dấu vết không bị mất. Causer được truyền
 *     tường minh là `$actor`, cùng lý do với bước 5.
 *
 * **Bước 0 (`stage/stage-05`, Review Focus 4, M6.5 Task 10) — khoá dòng `matters` TRƯỚC MỌI THỨ
 * KHÁC.** `lockForUpdate()` là câu lệnh ĐẦU TIÊN chạm CSDL bên trong transaction — không một
 * `SELECT` trần nào đứng trước nó. Trên MariaDB REPEATABLE READ, câu lệnh ĐẦU TIÊN chạy trong một
 * transaction đóng băng snapshot cho MỌI lần đọc "thường" (non-locking) sau đó trong CÙNG
 * transaction; một `SELECT` trần trước `lockForUpdate()` sẽ khoá đúng dòng nhưng ĐỌC RA dữ liệu
 * của snapshot đã đóng băng từ trước — khoá xong vẫn sai. `scopelessly()` (từ
 * {@see ReadsWithoutPortalScope}) gỡ `ClientPortalScope`, cùng lý do với `OpensDeadline`: một
 * nhân sự có thể đang mở song song một phiên `/portal` trong cùng trình duyệt.
 *
 * Không có khoá này, hai lần gọi `handle()` gần như đồng thời trên CÙNG một vụ việc đều đọc
 * `$matter->stage` TRƯỚC transaction, đều vượt qua kiểm tra `allowed_next` của CÙNG một giai đoạn
 * gốc, và đều ghi một `StageLog` — hai dòng append-only mâu thuẫn nhau, khách có thể nhận hai thư
 * chồng nhau, và vụ việc dừng lại ở giai đoạn của LẦN GHI SAU (đúng hình dạng `stage/stage-05`).
 *
 * `$expectedFromStage` (giai đoạn của $matter caller cầm trong tay, đọc TRƯỚC transaction) so với
 * giai đoạn đọc lại được DƯỚI KHOÁ: khác nhau nghĩa là một lần chuyển giai đoạn KHÁC đã chen vào
 * và commit trong lúc request này còn đợi khoá — từ chối thẳng bằng {@see MatterStageChanged},
 * KHÔNG lặng lẽ coi đó là một dòng "cùng giai đoạn" (§6.3) dù `to_stage` vô tình trùng giai đoạn
 * mới, và KHÔNG dùng lại `InvalidStageTransition` (xem docblock lớp đó cho lý do).
 */
class TransitionMatterStage
{
    use ReadsWithoutPortalScope;

    public function handle(
        Matter $matter,
        User $actor,
        string $toStage,
        DateTimeInterface|string $occurredAt,
        ?string $internalNote,
        ?string $publicContent,
        ?string $nextStep,
        ?string $clientAction,
        DateTimeInterface|string|null $expectedNextUpdateAt,
        bool $publish,
    ): StageLog {
        $matterId = $matter->getKey();
        $expectedFromStage = $matter->stage;

        return DB::transaction(function () use (
            $matterId, $expectedFromStage, $actor, $toStage, $occurredAt, $internalNote, $publicContent,
            $nextStep, $clientAction, $expectedNextUpdateAt, $publish,
        ): StageLog {
            // Bước 0 — xem docblock lớp. Câu lệnh ĐẦU TIÊN chạm CSDL trong transaction này.
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->findOrFail($matterId);

            if ($matter->stage !== $expectedFromStage) {
                throw MatterStageChanged::make($matter);
            }

            $fromStage = $matter->stage;
            $isSameStage = $toStage === $fromStage;
            $currentStageConfig = $matter->currentStage();

            // Giai đoạn mới phải có cấu hình thật, dù là dòng cùng giai đoạn (thì đó chính là
            // $currentStageConfig, luôn tồn tại) hay một chuyển giai đoạn thật sự.
            $targetStageConfig = $isSameStage ? $currentStageConfig : $matter->matterType->stage($toStage);

            if ($targetStageConfig === null) {
                throw InvalidStageTransition::make($matter, $fromStage, $toStage);
            }

            // Bước 1: allowed_next không áp dụng cho dòng cùng giai đoạn (SPEC §6.3).
            $bypassedAllowedNext = false;

            if (! $isSameStage && ! ($currentStageConfig?->allows($toStage) ?? false)) {
                if (! $actor->hasRole(Role::Admin->value)) {
                    throw InvalidStageTransition::make($matter, $fromStage, $toStage);
                }

                $bypassedAllowedNext = true;
            }

            // Bước 2: Action tự kiểm tra quyền, không dựa vào caller đã kiểm tra hay chưa.
            Gate::forUser($actor)->authorize('transitionStage', $matter);

            // Bước 3 (xem docblock lớp): occurred_at không được ở tương lai. So theo ngày, không
            // giờ — DatePicker chỉ gửi ngày, và today()/Carbon::instance() đều đọc múi giờ ứng
            // dụng đã đặt qua config('app.timezone').
            $occurredAtDate = $occurredAt instanceof DateTimeInterface
                ? Carbon::instance($occurredAt)
                : Carbon::parse($occurredAt);

            if ($occurredAtDate->copy()->startOfDay()->gt(today()->startOfDay())) {
                throw ValidationException::withMessages([
                    'occurred_at' => [__('actions.transition_matter_stage.occurred_at_future')],
                ]);
            }

            // Bước 4.
            if ($publish) {
                // (b) StageLogPolicy::publish hỏi thật qua Gate — không giả định nó trùng
                // matter.transitionStage. Dùng một StageLog chưa lưu, gắn sẵn quan hệ matter, vì
                // policy cần $stageLog->matter để kiểm tra canSeeMatter().
                $transientStageLog = (new StageLog)->setRelation('matter', $matter);
                Gate::forUser($actor)->authorize('publish', $transientStageLog);

                // (a) Không cho một dòng công bố nằm chờ trên một vụ việc chưa bật portal.
                if (! $matter->is_published_to_portal) {
                    throw MatterNotPublishedToPortal::make($matter);
                }

                // (c) mb_strlen, không phải strlen: một chuỗi tiếng Việt 29 ký tự có thể dài hơn
                // 30 byte, và strlen sẽ sai chấp nhận nó.
                if (mb_strlen(trim($publicContent ?? '')) < 30) {
                    throw ValidationException::withMessages([
                        'public_content' => [__('actions.transition_matter_stage.public_content_too_short')],
                    ]);
                }
            }

            // Bước 5. Công thức này CỐ Ý trùng với
            // App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema::stageDefaultNextUpdateAt()
            // (fix round 1, task 9, finding E) — bên đó chỉ tính để prefill/gợi ý trên form, đây mới
            // là nơi tính lại thật sự khi form gửi lên rỗng. Đổi công thức thì phải sửa cả hai nơi.
            $expectedNextUpdateAt ??= now()->addDays($targetStageConfig->default_next_update_days);

            $stageLog = new StageLog([
                'matter_id' => $matter->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'occurred_at' => $occurredAt,
                'internal_note' => $internalNote,
                'public_content' => $publicContent,
                'next_step' => $nextStep,
                'client_action' => $clientAction,
                'expected_next_update_at' => $expectedNextUpdateAt,
                'is_published' => $publish,
                'published_at' => $publish ? now() : null,
            ]);
            // `blameOn()` TRƯỚC khi save(): HasBlameable đọc actor tường minh trước phiên
            // `auth('web')` ambient, nên giá trị đặt ở đây luôn thắng, bất kể phiên đang mở là ai
            // — kể cả khi không có phiên đăng nhập nào (lệnh console, job).
            $stageLog->blameOn($actor)->save();

            // Bước 6 + 7, gộp một lệnh update() (xem docblock lớp).
            $publishedToPortal = $publish && $matter->is_published_to_portal;

            $matterUpdates = ['stage' => $toStage];

            if (! $isSameStage) {
                $matterUpdates['stage_entered_at'] = $occurredAt;

                // R8 (M6.5 Task 5): đúng MỘT nơi ghi closed_at trong toàn hệ thống. VÀO một giai
                // đoạn is_terminal đóng vụ việc (dùng now(), không dùng $occurredAt — "đóng vụ"
                // là một hành động của HÔM NAY, khác với "xảy ra vào ngày" mà occurred_at ghi lại
                // cho stage_entered_at/StageLog). RỜI một giai đoạn is_terminal — trên thực tế
                // luôn qua đường bỏ qua của admin, vì is_terminal thường không khai báo
                // allowed_next — mở lại vụ việc, nên closed_at về null. Một dòng cùng giai đoạn
                // (§6.3, $isSameStage) không rơi vào nhánh này, cùng lý do với stage_entered_at
                // ngay trên: nó không phải một lần VÀO hay RỜI giai đoạn nào cả.
                //
                // Fix round 1 (R8 minor): "VÀO một giai đoạn terminal" chỉ tính là VÀO LẦN ĐẦU —
                // tức giai đoạn TRƯỚC ĐÓ không phải terminal. Bản đầu chỉ hỏi giai đoạn ĐÍCH,
                // nên chuyển từ một giai đoạn terminal SANG một giai đoạn terminal KHÁC (ví dụ
                // "Kết thúc" → "Lưu trữ", cả hai đều is_terminal) bị coi là một lần VÀO mới, ghi
                // đè `closed_at` bằng `now()` — xoá mất ngày vụ việc THẬT SỰ đã đóng. Giai đoạn
                // TRƯỚC đã terminal rồi thì vụ việc đã đóng rồi; chuyển tiếp sang một giai đoạn
                // terminal khác không phải một lần đóng MỚI, nên không ghi gì cho `closed_at` —
                // giữ nguyên giá trị đang có.
                //
                // Final review X9 (C-I3): hỏi `closed_at` ĐANG CÓ, không hỏi giai đoạn đang đứng
                // có terminal không. Cờ `is_terminal` của giai đoạn đang đứng có thể đã được sửa
                // SAU khi vụ vào đó (dữ liệu cũ, trước khi `MatterTypeStage` chặn bật/tắt cờ khi
                // còn hồ sơ đứng ở đó) — hỏi cờ đó để lại `closed_at` mắc kẹt cả hai chiều. Giai
                // đoạn ĐÍCH quyết định: terminal và chưa đóng → đóng hôm nay; terminal và đã đóng
                // → giữ ngày đóng thật; không terminal → mở lại.
                if ($targetStageConfig->is_terminal) {
                    if ($matter->closed_at === null) {
                        $matterUpdates['closed_at'] = now();
                    }
                } elseif ($matter->closed_at !== null) {
                    $matterUpdates['closed_at'] = null;
                }
            }

            if ($publishedToPortal) {
                $matterUpdates['last_client_update_at'] = now();
            }

            // Cùng lý do như `$stageLog` ở trên, cho dòng `matters`: `HasBlameable::updating`
            // rơi về `auth('web')` ambient nếu không ai tuyên bố actor, nên phải `blameOn()` thì
            // cột mới chỉ đúng người vừa chuyển giai đoạn.
            $matter->blameOn($actor)->update($matterUpdates);

            // M7 Task 3. CHỈ khi giai đoạn THẬT SỰ đổi — một dòng cập nhật không đổi giai đoạn
            // (§6.3) không "chuyển" gì để một listener lưu trữ phải chạy lại. Độc lập với
            // `$publishedToPortal`/`$publish`: một lần chuyển giai đoạn NỘI BỘ (không công bố)
            // vẫn có thể là lần vụ việc đóng hay mở lại — `SyncMatterArchiveOnStageChange` phải
            // chạy cho cả hai, không chỉ cho những lần công bố ra portal.
            //
            // Rà soát cuối M7, I3 — phát TRƯỚC `StageLogPublished`. Cả hai sự kiện đợi commit và
            // chạy theo đúng thứ tự phát. Thư báo tiến độ hỏi "vụ còn trên cổng của người nhận
            // không" (`MatterPolicy::view`, gồm hạn tra cứu), nên dòng lưu trữ phải được đồng bộ
            // TRƯỚC: mở lại một vụ đã quá hạn tra cứu xoá `client_access_until`, và chỉ sau đó vụ
            // mới trở lại cổng. Phát ngược lại thì với hàng đợi `sync` thư đi (hay không đi) theo
            // hạn tra cứu CŨ, và với hàng đợi thật là một cuộc đua giữa worker và listener đồng bộ.
            if (! $isSameStage) {
                event(new MatterStageChangedEvent($stageLog));
            }

            if ($publishedToPortal) {
                event(new StageLogPublished($stageLog));
            }

            // Bước 8.
            Audit::record('matter_stage_transitioned', $matter, [
                'stage_log_id' => $stageLog->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'same_stage' => $isSameStage,
                'publish' => $publish,
                'published_to_portal' => $publishedToPortal,
                'bypassed_allowed_next' => $bypassedAllowedNext,
            ], $actor);

            return $stageLog;
        });
    }
}
