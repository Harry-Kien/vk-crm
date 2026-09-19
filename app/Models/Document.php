<?php

namespace App\Models;

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    public function isInternal(): bool
    {
        return $this->group->isInternal();
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
}
