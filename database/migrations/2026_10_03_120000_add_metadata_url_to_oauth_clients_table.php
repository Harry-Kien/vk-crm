<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R7 (Task 5) — `oauth_clients.metadata_url`: URL `client_id` của một client CIMD (Client ID
 * Metadata Document). Khoá DUY NHẤT: mỗi URL đúng một dòng client, do
 * `App\Actions\Mcp\ResolveClientIdMetadataDocument` tạo hoặc cập nhật từ tài liệu tải về.
 *
 * Passport giữ `id` là UUID (cột `uuid`, token và mã uỷ quyền trỏ về nó), nên URL không thể là
 * khoá chính: client gửi URL làm `client_id`, `App\Support\Mcp\McpClientRepository` đổi URL ra dòng
 * này, và league/oauth2-server chỉ thấy UUID của dòng.
 *
 * 255 ký tự: `ResolveClientIdMetadataDocument::MAX_URL_LENGTH` từ chối URL dài hơn TRƯỚC khi tải gì
 * (MariaDB strict: ghi quá cột là lỗi 500). Rỗng với mọi client khác (DCR, `passport:client`); chỉ
 * mục unique cho phép nhiều giá trị rỗng.
 *
 * Cùng kết nối với các bảng của Passport (`passport.connection`), như bốn migration gốc của gói.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('metadata_url', 255)->nullable()->unique()->after('is_mcp');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropUnique(['metadata_url']);
            $table->dropColumn('metadata_url');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
