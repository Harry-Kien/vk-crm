<?php

namespace App\Support\Storage;

use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * Bản trong vùng đệm (đĩa `private`) của MỘT media: thư mục `<media_id>/` mà thư viện media dựng
 * (`PathGeneratorFactory`, cùng đường mà `DefaultFileRemover` xoá), gồm tệp gốc và mọi thư mục con
 * (`conversions/`, `responsive-images/`) — kế hoạch M14, R2, R10.
 *
 * Với media đã lên kho, mã của M14 (tới Task 3) xoá thư mục này ở đúng hai nơi: lượt dọn theo biên
 * nhận văn phòng (`PurgeStagedDocumentCopies`) và listener khi media bị xoá
 * (`DiscardStagedCopyOnMediaDeleted`). Cả hai lấy thư mục ở đây, để không có hai bản của luật "thư
 * mục của media là gì". Media còn ở `private` thì thư viện media tự xoá thư mục của nó.
 *
 * Thư mục rỗng thì ném {@see LogicException}, không bao giờ trả `''`: `deleteDirectory('')` trên đĩa
 * `private` là xoá CẢ vùng đệm. Bộ dựng đường mặc định luôn có mã media nên không bao giờ rỗng; câu
 * kiểm có mặt cho một bộ dựng tự viết sau này.
 */
final class StagedCopy
{
    public static function directory(Media $media): string
    {
        $directory = trim(PathGeneratorFactory::create($media)->getPath($media), '/');

        if ($directory === '') {
            throw new LogicException('Thư mục vùng đệm của media rỗng; không xoá gốc đĩa private.');
        }

        return $directory;
    }

    /** Xoá thư mục vùng đệm của media. Thư mục đã không còn cũng là xong (`true`). */
    public static function discard(Media $media): bool
    {
        return DocumentStore::staging()->deleteDirectory(self::directory($media));
    }
}
