<?php

namespace App\Models;

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentGroupNotChangeable;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Audit;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Tệp vật lý (đúng một tệp mỗi bản ghi `Document` — mỗi lần nộp lại tạo một `Document` MỚI với
 * `version + 1`, SPEC §6.6 bước 7, chứ không phải nhiều tệp trên cùng một `Document`) do
 * `spatie/laravel-medialibrary` quản lý trên disk `private` (`storage/app/private`, SPEC §10.4).
 * Đây là toàn bộ trách nhiệm của model đối với medialibrary — việc SINH TÊN TỆP NGẪU NHIÊN khi
 * lưu, không dùng tên gốc do người nộp đặt, là việc của `UploadStaffDocument`/
 * `SubmitClientDocument` (Task 3/4) lúc gọi `addMedia(...)->usingFileName(...)`, không phải của
 * khai báo collection ở đây.
 */
class Document extends Model implements HasMedia
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use LogsActivity;
    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'matter_checklist_item_id', 'group', 'title', 'status', 'version',
        'parent_document_id', 'uploader_type', 'uploader_id', 'client_can_view', 'client_can_download',
        'published_at', 'published_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'group' => DocumentGroup::class,
            'status' => DocumentStatus::class,
            'version' => 'integer',
            'client_can_view' => 'boolean',
            'client_can_download' => 'boolean',
            'published_at' => 'datetime',
            'issued_at' => 'date',
        ];
    }

    /**
     * SPEC §10.4: "route có `signed` URL hết hạn sau 5 phút". Con số nằm ở đây chứ không ở
     * `config/`: nó là một điều khoản của đặc tả bảo mật, không phải một nút vặn cho người vận
     * hành, và một biến môi trường đặt nó thành 30 ngày sẽ không để lại dấu vết nào.
     */
    public const DOWNLOAD_LINK_MINUTES = 5;

    /** Tên tham số mang mã người nhận trong URL đã ký — xem {@see self::downloadUrlFor()}. */
    public const DOWNLOAD_RECIPIENT_PARAMETER = 'recipient';

    /**
     * Đường dẫn tải tệp, ký cho ĐÚNG MỘT người và sống 5 phút (SPEC §10.4).
     *
     * Người nhận được ký KÈM chứ không chỉ được ngụ ý, và `DocumentDownloadController` đòi mã đó
     * khớp người đang đăng nhập. Lý do đầy đủ nằm ở docblock controller; nói ngắn ở đây: một URL
     * đã ký mà không nêu người nhận là một tấm vé vô danh dùng được trong 5 phút, và
     * `document_downloads` (SPEC §4.12) sẽ ghi tên người bấm chứ không phải tên người được trao.
     *
     * Hàm này KHÔNG kiểm tra quyền, và không được phép kiểm: nơi quyết định là policy trong
     * controller, ở thời điểm tải, chứ không phải ở thời điểm dựng đường dẫn — giữa hai thời
     * điểm đó có 5 phút để một tài khoản bị vô hiệu hoặc một tài liệu đổi nhóm.
     */
    public function downloadUrlFor(User|ClientUser $recipient): string
    {
        return URL::temporarySignedRoute(
            'documents.download',
            now()->addMinutes(self::DOWNLOAD_LINK_MINUTES),
            [
                'document' => $this->getKey(),
                self::DOWNLOAD_RECIPIENT_PARAMETER => self::recipientToken($recipient),
            ],
        );
    }

    /**
     * Mã người nhận dùng trong URL đã ký. Dùng `getMorphClass()` (morph map NGHIÊM NGẶT, xem
     * `AppServiceProvider`) nên chuỗi là `user:12` / `client_user:7`: ngắn, ổn định, và không
     * bao giờ trùng nhau giữa hai guard — hai tài khoản khác guard cùng mang id 12 vẫn là hai mã
     * khác nhau.
     */
    public static function recipientToken(User|ClientUser $recipient): string
    {
        return $recipient->getMorphClass().':'.$recipient->getKey();
    }

    /**
     * Bật lên trong đúng khoảng thời gian `RegroupDocument` đang ghi, và chỉ ở đó — xem
     * {@see self::duringAuditedRegroup()} và hook `saving` ở {@see self::booted()}.
     */
    private static bool $duringAuditedRegroup = false;

    /**
     * Cửa duy nhất để một tài liệu rời nhóm D.
     *
     * **Vì sao hàng rào nằm ở model mà nghiệp vụ vẫn ở Action.** CLAUDE.md đặt nghiệp vụ trong
     * `app/Actions/`, và nó vẫn ở đó: AI được phép chuyển nhóm, dòng nhật ký ghi gì, câu từ chối
     * nói gì — toàn bộ nằm trong `RegroupDocument`. Hook ở đây không hỏi một câu nào về người
     * đang thao tác và không quyết định gì; nó chỉ làm cho một bất biến DỮ LIỆU không bị phá bởi
     * một đường đi vòng qua Action. Đó đúng vai trò mà `Matter::forceDeleting` →
     * `MatterNotDestroyable` đã giữ trong dự án này từ M1.
     *
     * Hàng rào phải ở model chứ không thể chỉ ở Action, vì cái nó chặn LÀ đường không đi qua
     * Action: một form Filament gọi `$record->update()`, một lệnh console, một seeder. Nhóm D là
     * ranh giới mà SPEC §4.11 gọi là tuyệt đối, và một ranh giới tuyệt đối chỉ do một Action
     * canh thì tuyệt đối cho tới màn hình đầu tiên quên gọi Action đó.
     */
    public static function duringAuditedRegroup(callable $callback): mixed
    {
        static::$duringAuditedRegroup = true;

        try {
            return $callback();
        } finally {
            static::$duringAuditedRegroup = false;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (Document $document): void {
            // SPEC §4.11: nhóm D có `client_can_download` **vĩnh viễn false**. Cho tới nay câu
            // đó chỉ đúng ở giá trị khởi tạo của `UploadStaffDocument`; một lệnh `update()`
            // thẳng vẫn bật được cờ lên. Khách không thấy tài liệu đó (global scope và policy
            // đều loại nhóm D) nên chưa phải một lỗ hổng, nhưng nó là một dòng dữ liệu nói dối —
            // và một dòng nói dối là thứ mà lần đọc sau sẽ tin.
            if ($document->group === DocumentGroup::Internal) {
                $document->client_can_download = false;
            }

            // Rời khỏi nhóm D chỉ có một cửa, và cửa đó ghi nhật ký (`RegroupDocument`). Trước
            // guard này, `update(['group' => 'C'])` đi lọt mà KHÔNG để lại dòng nhật ký nào, nên
            // thứ duy nhất còn lại sau đó là một dòng `document_published` về một tài liệu nhóm
            // C — đúng sự thật ở thời điểm đó, và vô dụng với người đi tìm chuyện gì đã xảy ra.
            //
            // So trên `getRawOriginal()` chứ không `getOriginal()`: bản có cast trả về enum, và
            // một so sánh enum ở đây sẽ đổi nghĩa lặng lẽ nếu cast đổi.
            if ($document->exists
                && $document->isDirty('group')
                && $document->getRawOriginal('group') === DocumentGroup::Internal->value
                && ! static::$duringAuditedRegroup
            ) {
                throw DocumentGroupNotChangeable::leavingInternalGroup();
            }
        });
    }

    /**
     * Đúng một tệp cho mỗi `Document` (SPEC §6.6 bước 6: "một tệp mỗi document"). `singleFile()`
     * tự xoá tệp cũ khi có tệp mới gán vào collection này — không phải vấn đề ở đây vì một
     * `Document` không bao giờ bị gán tệp lần hai (nộp lại tạo bản ghi `Document` mới với
     * `parent_document_id`, không ghi đè tệp của bản ghi cũ — xem docblock lớp).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')
            ->useDisk('private')
            ->singleFile();
    }

    /**
     * Khách chỉ thấy tài liệu được bật cho xem và không bao giờ thấy nhóm D (SPEC §4.11, §5).
     *
     * Điều kiện `status = published` là điều kiện thứ ba, và nó KHÔNG thừa so với
     * `client_can_view`: hai cột trả lời hai câu khác nhau. `client_can_view` nói "khi tài liệu
     * này ra tới khách thì khách được xem", còn `status` nói "nó đã ra tới khách chưa". Vòng đời
     * nhóm B ở SPEC §4.11 (`internal_draft → pending_approval → signed_filed → published`) tồn
     * tại chính là để "ngăn khách nhìn thấy một bản đơn mà toà chưa hề nhận được", nên một bản
     * nháp có ai đó bật sẵn `client_can_view` vẫn phải nằm ngoài mọi truy vấn portal.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('client_can_view'), true)
            ->where($this->qualifyColumn('status'), DocumentStatus::Published->value)
            ->where($this->qualifyColumn('group'), '!=', DocumentGroup::Internal->value)
            ->whereHas('matter');
    }

    /**
     * KHÔNG phải một lần kiểm tra quyền đầy đủ — đừng gọi hàm này một mình. Nó chỉ trả lời
     * câu hỏi về BẢN THÂN tài liệu ("bản này đã ra tới cổng khách chưa"), bằng ba điều kiện đọc
     * thẳng trên thuộc tính thay vì qua một truy vấn:
     *
     * - `client_can_view` và `group != D` là hai trong ba điều kiện SPEC §5 đặt cho `Document`;
     * - `status = published` KHÔNG có ở §5, nó đến từ vòng đời nhóm B ở §4.11 ("không được nhảy
     *   thẳng sang `published`"), và Task 2 đặt nó cạnh hai điều kiện kia vì cả ba cùng trả lời
     *   một câu hỏi.
     *
     * Điều kiện thứ ba của §5 — tài liệu thuộc một vụ việc khách được thấy — CỐ Ý không nằm ở
     * đây: nó là chuyện của `Matter`, và `DocumentPolicy::view()` lo bằng `canSeeMatter()` cộng
     * `visibleToPortal()`. Vì vậy mọi câu hỏi "khách này có được xem bản này không" phải đi qua
     * `Gate::allows('view', $document)`, không bao giờ qua riêng hàm này.
     *
     * Lý do hàm tồn tại cạnh `visibleToPortal()` — nói lại cùng một luật bằng một thứ ngôn ngữ
     * khác — nằm ở docblock `DocumentPolicy`.
     */
    public function isReleasedToPortal(): bool
    {
        return $this->client_can_view
            && $this->status === DocumentStatus::Published
            && ! $this->group->isInternal();
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(MatterChecklistItem::class, 'matter_checklist_item_id');
    }

    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_document_id');
    }

    public function newerVersions(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_document_id')->orderBy('version');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class);
    }

    /**
     * `Document` là model DUY NHẤT có một nhóm mà SPEC gọi là ranh giới tuyệt đối, nên mọi cột
     * quyết định "ai đọc được tệp này" phải đọc lại được từ nhật ký: `group`, `status` và hai cờ
     * khách hàng. Không có chúng thì một lần đổi nhóm D → C chỉ để lại một khoảng trống.
     *
     * Sự kiện `deleted` cũng được ghi (mặc định của trait), và nó đóng việc mang sang từ rà soát
     * Task 2 — "xoá tài liệu không có dấu vết". Với một model có `SoftDeletes`, spatie ghi giá
     * trị của dòng vừa biến mất dưới khoá `old` (KHÔNG phải `attributes` — đã kiểm bằng cách
     * chạy thật, và có test ghim), nên dòng nhật ký của một tài liệu nhóm D bị xoá vẫn đọc ra
     * `group = D` kèm tiêu đề: người rà soát biết thứ vừa biến mất là hồ sơ công việc nội bộ
     * chứ không phải một văn bản của khách.
     *
     * `published_at`/`published_by` KHÔNG nằm ở đây: mọi lần chúng được ghi đều đi kèm một dòng
     * nhật ký của `Audit` với actor tường minh, và dòng đó nói được nhiều hơn (causer của trait
     * suy ra từ phiên đăng nhập, có thể trống với một lệnh console).
     *
     * Bản đầu của câu trên viết "một dòng `document_published`", và nó đúng cho tới đúng ngày
     * `SubmitClientDocument` ra đời: một tệp khách tự gửi lên cũng được ghi `published_at` —
     * tệp đó ở trong tầm tay khách ngay lúc tạo — nhưng dấu vết của nó là `document_submitted`,
     * vì không ai trong văn phòng quyết định đưa thứ gì ra. Tên sự kiện nào trả lời câu nào, và
     * vì sao "khách đọc được những gì" là HỢP của hai tên chứ không phải một, được phát biểu ở
     * một chỗ duy nhất: docblock của {@see Audit}.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'matter_id', 'matter_checklist_item_id', 'group', 'title', 'status', 'version',
                'parent_document_id', 'client_can_view', 'client_can_download',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
