<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Đích của một route ứng dụng cố ý KHÔNG phục vụ: mọi request tới nó là 404 (SPEC §10.10).
 *
 * Dùng ở `routes/web.php` để đè hai route mà Filament đăng ký vô điều kiện
 * (`filament.exports.download`, `filament.imports.failed-rows.download`, lượt quét §10 trước bản 1.0).
 * Lớp invokable thay cho closure để `php artisan optimize` (`route:cache`) luôn tuần tự hoá được.
 */
final class RespondNotFound extends Controller
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException;
    }
}
