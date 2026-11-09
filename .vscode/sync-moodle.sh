#!/usr/bin/env bash
set -euo pipefail

PROJECT="grade/export/group"
ROOT_405="/var/www/html/moodle405"
ROOT_502="/var/www/html/moodle502/public"
SOURCE_405="$ROOT_405/$PROJECT"
SOURCE_502="$ROOT_502/$PROJECT"
SCRIPT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/sync-moodle.sh"
SLUG="$(printf '%s' "$PROJECT" | tr '/.' '--')"
TRIGGER_405="moodle-sync-405-$SLUG"
TRIGGER_502="moodle-sync-502-$SLUG"

sync_tree() {
    local source="$1" destination="$2"
    mkdir -p -- "$destination"
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --update --exclude='/.git/' --exclude='**/.git/**' -- "$source/" "$destination/"
    else
        # GNU cp is the fallback when rsync is unavailable. Existing newer files win;
        # equal-time differences are left untouched for manual resolution.
        while IFS= read -r -d '' file; do
            local relative="${file#"$source"/}"
            mkdir -p -- "$destination/$(dirname -- "$relative")"
            cp -au -- "$file" "$destination/$relative"
        done < <(find "$source" -mindepth 1 -type d -name .git -prune -o \( -type f -o -type l \) -print0)
    fi
}

sync_now() {
    if [[ ! -d "$SOURCE_405" || ! -d "$SOURCE_502" ]]; then
        printf 'No encuentro ambas carpetas del proyecto:\n  %s\n  %s\n' "$SOURCE_405" "$SOURCE_502" >&2
        return 2
    fi
    local lock="${TMPDIR:-/tmp}/moodle-sync-$SLUG.lock"
    exec 9>"$lock"
    if ! flock -n 9; then
        printf 'Ya hay una sincronización en curso para %s.\n' "$PROJECT"
        return 0
    fi
    if command -v rsync >/dev/null 2>&1; then
        printf 'Sincronizando %s con rsync...\n' "$PROJECT"
    else
        printf 'Sincronizando %s con cp -au (rsync no está instalado)...\n' "$PROJECT"
    fi
    sync_tree "$SOURCE_405" "$SOURCE_502"
    sync_tree "$SOURCE_502" "$SOURCE_405"
    printf 'Sincronización completada. Las eliminaciones no se propagan.\n'
}

watch_on() {
    command -v watchman >/dev/null 2>&1 || {
        printf 'No encuentro Watchman en PATH.\n' >&2
        return 2
    }
    [[ -d "$SOURCE_405" && -d "$SOURCE_502" ]] || {
        printf 'No encuentro ambas carpetas del proyecto:\n  %s\n  %s\n' "$SOURCE_405" "$SOURCE_502" >&2
        return 2
    }
    watchman watch "$SOURCE_405"
    watchman watch "$SOURCE_502"
    watchman -- trigger "$SOURCE_405" "$TRIGGER_405" -- "$SCRIPT" sync
    watchman -- trigger "$SOURCE_502" "$TRIGGER_502" -- "$SCRIPT" sync
    printf 'Watchman vigila %s en ambas instalaciones. Usa "%s stop" para detenerlo.\n' "$PROJECT" "$SCRIPT"
}

watch_off() {
    command -v watchman >/dev/null 2>&1 || {
        printf 'No encuentro Watchman en PATH.\n' >&2
        return 2
    }
    watchman trigger-del "$SOURCE_405" "$TRIGGER_405" >/dev/null 2>&1 || true
    watchman trigger-del "$SOURCE_502" "$TRIGGER_502" >/dev/null 2>&1 || true
    watchman watch-del "$SOURCE_405" >/dev/null 2>&1 || true
    watchman watch-del "$SOURCE_502" >/dev/null 2>&1 || true
    printf 'Vigilancia detenida para %s.\n' "$PROJECT"
}

case "${1:-sync}" in
    sync) sync_now ;;
    watch) watch_on ;;
    stop) watch_off ;;
    *) printf 'Uso: %s [sync|watch|stop]\n' "$0" >&2; exit 2 ;;
esac
