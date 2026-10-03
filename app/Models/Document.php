<?php

namespace App\Models;

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentGroupNotChangeable;
use App\Exceptions\DocumentReferencedByBillingRecord;
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
     *
     * **Route là bí danh TRONG scope của app trên điện thoại** (M12 Task 3, `routes/web.php`):
     * `ClientUser` → `/portal/documents/{id}/download`, `User` → `/admin/documents/{id}/download`.
     * Chọn theo KIỂU người nhận — mỗi guard chỉ đăng nhập được đúng một panel — chứ không theo
     * panel hiện hành, vì URL có thể được dựng ngoài một request của panel. Cùng controller, cùng
     * middleware với `documents.download`; chữ ký phủ cả path, nên đổi tiền tố của một URL đã ký là
     * 403.
     */
    public function downloadUrlFor(User|ClientUser $recipient): string
    {
        return URL::temporarySignedRoute(
            $recipient instanceof ClientUser ? 'documents.download.portal' : 'documents.download.admin',
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
            //
            // `client_can_view` bị hạ CÙNG LÚC, và nó là phần bản đầu thiếu. SPEC §4.11 chỉ viết
            // "vĩnh viễn false" cho cột tải, nên bản đầu chỉ hạ cột đó — và để lại đúng cái dòng
            // nói dối mà hook này tồn tại để ngăn, thiếu một cột: một dòng nhóm D mang
            // `client_can_view = 1`, tức một dòng dữ liệu khẳng định khách được xem một thứ mà
            // §4.11 gọi là ranh giới tuyệt đối. Đo được, rồi hoàn lại: `A (published, hai cờ
            // bật) → D` để lại `client_can_view = 1` trên một dòng nhóm D.
            //
            // Hai hệ quả nữa, và chúng mới là lý do quyết định:
            //
            //  - **Vòng `A → D → A` TRẢ tài liệu về cho khách mà không có một dòng công bố nào.**
            //    Cột `status` vẫn là `published`, nên nếu `client_can_view` sống sót chuyến đi
            //    thì ngay khi tài liệu rời nhóm D nó lại nằm trong tầm mắt khách —
            //    `isReleasedToPortal()` đúng trở lại — và thứ duy nhất trong nhật ký là hai dòng
            //    `document_regrouped`. Một người đi dựng lại "văn phòng đã đưa những gì ra trước
            //    mặt khách" chỉ lọc được theo TÊN sự kiện (SPEC §10.6), nên lần ra đó vô hình.
            //    Hạ cờ xuống thì đường duy nhất về lại tay khách là `PublishDocument`, và nó ghi
            //    `document_published`.
            //  - **Đối xứng với cột tải.** Cột tải đã bị hạ vĩnh viễn từ trước và KHÔNG được
            //    phục hồi khi rời nhóm D, nên lập luận "giữ cờ lại để chuyến khứ hồi trả về
            //    nguyên trạng" đã chết sẵn một nửa. Giữ một cột và hạ cột kia cho ra thứ tệ nhất
            //    trong ba lựa chọn: một tài liệu quay lại trạng thái "khách xem được nhưng không
            //    tải được" mà không ai quyết định điều đó.
            //
            // Cái giá, nói thẳng vì người vận hành phải biết trước khi bấm: chuyển nhầm một tài
            // liệu vào nhóm D là **thu hồi quyền xem của khách, không tự trả lại**. Câu đó nằm ở
            // helper text của ô chọn nhóm khi chuyển nhóm (`documents.tab.fields.target_group_help`).
            // Và sau một vòng khứ hồi, `PublishDocument` ghi `published_at`/`published_by` MỚI
            // thay vì giữ mốc cũ — đúng: lần ra tới khách trước đó đã chấm dứt, còn mốc cũ vẫn
            // nằm nguyên trong dòng `document_published` của nó.
            if ($document->group === DocumentGroup::Internal) {
                $document->client_can_view = false;
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

        // Gộp M6.5 + M9 (xung đột 5): tệp đang được một bản ghi tiền trỏ tới không xoá được — mềm
        // lẫn cứng (`forceDelete()` cũng đi qua `deleting`). Hai khoá ngoại ấy là `nullOnDelete`,
        // nên không có hook này một lần xoá cứng sẽ lặng lẽ cắt bằng chứng khỏi một phụ lục/khoản
        // thu bất biến. `DocumentPolicy::delete` trả lời cùng câu cho nút xoá; đây là chốt chặn cho
        // mọi đường không hỏi policy.
        static::deleting(function (Document $document): void {
            if ($document->isReferencedByBillingRecord()) {
                throw DocumentReferencedByBillingRecord::make();
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
            // Một tài liệu đã bị rút khỏi hồ sơ (xoá mềm) không quay lại tay khách bằng một lần
            // `withTrashed()` — thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới scope này.
            // Cùng lý lẽ với `Matter::applyClientPortalConstraints()`.
            ->whereNull($this->qualifyColumn('deleted_at'))
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
     * `! trashed()` là điều kiện thứ tư, và nó đến từ chính `applyClientPortalConstraints()` ngay
     * trên. Nó KHÔNG thừa: nếu không phát biểu ở đây thì điều kiện "đã rút thì không ra tới
     * cổng" chỉ còn được giữ bên trong `visibleToPortal()` — tức bởi `SoftDeletingScope`, một
     * scope KHÁC, thứ mà một lần `withTrashed()` gỡ ra. Đó đúng là hình dạng mà vòng sửa này lên
     * án ở ba model khác, nên nó không được phép sống sót ở đây. Phát biểu bằng THUỘC TÍNH, như
     * `MatterPolicy::releasedToPortal()` làm, để hai cách nói không chung một câu lệnh nào.
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
            && ! $this->group->isInternal()
            && ! $this->trashed();
    }

    /**
     * "Tài liệu này đã từng được CÔNG BỐ cho khách chưa" — MỘT chỗ định nghĩa duy nhất cho đúng
     * hai cột mà `PublishDocument` dùng để phân biệt "lần công bố đầu" với "công bố lại"
     * (`status = published` CỘNG `client_can_view = true`). Trước vòng sửa 1 của Task 16,
     * `PublishDocument` tính thẳng biểu thức này, còn `RegroupDocument` tính một biểu thức
     * KHÁC (`status` thuộc `[signed_filed, published]`, thiếu điều kiện `client_can_view`) cho
     * cùng một câu hỏi "tài liệu nhóm B này đã đi hết vòng đời chưa" — hai định nghĩa lệch nhau
     * cho đúng một trường hợp có thật: một tài liệu `published` mà `client_can_view = false` (ví
     * dụ vừa đi qua vòng D → B, xem hook `saving` phía trên — vào D hạ cờ, ra khỏi D không trả
     * lại). `RegroupDocument` cũ sẽ coi tài liệu đó "đã đi hết vòng đời" dù nó KHÔNG còn hiện với
     * khách, tức cho rời nhóm B mà không đòi chữ ký thật hay một lý do — đúng lỗ hổng vòng sửa 1
     * chỉ ra. Nay cả hai Action gọi đúng một hàm này.
     *
     * **Final review X7 (C-I1) — câu "vòng đời" đã tách ra khỏi hàm này.** Phán quyết lượt rà soát
     * cuối: một văn bản B `published` mà cờ xem đã tắt (vừa đi B → D → B) ĐÃ đi hết vòng đời — nó
     * phải công bố lại được, và rời B không cần lý do sửa nhầm nhóm. Câu "đã đi hết vòng đời chưa"
     * giờ là {@see self::hasClearedIssuedLifecycle()}; hàm này chỉ còn trả lời "đang/đã ra tới
     * khách" cho kiểm tra optimistic và nhánh "công bố lại" của `PublishDocument`.
     *
     * KHÔNG trùng `isReleasedToPortal()`: hàm đó CÒN kèm `! group->isInternal()` và `! trashed()`
     * — hai điều kiện mà cả hai caller của hàm này đều đã tự kiểm riêng (nhóm D chặn tuyệt đối ở
     * `PublishDocument`; xoá mềm chặn ở cổng đầu của cả hai Action), nên lặp lại chúng ở đây sẽ
     * làm một lời gọi trông như thừa.
     */
    public function wasPublishedToClient(): bool
    {
        return $this->status === DocumentStatus::Published && $this->client_can_view;
    }

    /**
     * "Văn bản nhóm B này đã đi hết vòng đời chưa" (SPEC §4.11) — final review X7 (C-I1), MỘT định
     * nghĩa cho cả `PublishDocument` (cổng vào `published` của nhóm B) lẫn `RegroupDocument` (cổng
     * rời nhóm B sang A/C) và ô lý do của màn hình chuyển nhóm.
     *
     * `signed_filed`; HOẶC đang trong tầm mắt khách (`wasPublishedToClient()`); HOẶC `published` mà
     * ĐÃ THẬT SỰ ra tới khách một lần (`published_at` có giá trị) dù cờ xem nay đã tắt. Vế cuối là
     * cái mới, và khác `wasPublishedToClient()` có chủ ý: một văn bản B đã
     * ký, đã công bố, rồi bị rút vào D (hook `saving` hạ `client_can_view`, giữ nguyên
     * `published_at`) và đưa về B mang `status = published` với cờ xem tắt. Nó đã đi hết vòng đời —
     * chữ ký và lần nộp không mất đi vì một lần rút — nên phải công bố lại được và rời B được mà
     * không phải khai "sửa nhầm nhóm".
     *
     * Vế `published_at`: mọi đường thật đưa tài liệu ra tới khách (`PublishDocument`,
     * `UploadStaffDocument`, `SubmitClientDocument`) đều ghi cột đó cùng lúc với `status`. Một lần
     * ghi tay chỉ cột `status` (sửa CSDL, một màn hình quên đi qua Action) để lại `published_at`
     * trống — và KHÔNG được thành lối tắt qua vòng đời nhóm B (`PublishDocumentTest`, "nhóm B bị
     * ghi thẳng status=published…").
     */
    public function hasClearedIssuedLifecycle(): bool
    {
        return $this->status === DocumentStatus::SignedFiled
            || $this->wasPublishedToClient()
            || ($this->status === DocumentStatus::Published && $this->published_at !== null);
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

    /**
     * Bản scan uỷ nhiệm chi / phiếu thu trỏ tới tệp này (`payments.receipt_document_id`). Đọc qua
     * {@see self::isReferencedByBillingRecord()} — không hỏi quan hệ này trực tiếp ở nơi khác.
     */
    public function paymentReceipts(): HasMany
    {
        return $this->hasMany(Payment::class, 'receipt_document_id');
    }

    /** Bản scan phụ lục hợp đồng trỏ tới tệp này (`contract_amendments.document_id`). Cùng lý do với {@see self::paymentReceipts()}. */
    public function contractAmendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class, 'document_id');
    }

    /**
     * Có bản ghi tiền nào trỏ tới tệp này không — định nghĩa DUY NHẤT (gộp M6.5 + M9, xung đột 5),
     * đọc bởi `DocumentPolicy::delete`, hook `deleting` ở {@see self::booted()}, và — khi M7 Task 7
     * dựng nó — `RetractDocument`.
     *
     * `withoutGlobalScopes()`: một khoản thu ĐÃ HUỶ vẫn là bản ghi được giữ lại và vẫn cần biên lai
     * của nó, và câu hỏi này không được đổi đáp án theo guard đang đăng nhập (`ClientPortalScope`
     * trả `1 = 0` cho mọi model tiền dưới guard khách) — "không thấy" không phải "không có".
     */
    public function isReferencedByBillingRecord(): bool
    {
        return $this->paymentReceipts()->withoutGlobalScopes()->exists()
            || $this->contractAmendments()->withoutGlobalScopes()->exists();
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
