#!/usr/bin/env bash
#
# tools/backup/office-pull.sh — M14 Task 7 (kế hoạch R10; hướng dẫn cài ở Phụ lục D của
# docs/SAO-LUU-KHOI-PHUC.md). Chạy trên MÁY CHỦ VĂN PHÒNG, không chạy trên máy chủ web: kéo bản thứ
# hai của kho tài liệu (Shared Drive "Kho") về một remote `crypt` trên ổ của máy văn phòng, kiểm từng
# tệp, rồi gửi một biên nhận để CRM biết tệp nào đã có bản ngoài Google. Mô hình KÉO: chiếm được máy
# chủ web cũng không với tới bản ở văn phòng.
#
# Ba remote rclone, tạo bằng `rclone config` dưới tài khoản hệ điều hành riêng `vkcrm-saoluu`:
#   vkkho:     Google Drive, phạm vi drive.readonly, team_drive = Shared Drive kho, root_folder_id =
#              thư mục gốc của môi trường (đúng hai giá trị trong .env của máy chủ web).
#   vkbackups: Google Drive, Shared Drive "VK-CRM Backups" (vai Người đóng góp: gửi được biên nhận,
#              không cho vào thùng rác được).
#   vkoffice:  crypt trên thư mục cục bộ; tên và nội dung tệp đều mã hoá.
#
# Một lượt đêm (mặc định):
#   1. khoá chống chạy chồng bằng `mkdir` (có trên mọi nền), ghi PID; khoá của PID đã chết thì gỡ;
#   2. chép kho về crypt, bất biến (--immutable: tệp đã có mà bị đổi trên kho thì báo lỗi, không ghi đè
#      — đó là tín hiệu giả mạo, và nó đi tới CRM qua "errors" của biên nhận);
#   3. danh sách chưa có biên nhận = mọi tệp trên kho trừ receipted.txt (comm -23 trên danh sách đã sort);
#   4. cryptcheck --one-way đúng các tệp đó: so NỘI DUNG đã mã hoá với nguồn, từng tệp; tệp khớp vào
#      match.txt;
#   5. đọc md5 của Drive và cỡ của đúng các tệp khớp, dựng biên nhận JSON (format 1; kho.team_drive và
#      kho.root_folder_id đọc từ `rclone config show vkkho`, không gõ tay; started_at, finished_at,
#      errors, files) — chỉ tên mờ, md5 và cỡ, không dữ liệu khách;
#   6. gửi biên nhận lên vkbackups:<thư mục sao lưu>/office-receipts/<thư mục môi trường>/ (copyto,
#      --immutable); CHỈ SAU KHI gửi thành công mới nối các tệp đã ghi vào receipted.txt;
#   7. kéo các archive CSDL (đã mã hoá AES-256 trên máy chủ web) về thư mục archive, cũng bất biến.
# Mỗi đêm luôn gửi một biên nhận, kể cả khi không có tệp mới ("files": []): CRM dùng nó làm nhịp sống,
# để dòng `document_office_copy` không VÀNG vì biên nhận cũ.
#
# `--check-monthly`: cryptcheck TOÀN BỘ kho với bản ở văn phòng, ghi nhật ký; tệp lệch nội dung, và tệp
# đã có biên nhận mà không còn ở văn phòng, được cộng vào "errors" của biên nhận kế tiếp.
#
# CẤM tuyệt đối trong script này (cả lệnh lẫn cờ): rclone sync, move, delete, deletefile, purge, rmdir, rmdirs, cleanup, dedupe, --delete-*.
# Bản ở văn phòng chỉ được THÊM; huỷ tệp hết hạn lưu là việc tay của người giữ máy (sổ tay R15 trong
# docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md). Test cấu trúc tests/Feature/Storage/OfficeCopyStructureTest.php
# kiểm điều này, cùng --immutable ở mọi lượt chép, --one-way ở cryptcheck, và khoá chạy chồng.
#
# Cấu hình: tệp office-pull.conf cạnh script (mẫu: office-pull.conf.example), hoặc đường dẫn trong biến
# VKCRM_OFFICE_CONF. Mật khẩu của tệp cấu hình rclone KHÔNG nằm trong tệp đó: rclone hỏi nó qua
# RCLONE_PASSWORD_COMMAND (kho mật khẩu của hệ điều hành) — xem Phụ lục D.
#
# Nhật ký: office-pull.log cạnh script. Mã thoát: 0 khi không lỗi; 1 khi có lỗi (biên nhận vẫn được
# gửi nếu gửi được); 2 khi cấu hình sai; 75 khi một lượt khác đang chạy.

set -uo pipefail
export LC_ALL=C

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF_FILE="${VKCRM_OFFICE_CONF:-${SCRIPT_DIR}/office-pull.conf}"
LOG_FILE="${SCRIPT_DIR}/office-pull.log"

# Mặc định; office-pull.conf ghi đè.
RCLONE="rclone"
KHO_REMOTE="vkkho"
OFFICE_DEST="vkoffice:kho"
BACKUPS_REMOTE="vkbackups"
BACKUPS_FOLDER="VK-CRM-backups"
ENV_FOLDER=""
ARCHIVE_DIR=""
STATE_DIR="${SCRIPT_DIR}/office-pull-state"

if [ -f "${CONF_FILE}" ]; then
  # shellcheck source=/dev/null
  . "${CONF_FILE}"
fi

LOCK_DIR="${STATE_DIR}/office-pull.lock"
RECEIPTED="${STATE_DIR}/receipted.txt"
CARRY_FILE="${STATE_DIR}/carry-errors"
ERRORS=0

exec >>"${LOG_FILE}" 2>&1

log() {
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

count_lines() {
  if [ -s "$1" ]; then
    grep -c '' "$1"
  else
    echo 0
  fi
}

read_carry() {
  local value
  value="$(cat "${CARRY_FILE}" 2>/dev/null || true)"
  case "${value}" in
    '' | *[!0-9]*) echo 0 ;;
    *) echo "${value}" ;;
  esac
}

add_carry() {
  echo "$(( $(read_carry) + $1 ))" > "${CARRY_FILE}"
}

# ------------------------------------------------------------------------------------------------
# Khoá chống chạy chồng: hai lượt chép cùng lúc vào crypt sinh tệp trùng.
# ------------------------------------------------------------------------------------------------
acquire_lock() {
  local old_pid=""

  mkdir -p "${STATE_DIR}" || return 1

  if mkdir "${LOCK_DIR}" 2>/dev/null; then
    echo "$$" > "${LOCK_DIR}/pid"
    return 0
  fi

  old_pid="$(cat "${LOCK_DIR}/pid" 2>/dev/null || true)"

  if [ -n "${old_pid}" ] && kill -0 "${old_pid}" 2>/dev/null; then
    log "Lượt khác (PID ${old_pid}) đang chạy; thoát, không làm gì."
    return 1
  fi

  # Khoá chưa có PID: có thể một lượt khác vừa tạo khoá và chưa kịp ghi PID. Chỉ coi là khoá chết
  # khi nó đã cũ hơn 2 phút.
  if [ -z "${old_pid}" ] && [ -n "$(find "${LOCK_DIR}" -maxdepth 0 -mmin -2 2>/dev/null)" ]; then
    log "Khoá vừa được một lượt khác tạo; thoát, không làm gì."
    return 1
  fi

  log "Gỡ khoá của lượt đã chết (PID ${old_pid:-không rõ})."
  rm -rf "${LOCK_DIR}"

  if mkdir "${LOCK_DIR}" 2>/dev/null; then
    echo "$$" > "${LOCK_DIR}/pid"
    return 0
  fi

  log "Không lấy được khoá sau khi gỡ khoá cũ; thoát."
  return 1
}

release_lock() {
  rm -rf "${LOCK_DIR}"
}

# ------------------------------------------------------------------------------------------------
# Cấu hình của remote nguồn, đọc từ rclone (không gõ tay): mã Shared Drive và thư mục gốc đi vào
# biên nhận, và CRM từ chối cả biên nhận khi chúng khác .env của máy chủ web.
# ------------------------------------------------------------------------------------------------
remote_value() {
  "${RCLONE}" config show "${KHO_REMOTE}" | sed -n "s/^$1 = //p" | head -n 1 | tr -d '\r'
}

check_config() {
  if [ -z "${ENV_FOLDER}" ] || [ -z "${ARCHIVE_DIR}" ]; then
    log "Thiếu ENV_FOLDER hoặc ARCHIVE_DIR trong ${CONF_FILE} (xem office-pull.conf.example)."
    return 1
  fi

  SCOPE="$(remote_value scope)"
  TEAM_DRIVE="$(remote_value team_drive)"
  ROOT_FOLDER_ID="$(remote_value root_folder_id)"

  if [ "${SCOPE}" != "drive.readonly" ]; then
    log "Remote ${KHO_REMOTE} phải có scope = drive.readonly (đang là '${SCOPE}'). Tạo lại remote theo Phụ lục D."
    return 1
  fi

  if ! [[ "${TEAM_DRIVE}" =~ ^[A-Za-z0-9_-]+$ ]] || ! [[ "${ROOT_FOLDER_ID}" =~ ^[A-Za-z0-9_-]+$ ]]; then
    log "Remote ${KHO_REMOTE} phải có team_drive và root_folder_id (mã Shared Drive kho và thư mục gốc)."
    return 1
  fi

  return 0
}

# ------------------------------------------------------------------------------------------------
# Biên nhận JSON từ danh sách "đường;md5;cỡ". Tên là phần cuối của đường (tên tệp trên Drive). Dòng
# không đúng khuôn (tên có ký tự lạ, không có md5) bị bỏ, không vào biên nhận và không vào
# receipted.txt: lượt sau kiểm lại.
# ------------------------------------------------------------------------------------------------
build_receipt() {
  local files="$1" paths="$2" errors="$3" finished_at

  finished_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  : > "${paths}"

  awk -F';' \
    -v team="${TEAM_DRIVE}" -v root="${ROOT_FOLDER_ID}" \
    -v started="${STARTED_AT}" -v finished="${finished_at}" \
    -v errors="${errors}" -v paths="${paths}" '
    BEGIN {
      printf "{\"format\":1,\"kho\":{\"team_drive\":\"%s\",\"root_folder_id\":\"%s\"},", team, root
      printf "\"started_at\":\"%s\",\"finished_at\":\"%s\",\"errors\":%d,\"files\":[", started, finished, errors
      n = 0
      skipped = 0
    }
    {
      sub(/\r$/, "")
      if (NF != 3) { skipped++; next }
      path = $1; md5 = $2; size = $3
      name = path
      sub(/.*\//, "", name)
      if (name !~ /^[0-9A-Za-z._~-]+$/ || md5 !~ /^[0-9a-f]+$/ || length(md5) != 32 || size !~ /^[0-9]+$/) { skipped++; next }
      printf "%s{\"name\":\"%s\",\"md5\":\"%s\",\"size\":%s}", (n > 0 ? "," : ""), name, md5, size
      n++
      print path > paths
    }
    END {
      printf "]}\n"
      if (skipped > 0) { printf "%d dòng không đúng khuôn, không ghi vào biên nhận\n", skipped > "/dev/stderr" }
    }' "${files}"
}

nightly() {
  local run_dir status differ missing carried total receipt_name receipt_local receipt_paths destination

  STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  receipt_name="receipt-$(date -u +%Y%m%dT%H%M%SZ).json"
  run_dir="${STATE_DIR}/run-${receipt_name%.json}"
  mkdir -p "${run_dir}" || return 1
  touch "${RECEIPTED}"

  log "Bắt đầu lượt đêm (${receipt_name})."

  # 2. Chép kho về crypt, không bao giờ ghi đè.
  if ! "${RCLONE}" copy "${KHO_REMOTE}:" "${OFFICE_DEST}" --immutable; then
    log "LỖI: lượt chép kho về văn phòng báo lỗi (có thể có tệp bị đổi trên kho; xem các dòng trên)."
    ERRORS=$(( ERRORS + 1 ))
  fi

  # 3. Tệp chưa có biên nhận.
  if ! "${RCLONE}" lsf -R --files-only "${KHO_REMOTE}:" > "${run_dir}/all.unsorted"; then
    log "LỖI: không liệt kê được kho."
    ERRORS=$(( ERRORS + 1 ))
    : > "${run_dir}/all.unsorted"
  fi
  tr -d '\r' < "${run_dir}/all.unsorted" | sort -u > "${run_dir}/all.txt"
  tr -d '\r' < "${RECEIPTED}" | sort -u > "${run_dir}/receipted.sorted"
  comm -23 "${run_dir}/all.txt" "${run_dir}/receipted.sorted" > "${run_dir}/todo.txt"

  # 4. Kiểm nội dung từng tệp chưa có biên nhận.
  : > "${run_dir}/match.txt"
  : > "${run_dir}/differ.txt"
  : > "${run_dir}/missing.txt"
  if [ -s "${run_dir}/todo.txt" ]; then
    "${RCLONE}" cryptcheck "${KHO_REMOTE}:" "${OFFICE_DEST}" --one-way \
      --files-from "${run_dir}/todo.txt" \
      --match "${run_dir}/match.txt" \
      --differ "${run_dir}/differ.txt" \
      --missing-on-dst "${run_dir}/missing.txt"
    status=$?
    differ="$(count_lines "${run_dir}/differ.txt")"
    missing="$(count_lines "${run_dir}/missing.txt")"

    if [ "${differ}" -gt 0 ]; then
      log "LỖI: ${differ} tệp ở văn phòng khác nội dung trên kho (có thể có tệp bị đổi trên kho)."
      ERRORS=$(( ERRORS + differ ))
    fi

    # Tệp chưa có ở văn phòng (chép hỏng, hoặc vừa lên kho sau lượt chép) không phải lỗi riêng: nó
    # không vào biên nhận, và lượt sau chép và kiểm lại.
    if [ "${missing}" -gt 0 ]; then
      log "${missing} tệp chưa có ở văn phòng; lượt sau thử lại."
    fi

    if [ "${status}" -ne 0 ] && [ "${differ}" -eq 0 ] && [ "${missing}" -eq 0 ]; then
      log "LỖI: cryptcheck thoát mã ${status} mà không báo tệp nào lệch hay thiếu."
      ERRORS=$(( ERRORS + 1 ))
    fi
  fi

  # 5. md5 của Drive và cỡ của đúng các tệp khớp.
  : > "${run_dir}/files.txt"
  if [ -s "${run_dir}/match.txt" ]; then
    if ! "${RCLONE}" lsf -R --files-only --format "phs" --hash md5 --separator ";" \
      --files-from "${run_dir}/match.txt" "${KHO_REMOTE}:" > "${run_dir}/files.txt"; then
      log "LỖI: không đọc được md5 của các tệp vừa kiểm; lượt sau thử lại."
      ERRORS=$(( ERRORS + 1 ))
      : > "${run_dir}/files.txt"
    fi
  fi

  carried="$(read_carry)"
  total=$(( ERRORS + carried ))
  receipt_local="${run_dir}/${receipt_name}"
  receipt_paths="${run_dir}/receipt-paths.txt"
  build_receipt "${run_dir}/files.txt" "${receipt_paths}" "${total}" > "${receipt_local}"

  # 6. Gửi biên nhận; chỉ sau khi gửi thành công mới ghi nhận các tệp đó.
  destination="${BACKUPS_REMOTE}:${BACKUPS_FOLDER}/office-receipts/${ENV_FOLDER}/${receipt_name}"
  if "${RCLONE}" copyto "${receipt_local}" "${destination}" --immutable; then
    cat "${receipt_paths}" >> "${RECEIPTED}"
    echo 0 > "${CARRY_FILE}"
    log "Đã gửi biên nhận ${receipt_name}: $(count_lines "${receipt_paths}") tệp, ${total} lỗi."
  else
    log "LỖI: không gửi được biên nhận ${receipt_name}; các tệp của nó được kiểm lại lượt sau."
    ERRORS=$(( ERRORS + 1 ))
    echo "$(( total + 1 ))" > "${CARRY_FILE}"
  fi

  # 7. Archive CSDL (đã mã hoá AES-256 trên máy chủ web), bất biến.
  mkdir -p "${ARCHIVE_DIR}"
  if ! "${RCLONE}" copy "${BACKUPS_REMOTE}:${BACKUPS_FOLDER}/${ENV_FOLDER}/" "${ARCHIVE_DIR}" --immutable; then
    log "LỖI: không kéo được archive CSDL về ${ARCHIVE_DIR}."
    ERRORS=$(( ERRORS + 1 ))
    add_carry 1
  fi

  rm -rf "${run_dir}"
  log "Xong lượt đêm: ${ERRORS} lỗi."
}

monthly() {
  local run_dir bad differ missing_receipted

  run_dir="${STATE_DIR}/run-monthly-$(date -u +%Y%m%dT%H%M%SZ)"
  mkdir -p "${run_dir}" || return 1
  touch "${RECEIPTED}"
  : > "${run_dir}/differ.txt"
  : > "${run_dir}/missing.txt"

  log "Bắt đầu lượt kiểm toàn bộ hằng tháng."

  "${RCLONE}" cryptcheck "${KHO_REMOTE}:" "${OFFICE_DEST}" --one-way \
    --differ "${run_dir}/differ.txt" \
    --missing-on-dst "${run_dir}/missing.txt"

  differ="$(count_lines "${run_dir}/differ.txt")"
  tr -d '\r' < "${run_dir}/missing.txt" | sort -u > "${run_dir}/missing.sorted"
  tr -d '\r' < "${RECEIPTED}" | sort -u > "${run_dir}/receipted.sorted"
  missing_receipted="$(comm -12 "${run_dir}/missing.sorted" "${run_dir}/receipted.sorted" | grep -c '' || true)"
  bad=$(( differ + missing_receipted ))

  if [ -s "${run_dir}/differ.txt" ]; then
    log "Tệp lệch nội dung:"
    cat "${run_dir}/differ.txt"
  fi

  if [ "${bad}" -gt 0 ]; then
    log "LỖI: ${differ} tệp lệch nội dung, ${missing_receipted} tệp đã có biên nhận mà không còn ở văn phòng. Báo người cài đặt."
    ERRORS=$(( ERRORS + bad ))
    add_carry "${bad}"
  else
    log "Kiểm toàn bộ: mọi tệp đã có biên nhận đều còn ở văn phòng và khớp nội dung."
  fi

  rm -rf "${run_dir}"
}

main() {
  local mode="nightly"

  case "${1:-}" in
    '') ;;
    --check-monthly) mode="monthly" ;;
    *)
      log "Tham số lạ: $1 (chỉ nhận --check-monthly)."
      return 2
      ;;
  esac

  if ! acquire_lock; then
    return 75
  fi
  trap release_lock EXIT

  if ! check_config; then
    return 2
  fi

  if [ "${mode}" = "monthly" ]; then
    monthly
  else
    nightly
  fi

  if [ "${ERRORS}" -gt 0 ]; then
    return 1
  fi

  return 0
}

main "$@"
exit $?
