#!/usr/bin/env bash
# VK-CRM — kiểm THẬT, bằng request thật, rằng hai mẫu máy chủ web của `tools/deploy/` không phát
# kho tệp hồ sơ `storage/app/private`, log và dotfile — kể cả khi document root bị đặt NHẦM vào
# gốc dự án thay vì `public/` (SPEC §10.4, kế hoạch M8 Task 4).
#
# Cách làm:
#   - dựng container `nginx:stable-alpine` và `httpd:2.4-alpine` CHÍNH THỨC từ đúng khối
#     `server`/`<VirtualHost *:443>` của hai mẫu (bỏ TLS, nghe cổng 8080 trong một mạng docker
#     riêng — không map cổng nào ra máy), với gốc dự án mount read-only làm document root;
#   - đặt một tệp lính canh nội dung ngẫu nhiên vào `storage/app/private` và một vào `storage/logs`;
#   - gửi request THẬT bằng `curlimages/curl` từ một container thứ hai (`--path-as-is`, để các biến
#     thể `//`, `..`, `%73` tới máy chủ nguyên dạng).
#
# Mỗi lớp chặn có một ĐỐI CHỨNG: cùng máy chủ, gỡ đúng lớp đó, phải trả 200 KÈM nội dung lính canh —
# chứng minh container đọc được tệp và chính lớp đó (không phải một lý do tình cờ) là thứ chặn.
#
#   nginx  N1  mẫu nguyên vẹn                                      -> 404
#          N0  mẫu bỏ khối `location ^~ /storage/`                 -> 200 + lính canh (đối chứng)
#   apache A1  mẫu nguyên vẹn                                      -> 404
#          A2  mẫu bỏ `RedirectMatch 404 "^/storage/"`,
#              AllowOverride All                                   -> 403 (.htaccess của storage/app/private)
#          A0  như A2 nhưng AllowOverride None                     -> 200 + lính canh (đối chứng)
#          AD  mẫu với luật dotfile CŨ `<FilesMatch "^\.">`        -> 200 cho /.github/… (vì sao đổi luật)
#
# Nội dung của `.env` không bao giờ được in ra: chỉ in mã trạng thái, và một phản hồi chỉ bị dò
# xem có chứa chuỗi lính canh hay không.
#
# Chạy từ gốc dự án:   bash tools/deploy/verify-storage-blocked.sh
# Cần Docker và ba image ở trên (chưa có thì `docker pull`). Mọi container, mạng và tệp lính canh
# bị xoá khi xong, kể cả khi lỗi giữa chừng. Thoát 0 khi mọi dòng PASS, 1 nếu có dòng FAIL.
set -euo pipefail
export MSYS_NO_PATHCONV=1

here="$(cd "$(dirname "$0")" && pwd)"
project="$(cd "$here/../.." && pwd)"
# Docker Desktop trên Windows (Git Bash) cần đường dẫn dạng D:/…; Linux dùng thẳng `pwd`.
project_mount="$(cd "$project" && (pwd -W 2>/dev/null || pwd))"

tag="vkcrm-verify-storage-$$"
net="$tag"
private_name="verify-$$-$RANDOM$RANDOM.txt"
log_name="verify-$$-$RANDOM$RANDOM.log"
marker_body="dau-vet-muc-10-4-$RANDOM$RANDOM$RANDOM$RANDOM"
private_file="$project/storage/app/private/$private_name"
log_file="$project/storage/logs/$log_name"
workflow="$(ls "$project/.github/workflows" 2>/dev/null | head -n 1 || true)"

cleanup() {
  docker rm -f "$tag-nginx" "$tag-httpd" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
  rm -f "$private_file" "$log_file"
}
trap cleanup EXIT

printf '%s\n' "$marker_body" > "$private_file"
printf '%s\n' "$marker_body" > "$log_file"
docker network create "$net" >/dev/null

failures=0

# probe <tên cấu hình> <container> <đường dẫn> <mã mong đợi> [leak]
#   leak: phản hồi PHẢI chứa lính canh (đối chứng); bỏ trống: phản hồi KHÔNG được chứa lính canh.
probe() {
  local config="$1" host="$2" path="$3" expected="$4" mode="${5:-}"
  local out code body leaked=no verdict=FAIL

  out="$(docker run --rm --network "$net" curlimages/curl:latest -s --path-as-is --max-time 10 \
    -w '\n%{http_code}' "http://$host:8080$path" || true)"
  code="${out##*$'\n'}"
  body="${out%$'\n'*}"

  case "$body" in *"$marker_body"*) leaked=yes ;; esac

  if [ "$code" = "$expected" ]; then
    if { [ "$mode" = leak ] && [ "$leaked" = yes ]; } || { [ "$mode" != leak ] && [ "$leaked" = no ]; }; then
      verdict=PASS
    fi
  fi

  [ "$verdict" = PASS ] || failures=$((failures + 1))
  printf '%-4s %-3s GET %-62s -> %s (mong đợi %s)%s\n' "$verdict" "$config" "$path" "$code" "$expected" \
    "$([ "$leaked" = yes ] && printf ', CÓ nội dung lính canh' || true)"
}

wait_for() {
  local host="$1" try
  for try in $(seq 1 30); do
    if docker run --rm --network "$net" curlimages/curl:latest -s -o /dev/null --max-time 2 "http://$host:8080/" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "Máy chủ $host không trả lời sau 30 giây:" >&2
  docker logs "$host" >&2 || true
  exit 1
}

# --- nginx -------------------------------------------------------------------------------------

# Khối `server` thứ hai của mẫu (cổng 443), bỏ TLS, `root` CỐ Ý đặt vào gốc dự án.
nginx_conf() {
  awk '/^server \{/ { n++ } n == 2 { print } n == 2 && /^\}/ { exit }' "$here/nginx.conf.example" \
    | sed -e 's/listen 443 ssl;/listen 8080;/' \
          -e '/listen \[::\]:443 ssl;/d' \
          -e '/http2 on;/d' \
          -e '/^ *ssl_/d' \
          -e 's#root /var/www/vk-crm/public;#root /srv/app;#' \
    | if [ "${1:-}" = drop-storage ]; then sed '/location \^~ \/storage\/ {/,/}/d'; else cat; fi
}

start_nginx() {
  docker rm -f "$tag-nginx" >/dev/null 2>&1 || true
  docker run -d --name "$tag-nginx" --network "$net" -v "$project_mount:/srv/app:ro" \
    -e VERIFY_CONF="$1" nginx:stable-alpine \
    sh -c 'printf "%s\n" "$VERIFY_CONF" > /etc/nginx/conf.d/default.conf && nginx -t && exec nginx -g "daemon off;"' >/dev/null
  wait_for "$tag-nginx"
}

# --- apache ------------------------------------------------------------------------------------

# Một httpd.conf tối thiểu + khối `<VirtualHost *:443>` của mẫu, bỏ TLS, `DocumentRoot` và
# `<Directory>` CỐ Ý đặt vào gốc dự án.
#   $1 = All|None (AllowOverride), $2 = keep|drop-storage|old-dotfile
httpd_conf() {
  cat <<'EOF'
ServerRoot "/usr/local/apache2"
Listen 8080
LoadModule mpm_event_module modules/mod_mpm_event.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule alias_module modules/mod_alias.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
User daemon
Group daemon
ServerName localhost
ErrorLog /proc/self/fd/2
TypesConfig conf/mime.types
EOF
  awk '/^<VirtualHost \*:443>/ { p = 1 } p { print } p && /^<\/VirtualHost>/ { exit }' "$here/apache-vhost.conf.example" \
    | sed -e 's#<VirtualHost \*:443>#<VirtualHost *:8080>#' \
          -e '/^ *SSL/d' \
          -e 's#/var/www/vk-crm/public#/srv/app#g' \
          -e "s#AllowOverride All#AllowOverride $1#" \
    | case "$2" in
        drop-storage) sed '/^ *RedirectMatch 404 "\^\/storage\/"/d' ;;
        old-dotfile) sed 's#^ *RedirectMatch 404 "/\\\."#    <FilesMatch "^\\.">\n        Require all denied\n    </FilesMatch>#' ;;
        *) cat ;;
      esac
}

start_httpd() {
  docker rm -f "$tag-httpd" >/dev/null 2>&1 || true
  docker run -d --name "$tag-httpd" --network "$net" -v "$project_mount:/srv/app:ro" \
    -e VERIFY_CONF="$1" httpd:2.4-alpine \
    sh -c 'printf "%s\n" "$VERIFY_CONF" > /usr/local/apache2/conf/verify.conf && httpd -t -f /usr/local/apache2/conf/verify.conf && exec httpd -f /usr/local/apache2/conf/verify.conf -DFOREGROUND' >/dev/null
  wait_for "$tag-httpd"
}

# Các cách một URL gọi tới tệp lính canh trong kho tệp hồ sơ.
private_paths=(
  "/storage/app/private/$private_name"
  "//storage/app/private/$private_name"
  "/%73torage/app/private/$private_name"
  "/public/../storage/app/private/$private_name"
)

# Dotfile và log: những thứ `root` đặt nhầm làm lộ cùng lúc.
other_paths=("/storage/app/private/.htaccess" "/storage/logs/$log_name" "/.env")
[ -n "$workflow" ] && other_paths+=("/.github/workflows/$workflow")

echo "== nginx:stable-alpine — tools/deploy/nginx.conf.example, root = gốc dự án =="
start_nginx "$(nginx_conf)"
for path in "${private_paths[@]}" "${other_paths[@]}"; do probe N1 "$tag-nginx" "$path" 404; done

start_nginx "$(nginx_conf drop-storage)"
probe N0 "$tag-nginx" "/storage/app/private/$private_name" 200 leak
probe N0 "$tag-nginx" "/storage/logs/$log_name" 200 leak

echo "== httpd:2.4-alpine — tools/deploy/apache-vhost.conf.example, DocumentRoot = gốc dự án =="
start_httpd "$(httpd_conf All keep)"
for path in "${private_paths[@]}" "${other_paths[@]}"; do probe A1 "$tag-httpd" "$path" 404; done

start_httpd "$(httpd_conf All drop-storage)"
probe A2 "$tag-httpd" "/storage/app/private/$private_name" 403

start_httpd "$(httpd_conf None drop-storage)"
probe A0 "$tag-httpd" "/storage/app/private/$private_name" 200 leak

if [ -n "$workflow" ]; then
  start_httpd "$(httpd_conf All old-dotfile)"
  probe AD "$tag-httpd" "/.github/workflows/$workflow" 200
fi

echo
if [ "$failures" -eq 0 ]; then
  echo "Mọi dòng PASS."
else
  echo "$failures dòng FAIL."
  exit 1
fi
