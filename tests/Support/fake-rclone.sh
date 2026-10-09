#!/usr/bin/env bash
# rclone GIẢ cho test chạy thật `tools/backup/office-pull.sh` (M14 Task 7). Không mạng, không Google:
# mỗi remote là một thư mục dưới $FAKE_RCLONE_ROOT:
#   vkkho:      -> $FAKE_RCLONE_ROOT/kho        (Kho trên Drive)
#   vkoffice:X  -> $FAKE_RCLONE_ROOT/office/X   (crypt ở văn phòng; ở đây là bản thô)
#   vkbackups:X -> $FAKE_RCLONE_ROOT/backups/X  ("VK-CRM Backups")
# Mỗi lời gọi được ghi (một dòng, các đối số cách nhau bởi dấu cách) vào $FAKE_RCLONE_ROOT/calls.log.
# Chỉ hiểu đúng các lệnh mà script dùng; lệnh khác thoát mã 99.
set -uo pipefail
root="${FAKE_RCLONE_ROOT:?}"
echo "$*" >> "${root}/calls.log"

path_of() {
  case "$1" in
    vkkho:*) echo "${root}/kho/${1#vkkho:}" ;;
    vkoffice:*) echo "${root}/office/${1#vkoffice:}" ;;
    vkbackups:*) echo "${root}/backups/${1#vkbackups:}" ;;
    *) echo "$1" ;;
  esac
}

list_files() {
  (cd "$1" && find . -type f | sed 's|^\./||' | sort)
}

opt() {
  local name="$1"; shift
  while [ $# -gt 0 ]; do
    if [ "$1" = "${name}" ]; then echo "$2"; return 0; fi
    shift
  done
  return 1
}

cmd="$1"; shift
case "${cmd}" in
  config)
    [ "$1" = "show" ] || exit 99
    printf '[%s]\ntype = drive\nscope = %s\nteam_drive = %s\nroot_folder_id = %s\n' "$2" \
      "${FAKE_SCOPE:-drive.readonly}" "${FAKE_TEAM_DRIVE}" "${FAKE_ROOT_FOLDER}"
    ;;
  copy | copyto)
    src="$(path_of "$1")"; dst="$(path_of "$2")"
    case " $* " in *" --immutable "*) ;; *) echo "thiếu --immutable" >&2; exit 98 ;; esac
    status=0
    if [ "${cmd}" = "copyto" ]; then
      [ -n "${FAKE_FAIL_COPYTO:-}" ] && exit 1
      if [ -e "${dst}" ] && ! cmp -s "${src}" "${dst}"; then exit 1; fi
      mkdir -p "$(dirname "${dst}")" && cp "${src}" "${dst}"
      exit 0
    fi
    while IFS= read -r f; do
      [ -z "${f}" ] && continue
      if [ -e "${dst}/${f}" ]; then
        cmp -s "${src}/${f}" "${dst}/${f}" || { echo "immutable: ${f} đã đổi" >&2; status=1; }
      else
        mkdir -p "$(dirname "${dst}/${f}")" && cp "${src}/${f}" "${dst}/${f}"
      fi
    done < <(list_files "${src}")
    exit "${status}"
    ;;
  lsf)
    remote="${!#}"; dir="$(path_of "${remote}")"
    from="$(opt --files-from "$@" || true)"
    format="$(opt --format "$@" || true)"
    if [ -n "${from}" ]; then files="$(cat "${from}")"; else files="$(list_files "${dir}")"; fi
    while IFS= read -r f; do
      [ -z "${f}" ] && continue
      [ -f "${dir}/${f}" ] || continue
      if [ "${format}" = "phs" ]; then
        printf '%s;%s;%s\n' "${f}" "$(md5sum < "${dir}/${f}" | cut -d' ' -f1)" "$(wc -c < "${dir}/${f}" | tr -d ' ')"
      else
        echo "${f}"
      fi
    done <<< "${files}"
    ;;
  cryptcheck)
    src="$(path_of "$1")"; dst="$(path_of "$2")"
    case " $* " in *" --one-way "*) ;; *) echo "thiếu --one-way" >&2; exit 98 ;; esac
    from="$(opt --files-from "$@" || true)"
    match="$(opt --match "$@" || true)"; differ="$(opt --differ "$@" || true)"; missing="$(opt --missing-on-dst "$@" || true)"
    if [ -n "${from}" ]; then files="$(cat "${from}")"; else files="$(list_files "${src}")"; fi
    status=0
    while IFS= read -r f; do
      [ -z "${f}" ] && continue
      if [ ! -e "${dst}/${f}" ]; then
        [ -n "${missing}" ] && echo "${f}" >> "${missing}"; status=1
      elif cmp -s "${src}/${f}" "${dst}/${f}"; then
        [ -n "${match}" ] && echo "${f}" >> "${match}"
      else
        [ -n "${differ}" ] && echo "${f}" >> "${differ}"; status=1
      fi
    done <<< "${files}"
    exit "${status}"
    ;;
  *)
    exit 99
    ;;
esac
