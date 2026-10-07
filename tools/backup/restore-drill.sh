#!/usr/bin/env bash
#
# tools/backup/restore-drill.sh — M8a Task 3 (SPEC §10 mục 8, R3: "Sao lưu chưa khôi phục thử
# thì chưa phải sao lưu"). Một lượt khôi phục THẬT, có số đo, chạy từ Git Bash trên máy dev
# (worktree D:\vkwt\lane-m8). KHÔNG chạy trên máy chủ thật — quy trình cho máy chủ thật (kèm nơi
# cất APP_KEY/BACKUP_ARCHIVE_PASSWORD) nằm ở docs/SAO-LUU-KHOI-PHUC.md, mục "Khôi phục thử".
#
# Thứ tự đúng như Task 3 đòi:
#   1. Dựng một BẢN SAO mã nguồn làm "máy nguồn" (lượt rà soát cuối M8a, M2): seeder, dữ liệu thử
#      và `backup:run` đều chạy TRONG bản sao này, nên tệp hồ sơ mẫu, tệp của dữ liệu thử và
#      archive đều nằm trong bản sao — KHÔNG trong storage/app/private hay storage/app/backups của
#      worktree (bản trước ghi thẳng vào hai thư mục đó của worktree, và nén luôn mọi thứ đang có
#      trong storage/app/private của worktree vào archive).
#   2. Xây một CSDL NGUỒN RIÊNG của lượt chạy này (`vk_crm_lane_m8_drill_<TS>`, KHÔNG PHẢI
#      `vk_crm_lane_m8` — đĩa serve `:8090` và các task khác của làn đọc/ghi CSDL đó ĐANG lúc kịch
#      bản này có thể chạy; `migrate:fresh` xoá sạch bảng) bằng một APP_KEY DÙNG MỘT LẦN — không
#      phải APP_KEY thật của `.env` trên máy dev. Tên CSDL mang hậu tố của lượt chạy, nên hai lượt
#      diễn tập chạy cùng lúc không xoá CSDL của nhau.
#   3. Tạo thêm một khách hàng có `id_number` (qua `Client::create()`, tôn trọng cast `encrypted`)
#      và một tài liệu có tệp thật (qua `UploadStaffDocument`, Action có sẵn — cùng đường tệp thật
#      mà `MatterSeeder` dùng).
#   4. `backup:run` thật: dump CSDL bằng `mariadb-dump` (cài trong container tạm, không sửa
#      image), nén cùng tệp hồ sơ CỦA BẢN SAO NGUỒN, mã hoá AES-256, ghi ra disk `local_backups`
#      CỦA BẢN SAO NGUỒN.
#   5. Dựng một MariaDB SẠCH (container mới, không map cổng ra host) và một BẢN SAO mã nguồn SẠCH
#      thứ hai, KHÔNG có `storage/app/private`.
#   6. Giải nén archive bằng mật khẩu, nạp dump vào MariaDB sạch, chép tệp về đúng chỗ,
#      `migrate:status`, rồi giải mã `id_number` + so checksum tệp + đếm dòng các bảng chính.
#   7. Chạy lại đúng bước giải mã đó với một APP_KEY MỚI để chứng minh nó thất bại — APP_KEY là
#      một nửa của bản sao lưu (R3).
#   8. Dọn container, CSDL nguồn riêng, cả hai bản sao — không để lại archive thật, tệp tạm, hay
#      CSDL nào trong repo hay trên MariaDB dùng chung.
#
# Điều kịch bản này ĐỌC và GHI trong worktree: chỉ ĐỌC mã nguồn và `vendor` (robocopy sang bản
# sao), và chỉ GHI dưới `storage/app/_drill/<lượt chạy>/` (git bỏ qua, xoá khi thoát). Không đọc
# cũng không ghi `storage/app/private`, `storage/app/backups`, `bootstrap/cache` hay `.env` của
# worktree.
#
# Cache cấu hình (lượt rà soát cuối M8a, M1): cả hai bản sao KHÔNG chép `bootstrap/cache`, và kịch
# bản dừng ngay nếu một bản sao vẫn có `bootstrap/cache/config.php`. Với một config.php đã cache,
# Laravel bỏ qua mọi biến `-e DB_DATABASE=… -e APP_KEY=…` của `docker run` — lượt diễn tập sẽ
# seed/khôi phục vào CSDL và khoá ghi trong cache, không phải CSDL và khoá dùng một lần ở đây.
#
# Chạy lại được: mỗi lần chạy tự sinh một hậu tố (thời gian + PID) cho tên container/CSDL/thư mục
# tạm, và một `trap ... EXIT` dọn dẹp dù script thoát giữa chừng (lỗi, Ctrl-C, `set -e`).
#
# Yêu cầu trên máy dev: Docker Desktop; container `crmkhachhang-mariadb-1` đang chạy trên mạng
# `crmkhachhang_vkcrm` (kịch bản tự tạo CSDL nguồn riêng trên CHÍNH container đó ở bước 2 — không
# cần chuẩn bị gì trước); Git Bash (MSYS) trên Windows với `openssl`, `cygpath`, `robocopy` sẵn có
# (đều là công cụ có sẵn của Git for Windows / Windows).
#
# Bí mật: KHÔNG bao giờ ghi APP_KEY hay BACKUP_ARCHIVE_PASSWORD ra file, log, hay report — cả hai
# được SINH MỚI (dùng một lần) ở mỗi lần chạy, không đọc từ `.env` thật của làn.

set -euo pipefail
export MSYS_NO_PATHCONV=1

# --------------------------------------------------------------------------------------------
# Đường dẫn & hằng số
# --------------------------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LANE_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LANE_DIR_WIN="$(cygpath -w "${LANE_DIR}")"

TS="$(date +%Y%m%d%H%M%S)_$$"
IMAGE='webdevops/php:8.3-alpine'
NETWORK='crmkhachhang_vkcrm'
SOURCE_DB_CONTAINER='crmkhachhang-mariadb-1'
# CSDL NGUỒN RIÊNG của LƯỢT CHẠY này — KHÔNG PHẢI `vk_crm_lane_m8` (đĩa serve `:8090` của làn, các
# task khác có thể đang dùng đúng lúc này), và mang hậu tố lượt chạy để hai lượt diễn tập đồng thời
# không `migrate:fresh`/`DROP` CSDL của nhau. Sống trên CÙNG container MariaDB dùng chung, tạo mới
# ở bước 2 và xoá hẳn ở bước dọn dẹp.
SOURCE_DB_NAME="vk_crm_lane_m8_drill_${TS}"
RESTORE_DB_CONTAINER="vkcrm-lane-m8-restore-${TS}"
RESTORE_DB_NAME='vkcrm_restore_drill'
DRILL_ID_NUMBER='099999888777'

RUN_DIR="${LANE_DIR}/storage/app/_drill/run-${TS}"
WORK_DIR="${RUN_DIR}/work"
WORK_DIR_WIN="$(cygpath -w "${WORK_DIR}")"
SOURCE_APP_DIR="${RUN_DIR}/source-app"
SOURCE_APP_DIR_WIN="$(cygpath -w "${SOURCE_APP_DIR}")"
CLEAN_APP_DIR="${RUN_DIR}/clean-app"
CLEAN_APP_DIR_WIN="$(cygpath -w "${CLEAN_APP_DIR}")"

ARCHIVE_HOST_PATH=""
ARCHIVE_REL_PATH=""
WRONG_KEY_EXIT=0
WRONG_KEY_OUTPUT=""

# --------------------------------------------------------------------------------------------
# Bí mật DÙNG MỘT LẦN — sinh mới mỗi lần chạy, không đọc .env thật, không bao giờ echo nguyên văn
# --------------------------------------------------------------------------------------------
APP_KEY_OLD="base64:$(openssl rand -base64 32)"
APP_KEY_NEW="base64:$(openssl rand -base64 32)"
BACKUP_PASSWORD="drill-$(openssl rand -hex 16)"

# --------------------------------------------------------------------------------------------
# Đo thời gian
# --------------------------------------------------------------------------------------------
declare -a STEP_NAMES=()
declare -a STEP_MS=()

now_ms() { date +%s%3N; }

step() {
  local name="$1"; shift
  local t0 t1
  echo
  echo "== ${name} =="
  t0="$(now_ms)"
  "$@"
  t1="$(now_ms)"
  STEP_NAMES+=("${name}")
  STEP_MS+=("$(( t1 - t0 ))")
  echo "-- ${name}: $(( t1 - t0 )) ms"
}

print_summary() {
  echo
  echo "== TÓM TẮT THỜI GIAN (số đo thật, lần chạy $(date -Iseconds)) =="
  local total=0
  local i
  for i in "${!STEP_NAMES[@]}"; do
    printf '%-70s %8d ms\n' "${STEP_NAMES[$i]}" "${STEP_MS[$i]}"
    total=$(( total + STEP_MS[i] ))
  done
  printf '%-70s %8d ms\n' "TỔNG" "${total}"
}

# --------------------------------------------------------------------------------------------
# Dọn dẹp — chạy dù script thoát giữa chừng
# --------------------------------------------------------------------------------------------
cleanup() {
  local status=$?
  set +e
  echo
  echo "== dọn dẹp =="
  docker rm -f "${RESTORE_DB_CONTAINER}" >/dev/null 2>&1
  # CSDL nguồn RIÊNG của lượt chạy này — xem hằng số SOURCE_DB_NAME. An toàn khi gọi dù bước 2
  # chưa từng chạy: DROP ... IF EXISTS.
  docker exec "${SOURCE_DB_CONTAINER}" mariadb -uroot -ppassword \
    -e "DROP DATABASE IF EXISTS \`${SOURCE_DB_NAME}\`;" >/dev/null 2>&1
  # Cả hai bản sao (kể cả archive của lượt thử, nằm trong bản sao nguồn) và thư mục làm việc.
  rm -rf "${RUN_DIR}"
  rmdir "${LANE_DIR}/storage/app/_drill" 2>/dev/null || true
  echo "Đã xoá container ${RESTORE_DB_CONTAINER}, CSDL ${SOURCE_DB_NAME}, hai bản sao mã nguồn, archive và thư mục tạm của lượt thử này."
  exit "${status}"
}
# Đăng ký trap TRƯỚC khi tạo bất kỳ tài nguyên tạm nào (thư mục, CSDL, container) — một lỗi xảy ra
# NGAY SAU một bước tạo tài nguyên nhưng TRƯỚC khi trap tồn tại sẽ để tài nguyên đó rò rỉ mãi mãi,
# vì không có gì đứng ra dọn nó khi script thoát.
trap cleanup EXIT

mkdir -p "${WORK_DIR}"

# --------------------------------------------------------------------------------------------
# Bản sao mã nguồn: mọi thứ TRỪ dữ liệu, bí mật và cache của worktree. Dùng lại `vendor` đã cài
# (không composer install lại) — "sạch" ở đây là KHÔNG hồ sơ khách, KHÔNG archive, KHÔNG `.env`,
# KHÔNG cache cấu hình; không phải "không có PHP dependency nào".
# --------------------------------------------------------------------------------------------
copy_app_tree() {
  local dest="$1" dest_win="$2"

  mkdir -p "${dest}"

  set +e
  robocopy "${LANE_DIR_WIN}" "${dest_win}" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 \
    /XF ".env" \
    /XD "${LANE_DIR_WIN}\storage\app\private" \
        "${LANE_DIR_WIN}\storage\app\backups" \
        "${LANE_DIR_WIN}\storage\app\backup-temp" \
        "${LANE_DIR_WIN}\storage\app\_drill" \
        "${LANE_DIR_WIN}\storage\framework\testing" \
        "${LANE_DIR_WIN}\storage\logs" \
        "${LANE_DIR_WIN}\bootstrap\cache" \
        "${LANE_DIR_WIN}\.git" \
        "${LANE_DIR_WIN}\node_modules" \
    >/dev/null
  local rc=$?
  set -e
  # Robocopy: 0-7 là các mã THÀNH CÔNG (kèm chi tiết vô hại, ví dụ "có tệp mới"); chỉ >=8 là lỗi
  # thật. Xem tài liệu Microsoft "Robocopy exit codes".
  if [ "${rc}" -ge 8 ]; then
    echo "robocopy thất bại, mã ${rc}" >&2
    exit 1
  fi

  mkdir -p "${dest}/storage/app/private" "${dest}/storage/app/backups" "${dest}/storage/logs" "${dest}/bootstrap/cache"

  # M1: không bao giờ chạy Laravel trên một bản sao còn cache cấu hình (xem đầu tệp).
  if [ -e "${dest}/bootstrap/cache/config.php" ]; then
    echo "Bản sao ${dest} có bootstrap/cache/config.php — Laravel sẽ bỏ qua mọi biến -e của docker run. Dừng." >&2
    exit 1
  fi

  # KHÔNG chép .env thật (loại trừ ở trên) — dùng .env.example (bí mật rỗng/mẫu) làm nền, mọi
  # biến cần cho lượt diễn tập (APP_KEY, DB_*, ...) truyền qua `-e` của `docker run`. APP_KEY THẬT
  # của máy dev không bao giờ nằm trong bản sao tạm, dù chỉ trong lúc script đang chạy.
  cp "${LANE_DIR}/.env.example" "${dest}/.env"
}

prepare_source_app() {
  copy_app_tree "${SOURCE_APP_DIR}" "${SOURCE_APP_DIR_WIN}"
}

# --------------------------------------------------------------------------------------------
# Bước 2 — Xây CSDL nguồn RIÊNG bằng APP_KEY dùng một lần, trong bản sao nguồn
# --------------------------------------------------------------------------------------------
seed_source_db() {
  docker exec "${SOURCE_DB_CONTAINER}" mariadb -uroot -ppassword \
    -e "CREATE DATABASE IF NOT EXISTS \`${SOURCE_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${SOURCE_DB_NAME}\`.* TO 'sail'@'%';"

  docker run --rm -i --network "${NETWORK}" \
    -v "${SOURCE_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306 \
    -e DB_DATABASE="${SOURCE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    "${IMAGE}" php artisan migrate:fresh --seed --force
}

# --------------------------------------------------------------------------------------------
# Bước 3 — Dữ liệu thử: một khách hàng (id_number) + một tài liệu (tệp thật)
# --------------------------------------------------------------------------------------------
write_fixture_script() {
  cat > "${WORK_DIR}/fixture.php" <<'PHP'
<?php

/*
 * Dữ liệu thử cho lượt khôi phục — KHÔNG phải mã sản phẩm, sinh ra tại chỗ bởi
 * tools/backup/restore-drill.sh, không commit. Tạo đúng hai thứ Task 3 đòi: một khách hàng có
 * `id_number` qua `Client::create()` (tôn trọng cast `encrypted` — không `DB::insert()` tay), và
 * một tài liệu có tệp thật trong storage/app/private qua `UploadStaffDocument` (Action có sẵn —
 * cùng đường tệp thật mà `database/seeders/MatterSeeder.php` dùng cho dữ liệu mẫu).
 */

use App\Actions\Document\UploadStaffDocument;
use App\Enums\ClientType;
use App\Enums\DocumentGroup;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Support\Facades\DB;

$idNumber = getenv('DRILL_ID_NUMBER') ?: '099999888777';

$client = Client::create([
    'type' => ClientType::Individual,
    'name' => 'Khách hàng khôi phục thử — M8a Task 3',
    'id_number' => $idNumber,
    'phone' => '0999888777',
    'address' => 'Địa chỉ dùng riêng cho đợt khôi phục thử, không phải khách hàng thật',
]);

$matter = Matter::query()->findOrFail(1);
$lead = User::query()->findOrFail($matter->lead_lawyer_id);

$document = app(UploadStaffDocument::class)->handle(
    matter: $matter,
    actor: $lead,
    file: DemoPdf::upload('khoi-phuc-thu-nghiem-m8a.pdf', 'Tep thu khoi phuc M8a Task 3', $matter->code),
    group: DocumentGroup::Internal,
    title: 'Tài liệu khôi phục thử — M8a Task 3',
);

$media = $document->getFirstMedia('file');

if ($media === null) {
    throw new RuntimeException('Tài liệu thử không gắn được tệp — kiểm lại UploadStaffDocument.');
}

$absolutePath = $media->getPath();

$result = [
    'client_id' => $client->getKey(),
    'id_number' => $idNumber,
    'document_id' => $document->getKey(),
    'media_id' => $media->getKey(),
    // Đường TƯƠNG ĐỐI tính từ disk 'private' (storage_path('app/private')) — dùng để tìm lại
    // đúng tệp trên bản khôi phục, nơi base_path() khác máy đã tạo archive.
    'media_relative_path' => ltrim(str_replace(storage_path('app/private'), '', $absolutePath), DIRECTORY_SEPARATOR.'/'),
    'sha256' => hash_file('sha256', $absolutePath),
    'row_counts' => [
        'clients' => DB::table('clients')->count(),
        'matters' => DB::table('matters')->count(),
        'documents' => DB::table('documents')->count(),
        'users' => DB::table('users')->count(),
        'matter_parties' => DB::table('matter_parties')->count(),
        'media' => DB::table('media')->count(),
        'migrations' => DB::table('migrations')->count(),
    ],
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

fwrite(STDOUT, "___FIXTURE_JSON_START___\n{$json}\n___FIXTURE_JSON_END___\n");
PHP
}

create_fixture() {
  write_fixture_script

  docker run --rm -i --network "${NETWORK}" \
    -v "${SOURCE_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -v "${WORK_DIR_WIN}:/drill" \
    -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306 \
    -e DB_DATABASE="${SOURCE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    -e DRILL_ID_NUMBER="${DRILL_ID_NUMBER}" \
    "${IMAGE}" php artisan tinker --execute="require '/drill/fixture.php';" \
    | tee "${WORK_DIR}/fixture-stdout.txt"

  sed -n '/___FIXTURE_JSON_START___/,/___FIXTURE_JSON_END___/p' "${WORK_DIR}/fixture-stdout.txt" \
    | sed '1d;$d' > "${WORK_DIR}/fixture-result.json"

  if [ ! -s "${WORK_DIR}/fixture-result.json" ]; then
    echo "Không trích được JSON dữ liệu thử — xem ${WORK_DIR}/fixture-stdout.txt" >&2
    exit 1
  fi

  echo "Dữ liệu thử:"
  cat "${WORK_DIR}/fixture-result.json"
}

# --------------------------------------------------------------------------------------------
# Bước 4 — backup:run thật (dump CSDL + tệp, mã hoá), ra disk local_backups CỦA BẢN SAO NGUỒN
# --------------------------------------------------------------------------------------------
run_backup() {
  docker run --rm -i --network "${NETWORK}" \
    -v "${SOURCE_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306 \
    -e DB_DATABASE="${SOURCE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    -e BACKUP_ARCHIVE_PASSWORD="${BACKUP_PASSWORD}" \
    -e BACKUP_DISKS=local_backups \
    "${IMAGE}" sh -c 'apk add --no-cache -q mariadb-client >/dev/null && php artisan backup:run --disable-notifications'
}

locate_archive() {
  # Bản sao nguồn vừa dựng có storage/app/backups RỖNG, nên thấy đúng MỘT archive — của lượt này.
  ARCHIVE_HOST_PATH="$(find "${SOURCE_APP_DIR}/storage/app/backups" -type f -name '*.zip' | sort | tail -n1)"

  if [ -z "${ARCHIVE_HOST_PATH}" ]; then
    echo "Không tìm thấy archive sau backup:run" >&2
    exit 1
  fi

  ARCHIVE_REL_PATH="${ARCHIVE_HOST_PATH#"${SOURCE_APP_DIR}"/}"
  echo "Archive: ${ARCHIVE_REL_PATH} ($(du -h "${ARCHIVE_HOST_PATH}" | cut -f1))"
}

# --------------------------------------------------------------------------------------------
# Bước 6a — Bản sao mã nguồn SẠCH cho bản khôi phục (không có storage/app/private)
# --------------------------------------------------------------------------------------------
prepare_clean_app() {
  copy_app_tree "${CLEAN_APP_DIR}" "${CLEAN_APP_DIR_WIN}"
}

# --------------------------------------------------------------------------------------------
# Bước 6b — MariaDB SẠCH tạm thời, không map cổng ra host
# --------------------------------------------------------------------------------------------
start_restore_db() {
  docker run -d --name "${RESTORE_DB_CONTAINER}" --network "${NETWORK}" \
    -e MARIADB_ROOT_PASSWORD=password \
    -e MARIADB_DATABASE="${RESTORE_DB_NAME}" \
    -e MARIADB_USER=sail -e MARIADB_PASSWORD=password \
    mariadb:11 >/dev/null

  echo -n "Chờ MariaDB sạch sẵn sàng"
  local i
  for i in $(seq 1 60); do
    if docker exec "${RESTORE_DB_CONTAINER}" mariadb -uroot -ppassword -e "SELECT 1" >/dev/null 2>&1; then
      echo " OK"
      return 0
    fi
    printf '.'
    sleep 1
  done
  echo " thất bại — MariaDB sạch không sẵn sàng sau 60s"
  return 1
}

# --------------------------------------------------------------------------------------------
# Bước 7 — giải nén bằng mật khẩu (PHP ZipArchive — `unzip` của Alpine không mở được AES)
# --------------------------------------------------------------------------------------------
write_extract_script() {
  cat > "${WORK_DIR}/extract.php" <<'PHP'
<?php

$password = getenv('BACKUP_ARCHIVE_PASSWORD');
$archive = getenv('ARCHIVE_PATH');
$dest = '/drill/extracted';

@mkdir($dest, 0777, true);

$zip = new ZipArchive;
$open = $zip->open($archive);

if ($open !== true) {
    fwrite(STDERR, "Không mở được archive, mã lỗi ZipArchive: {$open}\n");
    exit(1);
}

$zip->setPassword($password);
$count = $zip->numFiles;

if (! $zip->extractTo($dest)) {
    fwrite(STDERR, "Giải nén thất bại — kiểm lại mật khẩu (đúng mật khẩu nhưng sai chỗ mã hoá cũng cho lỗi này).\n");
    exit(1);
}

$zip->close();

fwrite(STDOUT, "OK: giải nén {$count} mục.\n");
PHP
}

extract_archive() {
  write_extract_script

  docker run --rm -i \
    -v "${SOURCE_APP_DIR_WIN}:/host:ro" \
    -v "${WORK_DIR_WIN}:/drill" \
    -e BACKUP_ARCHIVE_PASSWORD="${BACKUP_PASSWORD}" \
    -e ARCHIVE_PATH="/host/${ARCHIVE_REL_PATH}" \
    "${IMAGE}" php /drill/extract.php
}

# --------------------------------------------------------------------------------------------
# Bước 8 — nạp dump vào MariaDB sạch
# --------------------------------------------------------------------------------------------
load_dump() {
  local dumpfile
  dumpfile="$(find "${WORK_DIR}/extracted/db-dumps" -type f -name '*.sql' 2>/dev/null | head -n1)"

  if [ -z "${dumpfile}" ]; then
    echo "Không tìm thấy bản dump CSDL trong archive đã giải nén" >&2
    exit 1
  fi

  echo "Bản dump: $(basename "${dumpfile}") ($(du -h "${dumpfile}" | cut -f1))"

  # Nạp bằng client `mariadb` (không phải `mysql` cũ) — bản dump của `mariadb-dump` mới mở đầu
  # bằng dòng `/*M!999999\- enable the sandbox mode */` mà client MySQL/mariadb cũ không đọc
  # được (docs/research/2026-09-26-sao-luu.md, mục 3). Container khôi phục dùng đúng client
  # `mariadb` (cùng gói `mariadb-client` vừa cài ở bước backup:run), nên dòng đó không phải vấn
  # đề — không cần cắt bỏ.
  docker exec -i "${RESTORE_DB_CONTAINER}" mariadb -uroot -ppassword "${RESTORE_DB_NAME}" < "${dumpfile}"
}

# --------------------------------------------------------------------------------------------
# Bước 9 — chép tệp hồ sơ về đúng chỗ trên bản sao sạch
# --------------------------------------------------------------------------------------------
copy_private_files() {
  mkdir -p "${CLEAN_APP_DIR}/storage/app/private"

  # `relative_path` của config/backup.php (M8a Task 3) là `base_path()`, nên mục trong archive
  # là `storage/app/private/...` — TƯƠNG ĐỐI, không phụ thuộc base_path() tuyệt đối của container
  # đã tạo archive. Xem docblock ở config/backup.php.
  if [ -d "${WORK_DIR}/extracted/storage/app/private" ]; then
    cp -r "${WORK_DIR}/extracted/storage/app/private/." "${CLEAN_APP_DIR}/storage/app/private/"
  else
    echo "CẢNH BÁO: không thấy storage/app/private trong archive đã giải nén — kiểm relative_path" >&2
    exit 1
  fi

  echo "Đã chép $(find "${CLEAN_APP_DIR}/storage/app/private" -type f | wc -l) tệp về storage/app/private của bản sao sạch."
}

# --------------------------------------------------------------------------------------------
# Bước 10 — migrate:status trên bản khôi phục (không có migration đang chờ)
# --------------------------------------------------------------------------------------------
migrate_status() {
  docker run --rm -i --network "${NETWORK}" \
    -v "${CLEAN_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST="${RESTORE_DB_CONTAINER}" -e DB_PORT=3306 \
    -e DB_DATABASE="${RESTORE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    "${IMAGE}" php artisan migrate:status
}

# --------------------------------------------------------------------------------------------
# Bước 11 — giải mã id_number + so checksum tệp + đếm dòng các bảng chính (APP_KEY CŨ, đúng)
# --------------------------------------------------------------------------------------------
write_verify_script() {
  cat > "${WORK_DIR}/verify.php" <<'PHP'
<?php

use Illuminate\Support\Facades\DB;

$fixture = json_decode(file_get_contents(getenv('FIXTURE_JSON_PATH')), true, flags: JSON_THROW_ON_ERROR);

$client = \App\Models\Client::query()->find($fixture['client_id']);

if ($client === null) {
    fwrite(STDERR, "Không tìm thấy khách hàng #{$fixture['client_id']} sau khi khôi phục.\n");
    exit(1);
}

// Đọc `$client->id_number` GIẢI MÃ ngay tại đây (cast `encrypted`) — với APP_KEY sai, dòng này
// tự ném `Illuminate\Contracts\Encryption\DecryptException` trước khi tới được so sánh bên dưới,
// và tinker (App\Console chạy PsySH) in nguyên văn thông điệp lỗi đó rồi thoát mã 1.
$decrypted = $client->id_number;
$idNumberMatches = hash_equals($fixture['id_number'], (string) $decrypted);

$mediaAbsolutePath = storage_path('app/private/'.$fixture['media_relative_path']);
$fileExists = is_file($mediaAbsolutePath);
$sha256 = $fileExists ? hash_file('sha256', $mediaAbsolutePath) : null;
$checksumMatches = $sha256 !== null && hash_equals($fixture['sha256'], $sha256);

$rowCountsMatch = true;
$rowCountsNow = [];

foreach ($fixture['row_counts'] as $table => $expected) {
    $actual = DB::table($table)->count();
    $rowCountsNow[$table] = $actual;

    if ($actual !== $expected) {
        $rowCountsMatch = false;
    }
}

$result = [
    'id_number_decrypted' => $decrypted,
    'id_number_matches_original' => $idNumberMatches,
    'media_file_exists' => $fileExists,
    'checksum_matches_original' => $checksumMatches,
    'row_counts_match_original' => $rowCountsMatch,
    'row_counts_now' => $rowCountsNow,
    'row_counts_original' => $fixture['row_counts'],
];

fwrite(STDOUT, "___VERIFY_JSON_START___\n".json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n___VERIFY_JSON_END___\n");

if (! $idNumberMatches || ! $checksumMatches || ! $rowCountsMatch) {
    exit(1);
}
PHP
}

verify_restore() {
  write_verify_script

  docker run --rm -i --network "${NETWORK}" \
    -v "${CLEAN_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -v "${WORK_DIR_WIN}:/drill" \
    -e DB_CONNECTION=mariadb -e DB_HOST="${RESTORE_DB_CONTAINER}" -e DB_PORT=3306 \
    -e DB_DATABASE="${RESTORE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    -e FIXTURE_JSON_PATH=/drill/fixture-result.json \
    "${IMAGE}" php artisan tinker --execute="require '/drill/verify.php';"
}

# --------------------------------------------------------------------------------------------
# Bước 12b — kho tài liệu (M14 Task 7, kế hoạch R10): sau khi khôi phục CSDL, kiểm một mẫu 20 media
# so với kho (`vkcrm:storage:verify --sample=20`, md5 + cỡ của tệp trên kho so với dòng media). Lượt
# diễn tập trên máy dev không có khoá Google nào (`DOCUMENT_STORAGE=local`) và dữ liệu thử của nó
# không có media nào trên kho, nên ở đây bước này chỉ chứng minh lệnh chạy được trên CSDL vừa khôi
# phục; trên máy chủ thật (docs/SAO-LUU-KHOI-PHUC.md, "Quy trình cho máy chủ thật") nó hỏi kho thật.
# Lệnh thuộc Task 6 của M14: bản mã chưa có lệnh đó thì bước này in "bỏ qua" thay vì làm hỏng cả
# lượt diễn tập.
# --------------------------------------------------------------------------------------------
verify_document_store() {
  local commands

  commands="$(docker run --rm -i --network "${NETWORK}" \
    -v "${CLEAN_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST="${RESTORE_DB_CONTAINER}" -e DB_PORT=3306 \
    -e DB_DATABASE="${RESTORE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    "${IMAGE}" php artisan list --raw)"

  if ! grep -q '^vkcrm:storage:verify ' <<<"${commands}"; then
    echo "Bỏ qua: bản mã này chưa có lệnh vkcrm:storage:verify (M14 Task 6)."
    return 0
  fi

  docker run --rm -i --network "${NETWORK}" \
    -v "${CLEAN_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST="${RESTORE_DB_CONTAINER}" -e DB_PORT=3306 \
    -e DB_DATABASE="${RESTORE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" -e DOCUMENT_STORAGE=local \
    "${IMAGE}" php artisan vkcrm:storage:verify --sample=20
}

# --------------------------------------------------------------------------------------------
# Bước 12 — CHỨNG MINH thất bại với một APP_KEY MỚI (R3: "APP_KEY là một nửa của bản sao lưu")
# --------------------------------------------------------------------------------------------
verify_wrong_key() {
  set +e
  WRONG_KEY_OUTPUT="$(docker run --rm -i --network "${NETWORK}" \
    -v "${CLEAN_APP_DIR_WIN}:/var/www/html" -w /var/www/html \
    -v "${WORK_DIR_WIN}:/drill" \
    -e DB_CONNECTION=mariadb -e DB_HOST="${RESTORE_DB_CONTAINER}" -e DB_PORT=3306 \
    -e DB_DATABASE="${RESTORE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_NEW}" \
    -e FIXTURE_JSON_PATH=/drill/fixture-result.json \
    "${IMAGE}" php artisan tinker --execute="require '/drill/verify.php';" 2>&1)"
  WRONG_KEY_EXIT=$?
  set -e

  echo "${WRONG_KEY_OUTPUT}"

  if [ "${WRONG_KEY_EXIT}" -eq 0 ]; then
    echo "CẢNH BÁO NGHIÊM TRỌNG: giải mã THÀNH CÔNG với một APP_KEY khác — điều này không được xảy ra." >&2
    return 1
  fi

  echo "Đúng như dự đoán: APP_KEY mới KHÔNG giải mã được (thoát mã ${WRONG_KEY_EXIT})."
}

# --------------------------------------------------------------------------------------------
# main
# --------------------------------------------------------------------------------------------
main() {
  echo "Khôi phục thử M8a Task 3 (R3) — bắt đầu $(date -Iseconds)"
  echo "Worktree: ${LANE_DIR} (chỉ đọc mã nguồn; mọi thứ của lượt chạy nằm dưới ${RUN_DIR})"
  echo "CSDL nguồn riêng: ${SOURCE_DB_NAME} (không đụng vk_crm_lane_m8)"
  echo "Container MariaDB sạch: ${RESTORE_DB_CONTAINER}"

  step "1. Dựng bản sao mã nguồn NGUỒN (không dữ liệu, không .env, không cache của worktree)" prepare_source_app
  step "2. Xây CSDL nguồn riêng (migrate:fresh --seed, APP_KEY dùng một lần)" seed_source_db
  step "3. Tạo dữ liệu thử (khách hàng có id_number + tài liệu có tệp thật)" create_fixture
  step "4. backup:run thật (dump CSDL + tệp, mã hoá AES-256)" run_backup
  step "5. Định vị archive vừa tạo" locate_archive
  step "6. Dựng bản sao mã nguồn SẠCH cho bản khôi phục (không storage/app/private)" prepare_clean_app
  step "7. Dựng MariaDB SẠCH tạm thời (không map cổng ra host)" start_restore_db
  step "8. Giải nén archive bằng mật khẩu (PHP ZipArchive)" extract_archive
  step "9. Nạp bản dump vào MariaDB sạch" load_dump
  step "10. Chép tệp hồ sơ về storage/app/private của bản sao sạch" copy_private_files
  step "11. migrate:status trên bản khôi phục (không migration nào đang chờ)" migrate_status
  step "12. Giải mã id_number + so checksum tệp + đếm dòng bảng chính (APP_KEY CŨ, đúng)" verify_restore
  step "12b. Kiểm mẫu 20 media với kho tài liệu (vkcrm:storage:verify --sample=20, M14)" verify_document_store
  step "13. Chứng minh thất bại với APP_KEY MỚI (R3)" verify_wrong_key

  print_summary

  echo
  echo "KẾT LUẬN: khôi phục thành công với đúng APP_KEY cũ; thất bại rõ ràng với APP_KEY khác."
  echo "Bản sao lưu (đĩa local_backups, mã hoá AES-256) là một bản sao lưu THẬT theo nghĩa R3."
}

main "$@"
