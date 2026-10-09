<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R7 (Task 3) — `oauth_clients.is_mcp`: client do đăng ký động (DCR, `POST /oauth/register`) của
 * máy chủ MCP tạo ra. `/mcp` chỉ nhận token của client mang cờ này
 * (`App\Http\Middleware\Mcp\EnsureMcpClient`), và lệnh dọn `vkcrm:mcp-prune-clients` chỉ xoá client
 * mang cờ này.
 *
 * Mặc định `false`: client tạo bằng `passport:client` (hay bất kỳ đường nào khác ngoài
 * `App\Actions\Mcp\RegisterMcpClient`) KHÔNG phải client MCP, nên token của nó không mở được `/mcp`
 * và nó không bao giờ bị dọn tự động.
 *
 * Cùng kết nối với các bảng của Passport (`passport.connection`), như bốn migration gốc của gói.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->boolean('is_mcp')->default(false)->after('revoked');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('is_mcp');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
