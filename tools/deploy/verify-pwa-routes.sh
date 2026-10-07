#!/usr/bin/env bash
# VK-CRM — kiểm THẬT, bằng request thật qua nginx/Apache + php-fpm, rằng hai mẫu máy chủ web của
# `tools/deploy/` phục vụ đúng các route của app trên điện thoại (kế hoạch M12, phán quyết R4):
#
#   - `/admin/sw.js`, `/portal/sw.js` là ROUTE PHP mang đuôi `.js` — phải tới được PHP (không bị
#     location tệp tĩnh của nginx trả 404) và giữ header do PHP đặt: `Content-Type` JavaScript,
#     `Service-Worker-Allowed`, `Cache-Control: no-cache` — KHÔNG `immutable` một năm của tệp tĩnh
#     (có thì điện thoại không bao giờ thấy bản sửa của worker);
#   - `/portal/offline`, `/portal/manifest.webmanifest` tới PHP;
#   - `/pwa/register.js` là tệp tĩnh THẬT: nhận `immutable` của mẫu (vì thế thẻ `<script>` mang
#     `?v=<băm nội dung>`, `App\Support\Pwa\RegisterScript`).
#
# Cách làm (cùng khuôn `tools/deploy/verify-storage-blocked.sh`):
#   - một container php-fpm `webdevops/php:8.3-alpine` mount gốc dự án ở `/var/www/html` (đường
#     dẫn giống hệt bên máy chủ web, để `SCRIPT_FILENAME` khớp), dùng `.env` của dự án;
#   - container `nginx:stable-alpine` và `httpd:2.4-alpine` CHÍNH THỨC dựng từ đúng khối
#     `server`/`<VirtualHost *:443>` của hai mẫu (bỏ TLS, nghe cổng 8080 trong một mạng docker
#     riêng — không map cổng nào ra máy), `root`/`DocumentRoot` = `/var/www/html/public`, chỉ đổi
#     đích FastCGI sang container php-fpm;
#   - request THẬT bằng `curlimages/curl` từ một container thứ ba.
#
# Đối chứng: nginx với mẫu BỎ hai khối `location = /…/sw.js` phải trả 404 của CHÍNH nginx — chứng
# minh hai khối đó (không phải một lý do tình cờ) là thứ đưa worker tới PHP.
#
#   nginx  N1  mẫu nguyên vẹn                          -> sw.js 200 + header R4, register.js immutable
#          N0  mẫu bỏ hai khối `location = …/sw.js`    -> sw.js 404 của nginx (đối chứng)
#   apache A1  mẫu nguyên vẹn (`AllowOverride All`,
#              `.htaccess` của Laravel)                 -> sw.js 200 + header R4, register.js immutable
#
# Chạy từ gốc dự án:   bash tools/deploy/verify-pwa-routes.sh
# Biến môi trường tuỳ chọn: `VERIFY_PHP_NETWORK` = mạng docker có CSDL mà `.env` trỏ tới (máy dev:
# `crmkhachhang_vkcrm`) — chỉ cần cho dòng đối chứng `/admin/login` (trang có phiên); các route
# PWA không chạm CSDL. `VERIFY_PHP_ENV` = các cặp `-e K=V` thêm cho php-fpm (ví dụ CSDL riêng).
# Cần Docker và bốn image ở trên. Mọi container và mạng bị xoá khi xong, kể cả khi lỗi giữa chừng.
# Thoát 0 khi mọi dòng PASS, 1 nếu có dòng FAIL. Chỉ in mã trạng thái và header, không in thân.
set -euo pipefail
export MSYS_NO_PATHCONV=1

here="$(cd "$(dirname "$0")" && pwd)"
project="$(cd "$here/../.." && pwd)"
project_mount="$(cd "$project" && (pwd -W 2>/dev/null || pwd))"

tag="vkcrm-verify-pwa-$$"
net="$tag"
php="$tag-php"

cleanup() {
  docker rm -f "$tag-nginx" "$tag-httpd" "$php" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$net" >/dev/null

# shellcheck disable=SC2086
docker run -d --name "$php" --network "$net" -v "$project_mount:/var/www/html" \
  -e MAIL_MAILER=log ${VERIFY_PHP_ENV:-} webdevops/php:8.3-alpine >/dev/null
if [ -n "${VERIFY_PHP_NETWORK:-}" ]; then
  docker network connect "$VERIFY_PHP_NETWORK" "$php"
fi

failures=0

# fetch <host> <path> -> in ra phần header của phản hồi (thân bỏ đi)
fetch() {
  docker run --rm --network "$net" curlimages/curl:latest -s --max-time 20 -D - -o /dev/null \
    "http://$1:8080$2" 2>/dev/null | tr -d '\r' || true
}

header() {
  printf '%s\n' "$1" | awk -v name="$2" 'BEGIN { IGNORECASE = 1 } tolower($0) ~ "^" tolower(name) ":" { sub(/^[^:]*: */, ""); print; exit }'
}

status() {
  printf '%s\n' "$1" | awk 'NR == 1 { print $2 }'
}

# check <cấu hình> <host> <path> <mã> <cache-control: no-cache|immutable|-> [service-worker-allowed]
check() {
  local config="$1" host="$2" path="$3" expected="$4" cache="$5" swa="${6:-}"
  local out code cc type allowed verdict=PASS reason=""

  out="$(fetch "$host" "$path")"
  code="$(status "$out")"
  cc="$(header "$out" 'Cache-Control')"
  type="$(header "$out" 'Content-Type')"
  allowed="$(header "$out" 'Service-Worker-Allowed')"

  [ "$code" = "$expected" ] || { verdict=FAIL; reason="mã $code"; }

  case "$cache" in
    no-cache)
      case "$cc" in *no-cache*) ;; *) verdict=FAIL; reason="$reason cache-control thiếu no-cache" ;; esac
      case "$cc" in *immutable*|*max-age=31536000*) verdict=FAIL; reason="$reason cache-control immutable" ;; esac
      case "$type" in application/javascript*) ;; *) verdict=FAIL; reason="$reason content-type $type" ;; esac
      ;;
    immutable)
      case "$cc" in *immutable*) ;; *) verdict=FAIL; reason="$reason cache-control thiếu immutable" ;; esac
      ;;
  esac

  if [ -n "$swa" ] && [ "$allowed" != "$swa" ]; then
    verdict=FAIL; reason="$reason service-worker-allowed '$allowed'"
  fi

  [ "$verdict" = PASS ] || failures=$((failures + 1))
  printf '%-4s %-3s GET %-30s -> %s | type: %s | cache-control: %s | sw-allowed: %s%s\n' \
    "$verdict" "$config" "$path" "$code" "${type:--}" "${cc:--}" "${allowed:--}" "${reason:+ ($reason)}"
}

# Đối chứng N0: 404 do chính nginx trả (thân trang lỗi mặc định có chữ "nginx"), không phải PHP.
check_nginx_404() {
  local host="$1" path="$2" body verdict=FAIL code
  code="$(docker run --rm --network "$net" curlimages/curl:latest -s --max-time 20 -o /dev/null -w '%{http_code}' "http://$host:8080$path" || true)"
  body="$(docker run --rm --network "$net" curlimages/curl:latest -s --max-time 20 "http://$host:8080$path" || true)"
  case "$body" in *"<center>nginx"*) [ "$code" = 404 ] && verdict=PASS ;; esac
  [ "$verdict" = PASS ] || failures=$((failures + 1))
  printf '%-4s N0  GET %-30s -> %s, thân trang lỗi của nginx: %s (mong đợi 404 của nginx)\n' \
    "$verdict" "$path" "$code" "$(case "$body" in *"<center>nginx"*) echo có ;; *) echo không ;; esac)"
}

wait_for() {
  local host="$1" try
  for try in $(seq 1 60); do
    if docker run --rm --network "$net" curlimages/curl:latest -s -o /dev/null --max-time 5 "http://$host:8080/up" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "Máy chủ $host không trả lời sau 60 giây:" >&2
  docker logs "$host" >&2 || true
  exit 1
}

# --- nginx -------------------------------------------------------------------------------------

nginx_conf() {
  awk '/^server \{/ { n++ } n == 2 { print } n == 2 && /^\}/ { exit }' "$here/nginx.conf.example" \
    | sed -e 's/listen 443 ssl;/listen 8080;/' \
          -e '/listen \[::\]:443 ssl;/d' \
          -e '/http2 on;/d' \
          -e '/^ *ssl_/d' \
          -e 's#root /var/www/vk-crm/public;#root /var/www/html/public;#' \
          -e "s#fastcgi_pass unix:/run/php/php8.3-fpm.sock;#fastcgi_pass $php:9000;#" \
    | if [ "${1:-}" = drop-sw ]; then sed '/location = \/\(admin\|portal\)\/sw\.js {/,/}/d'; else cat; fi
}

start_nginx() {
  docker rm -f "$tag-nginx" >/dev/null 2>&1 || true
  docker run -d --name "$tag-nginx" --network "$net" -v "$project_mount:/var/www/html:ro" \
    -e VERIFY_CONF="$1" nginx:stable-alpine \
    sh -c 'printf "%s\n" "$VERIFY_CONF" > /etc/nginx/conf.d/default.conf && nginx -t && exec nginx -g "daemon off;"' >/dev/null
  wait_for "$tag-nginx"
}

# --- apache ------------------------------------------------------------------------------------

httpd_conf() {
  cat <<EOF
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
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
LoadModule log_config_module modules/mod_log_config.so
User daemon
Group daemon
ServerName localhost
ErrorLog /proc/self/fd/2
TypesConfig conf/mime.types
DirectoryIndex index.php
<FilesMatch "\.php\$">
    SetHandler "proxy:fcgi://$php:9000"
</FilesMatch>
EOF
  awk '/^<VirtualHost \*:443>/ { p = 1 } p { print } p && /^<\/VirtualHost>/ { exit }' "$here/apache-vhost.conf.example" \
    | sed -e 's#<VirtualHost \*:443>#<VirtualHost *:8080>#' \
          -e '/^ *SSL/d' \
          -e 's#/var/www/vk-crm/public#/var/www/html/public#g'
}

start_httpd() {
  docker rm -f "$tag-httpd" >/dev/null 2>&1 || true
  docker run -d --name "$tag-httpd" --network "$net" -v "$project_mount:/var/www/html:ro" \
    -e VERIFY_CONF="$1" httpd:2.4-alpine \
    sh -c 'printf "%s\n" "$VERIFY_CONF" > /usr/local/apache2/conf/verify.conf && httpd -t -f /usr/local/apache2/conf/verify.conf && exec httpd -f /usr/local/apache2/conf/verify.conf -DFOREGROUND' >/dev/null
  wait_for "$tag-httpd"
}

pwa_checks() {
  local config="$1" host="$2"
  check "$config" "$host" /admin/sw.js 200 no-cache /admin
  check "$config" "$host" /portal/sw.js 200 no-cache /portal
  check "$config" "$host" '/portal/sw.js?probe=1' 200 no-cache /portal
  check "$config" "$host" /portal/offline 200 -
  check "$config" "$host" /admin/offline 200 -
  check "$config" "$host" /portal/manifest.webmanifest 200 -
  check "$config" "$host" /pwa/register.js 200 immutable
  if [ -n "${VERIFY_PHP_NETWORK:-}" ]; then
    check "$config" "$host" /admin/login 200 -
    check "$config" "$host" /portal/login 200 -
  fi
}

echo "== nginx:stable-alpine + php-fpm — tools/deploy/nginx.conf.example =="
start_nginx "$(nginx_conf)"
pwa_checks N1 "$tag-nginx"

start_nginx "$(nginx_conf drop-sw)"
check_nginx_404 "$tag-nginx" /admin/sw.js
check_nginx_404 "$tag-nginx" /portal/sw.js

echo "== httpd:2.4-alpine + php-fpm (mod_proxy_fcgi) — tools/deploy/apache-vhost.conf.example =="
start_httpd "$(httpd_conf)"
pwa_checks A1 "$tag-httpd"

echo
if [ "$failures" -eq 0 ]; then
  echo "Mọi dòng PASS."
else
  echo "$failures dòng FAIL."
  exit 1
fi
