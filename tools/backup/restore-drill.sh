#!/usr/bin/env bash
#
# tools/backup/restore-drill.sh — M8a Task 3 (SPEC §10 mục 8, R3: "Sao lưu chưa khôi phục thử
# thì chưa phải sao lưu"). Một lượt khôi phục THẬT, có số đo, chạy từ Git Bash trên máy dev
# (worktree D:\vkwt\lane-m8). KHÔNG chạy trên máy chủ thật — quy trình cho máy chủ thật (kèm nơi
# cất APP_KEY/BACKUP_ARCHIVE_PASSWORD) nằm ở docs/SAO-LUU-KHOI-PHUC.md, mục "Khôi phục thử".
#
# Thứ tự đúng như Task 3 đòi:
#   1. Xây một CSDL NGUỒN RIÊNG của kịch bản này (`vk_crm_lane_m8_drill`, KHÔNG PHẢI
#      `vk_crm_lane_m8` — đĩa serve `:8090` và các task khác của làn đọc/ghi CSDL đó ĐANG lúc kịch
#      bản này có thể chạy; `migrate:fresh` xoá sạch bảng, nên đụng nhầm vào nó phá luôn việc của
#      người khác) bằng một APP_KEY DÙNG MỘT LẦN — không phải APP_KEY thật của `.env` trên máy dev.
#      Lý do cần khoá dùng một lần: bước "đặt APP_KEY cũ" ở dưới chỉ chứng minh được điều nó phải
#      chứng minh (khôi phục cần ĐÚNG khoá, không phải BẤT KỲ khoá nào) nếu khoá đó không lẫn với
#      khoá thật của máy đang chạy kịch bản.
#   2. Tạo thêm một khách hàng có `id_number` (qua `Client::create()`, tôn trọng cast `encrypted`)
#      và một tài liệu có tệp thật (qua `UploadStaffDocument`, Action có sẵn — cùng đường tệp thật
#      mà `MatterSeeder` dùng).
#   3. `backup:run` thật: dump CSDL bằng `mariadb-dump` (cài trong container tạm, không sửa
#      image), nén cùng tệp hồ sơ, mã hoá AES-256, ghi ra disk `local_backups`.
#   4. Dựng một MariaDB SẠCH (container mới, không map cổng ra host) và một BẢN SAO mã nguồn
#      KHÔNG có `storage/app/private` — không ghi đè trực tiếp vào máy dev đang chạy.
#   5. Giải nén archive bằng mật khẩu, nạp dump vào MariaDB sạch, chép tệp về đúng chỗ,
#      `migrate:status`, rồi giải mã `id_number` + so checksum tệp + đếm dòng các bảng chính.
#   6. Chạy lại đúng bước giải mã đó với một APP_KEY MỚI để chứng minh nó thất bại — APP_KEY là
#      một nửa của bản sao lưu (R3).
#   7. Dọn container, CSDL nguồn riêng, thư mục tạm — không để lại archive thật, tệp tạm, hay CSDL
#      nào trong repo hay trên MariaDB dùng chung.
#
# Chạy lại được: mỗi lần chạy tự sinh một hậu tố thời gian cho tên container/CSDL/thư mục tạm, và
# một `trap ... EXIT` dọn dẹp dù script thoát giữa chừng (lỗi, Ctrl-C, `set -e`).
#
# KHÔNG BAO GIỜ đụng tới `vk_crm_lane_m8` (CSDL phục vụ bản chạy `:8090` của làn — các task khác
# có thể đang seed/đọc/ghi nó CÙNG LÚC kịch bản này chạy) hay bất kỳ CSDL nào khác ngoài
# `vk_crm_lane_m8_drill` mà chính kịch bản này tạo và xoá.
#
# Yêu cầu trên máy dev: Docker Desktop; container `crmkhachhang-mariadb-1` đang chạy trên mạng
# `crmkhachhang_vkcrm` (kịch bản tự tạo CSDL nguồn riêng `vk_crm_lane_m8_drill` trên CHÍNH container
# đó ở bước 1 — không cần chuẩn bị gì trước); Git Bash (MSYS) trên Windows với `openssl`,
# `cygpath`, `robocopy` sẵn có (đều là công cụ có sẵn của Git for Windows / Windows).
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

TS="$(date +%Y%m%d%H%M%S)"
IMAGE='webdevops/php:8.3-alpine'
NETWORK='crmkhachhang_vkcrm'
SOURCE_DB_CONTAINER='crmkhachhang-mariadb-1'
# CSDL NGUỒN RIÊNG của kịch bản này — KHÔNG PHẢI `vk_crm_lane_m8` (đĩa serve `:8090` của làn, các
# task khác có thể đang dùng đúng lúc này). Sống trên CÙNG container MariaDB dùng chung
# (`crmkhachhang-mariadb-1`, đỡ phải dựng thêm một container MariaDB thứ hai chỉ để seed), nhưng là
# một CSDL riêng, tạo mới ở bước 1 và xoá hẳn ở bước dọn dẹp — không migration, không seed, không
# dữ liệu thử nào của kịch bản này từng chạm vào `vk_crm_lane_m8`.
SOURCE_DB_NAME='vk_crm_lane_m8_drill'
RESTORE_DB_CONTAINER="vkcrm-lane-m8-restore-${TS}"
RESTORE_DB_NAME='vkcrm_restore_drill'
DRILL_ID_NUMBER='099999888777'

WORK_DIR="${LANE_DIR}/storage/app/_drill/work-${TS}"
WORK_DIR_WIN="$(cygpath -w "${WORK_DIR}")"
CLEAN_APP_DIR="${LANE_DIR}/storage/app/_drill/clean-app-${TS}"
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
  # CSDL nguồn RIÊNG của kịch bản này — không phải vk_crm_lane_m8, xem hằng số SOURCE_DB_NAME.
  # An toàn khi gọi dù bước 1 chưa từng chạy (CREATE DATABASE chưa xảy ra): DROP ... IF EXISTS.
  docker exec "${SOURCE_DB_CONTAINER}" mariadb -uroot -ppassword \
    -e "DROP DATABASE IF EXISTS \`${SOURCE_DB_NAME}\`;" >/dev/null 2>&1
  rm -rf "${WORK_DIR}" "${CLEAN_APP_DIR}"
  # Archive của lượt drill KHÔNG phải một bản sao lưu thật cần giữ lại — xoá để
  # storage/app/backups không tích rác qua nhiều lần chạy thử (thư mục vốn đã .gitignore, dọn
  # vẫn đúng: một agent chạy script này nhiều lần không nên để lại hàng chục archive thử).
  if [ -n "${ARCHIVE_HOST_PATH}" ] && [ -f "${ARCHIVE_HOST_PATH}" ]; then
    rm -f "${ARCHIVE_HOST_PATH}"
    rmdir "$(dirname "${ARCHIVE_HOST_PATH}")" 2>/dev/null || true
  fi
  echo "Đã xoá container ${RESTORE_DB_CONTAINER}, CSDL ${SOURCE_DB_NAME}, thư mục tạm, và archive của lượt thử này."
  exit "${status}"
}
# Đăng ký trap TRƯỚC khi tạo bất kỳ tài nguyên tạm nào (thư mục, CSDL, container) — một lỗi xảy ra
# NGAY SAU một bước tạo tài nguyên nhưng TRƯỚC khi trap tồn tại sẽ để tài nguyên đó rò rỉ mãi mãi,
# vì không có gì đứng ra dọn nó khi script thoát.
trap cleanup EXIT

mkdir -p "${WORK_DIR}"

# --------------------------------------------------------------------------------------------
# Bước 1 — Xây CSDL nguồn RIÊNG (vk_crm_lane_m8_drill) bằng APP_KEY dùng một lần
# --------------------------------------------------------------------------------------------
seed_source_db() {
  docker exec "${SOURCE_DB_CONTAINER}" mariadb -uroot -ppassword \
    -e "CREATE DATABASE IF NOT EXISTS \`${SOURCE_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${SOURCE_DB_NAME}\`.* TO 'sail'@'%';"

  docker run --rm -i --network "${NETWORK}" \
    -v "${LANE_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306 \
    -e DB_DATABASE="${SOURCE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    "${IMAGE}" php artisan migrate:fresh --seed --force
}

# --------------------------------------------------------------------------------------------
# Bước 2 — Dữ liệu thử: một khách hàng (id_number) + một tài liệu (tệp thật)
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
    -v "${LANE_DIR_WIN}:/var/www/html" -w /var/www/html \
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
# Bước 3 — backup:run thật (dump CSDL + tệp, mã hoá), ra disk local_backups
# --------------------------------------------------------------------------------------------
run_backup() {
  # Thư mục của WORKTREE (disk local_backups trên đĩa, không phải một CSDL) — dọn trước mỗi lượt
  # chỉ để bước 4 (định vị archive) luôn thấy đúng MỘT archive, của LƯỢT NÀY.
  rm -rf "${LANE_DIR}/storage/app/backups"/* 2>/dev/null || true

  docker run --rm -i --network "${NETWORK}" \
    -v "${LANE_DIR_WIN}:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306 \
    -e DB_DATABASE="${SOURCE_DB_NAME}" -e DB_USERNAME=sail -e DB_PASSWORD=password \
    -e APP_KEY="${APP_KEY_OLD}" \
    -e BACKUP_ARCHIVE_PASSWORD="${BACKUP_PASSWORD}" \
    -e BACKUP_DISKS=local_backups \
    "${IMAGE}" sh -c 'apk add --no-cache -q mariadb-client >/dev/null && php artisan backup:run --disable-notifications'
}

locate_archive() {
  ARCHIVE_HOST_PATH="$(find "${LANE_DIR}/storage/app/backups" -type f -name '*.zip' | sort | tail -n1)"

  if [ -z "${ARCHIVE_HOST_PATH}" ]; then
    echo "Không tìm thấy archive sau backup:run" >&2
    exit 1
  fi

  ARCHIVE_REL_PATH="${ARCHIVE_HOST_PATH#"${LANE_DIR}"/}"
  echo "Archive: ${ARCHIVE_REL_PATH} ($(du -h "${ARCHIVE_HOST_PATH}" | cut -f1))"
}

# --------------------------------------------------------------------------------------------
# Bước 4a — Bản sao mã nguồn SẠCH: mọi thứ TRỪ storage/app/private (dùng lại vendor đã cài, để
# không phải composer install lại — cái "sạch" ở đây là KHÔNG hồ sơ khách, KHÔNG CSDL cũ, không
# phải "không có PHP dependency nào")
# --------------------------------------------------------------------------------------------
prepare_clean_app() {
  mkdir -p "${CLEAN_APP_DIR}"

  set +e
  robocopy "${LANE_DIR_WIN}" "${CLEAN_APP_DIR_WIN}" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 \
    /XF ".env" \
    /XD "${LANE_DIR_WIN}\storage\app\private" \
        "${LANE_DIR_WIN}\storage\app\backups" \
        "${LANE_DIR_WIN}\storage\app\_drill" \
        "${LANE_DIR_WIN}\.git" \
        "${LANE_DIR_WIN}\node_modules" \
    >/dev/null
  rc=$?
  set -e
  # Robocopy: 0-7 là các mã THÀNH CÔNG (kèm chi tiết vô hại, ví dụ "có tệp mới"); chỉ >=8 là lỗi
  # thật. Xem tài liệu Microsoft "Robocopy exit codes".
  if [ "${rc}" -ge 8 ]; then
    echo "robocopy thất bại, mã ${rc}" >&2
    exit 1
  fi

  mkdir -p "${CLEAN_APP_DIR}/storage/app/private"
  # KHÔNG chép .env thật (loại trừ ở trên) — dùng .env.example (bí mật rỗng/mẫu) làm nền, mọi
  # biến cần cho lượt khôi phục (APP_KEY, DB_*, ...) truyền qua `-e` của `docker run`, đè lên
  # đúng như README/`.env.example` đã ghi. Tức là APP_KEY THẬT của máy dev không bao giờ nằm
  # trong bản sao tạm này, dù chỉ trong lúc script đang chạy.
  cp "${LANE_DIR}/.env.example" "${CLEAN_APP_DIR}/.env"
}

# --------------------------------------------------------------------------------------------
# Bước 4b — MariaDB SẠCH tạm thời, không map cổng ra host
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
# Bước 5 — giải nén bằng mật khẩu (PHP ZipArchive — `unzip` của Alpine không mở được AES)
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
    -v "${LANE_DIR_WIN}:/host:ro" \
    -v "${WORK_DIR_WIN}:/drill" \
    -e BACKUP_ARCHIVE_PASSWORD="${BACKUP_PASSWORD}" \
    -e ARCHIVE_PATH="/host/${ARCHIVE_REL_PATH}" \
    "${IMAGE}" php /drill/extract.php
}

# --------------------------------------------------------------------------------------------
# Bước 6 — nạp dump vào MariaDB sạch
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
# Bước 7 — chép tệp hồ sơ về đúng chỗ trên bản sao sạch
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
# Bước 8 — migrate:status trên bản khôi phục (không có migration đang chờ)
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
# Bước 9 — giải mã id_number + so checksum tệp + đếm dòng các bảng chính (APP_KEY CŨ, đúng)
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
# Bước 10 — CHỨNG MINH thất bại với một APP_KEY MỚI (R3: "APP_KEY là một nửa của bản sao lưu")
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
  echo "Worktree: ${LANE_DIR}"
  echo "CSDL nguồn riêng: ${SOURCE_DB_NAME} (không đụng vk_crm_lane_m8)"
  echo "Container MariaDB sạch: ${RESTORE_DB_CONTAINER}"

  step "1. Xây CSDL nguồn riêng ${SOURCE_DB_NAME} (migrate:fresh --seed, APP_KEY dùng một lần)" seed_source_db
  step "2. Tạo dữ liệu thử (khách hàng có id_number + tài liệu có tệp thật)" create_fixture
  step "3. backup:run thật (dump CSDL + tệp, mã hoá AES-256)" run_backup
  step "4. Định vị archive vừa tạo" locate_archive
  step "5. Dựng bản sao mã nguồn SẠCH (không có storage/app/private)" prepare_clean_app
  step "6. Dựng MariaDB SẠCH tạm thời (không map cổng ra host)" start_restore_db
  step "7. Giải nén archive bằng mật khẩu (PHP ZipArchive)" extract_archive
  step "8. Nạp bản dump vào MariaDB sạch" load_dump
  step "9. Chép tệp hồ sơ về storage/app/private của bản sao sạch" copy_private_files
  step "10. migrate:status trên bản khôi phục (không migration nào đang chờ)" migrate_status
  step "11. Giải mã id_number + so checksum tệp + đếm dòng bảng chính (APP_KEY CŨ, đúng)" verify_restore
  step "12. Chứng minh thất bại với APP_KEY MỚI (R3)" verify_wrong_key

  print_summary

  echo
  echo "KẾT LUẬN: khôi phục thành công với đúng APP_KEY cũ; thất bại rõ ràng với APP_KEY khác."
  echo "Bản sao lưu (đĩa local_backups, mã hoá AES-256) là một bản sao lưu THẬT theo nghĩa R3."
}

main "$@"
