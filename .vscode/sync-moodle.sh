#!/usr/bin/env bash
set -euo pipefail

PROJECT="grade/export/group"
ROOT_405="/var/www/html/moodle405"
ROOT_502="/var/www/html/moodle502/public"
SOURCE_405="$ROOT_405/$PROJECT"
SOURCE_502="$ROOT_502/$PROJECT"
SCRIPT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/sync-moodle.sh"
SLUG="$(printf '%s' "$PROJECT" | tr '/.' '--')"
LOG_FILE="${TMPDIR:-/tmp}/moodle-sync-$SLUG.log"
STATE_FILE="/var/tmp/moodle-sync-$SLUG.state"
STATE_DELIM=$'\x1f'
TRIGGER_405="moodle-sync-405-$SLUG"
TRIGGER_502="moodle-sync-502-$SLUG"
declare -A OLD_405=() OLD_502=() CUR_405=() CUR_502=()

log_line() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" | tee -a "$LOG_FILE"
}

load_state() {
    OLD_405=()
    OLD_502=()
    if [[ -f "$STATE_FILE" ]]; then
        local relative left right
        while IFS="$STATE_DELIM" read -r relative left right; do
            [[ -n "$relative" ]] || continue
            OLD_405["$relative"]="$left"
            OLD_502["$relative"]="$right"
        done < "$STATE_FILE"
    fi
}

snapshot_tree() {
    local root="$1" side="$2" relative signature
    if [[ "$side" == 405 ]]; then CUR_405=(); else CUR_502=(); fi
    while IFS=$'\t' read -r relative signature; do
        [[ -n "$relative" ]] || continue
        if [[ "$side" == 405 ]]; then CUR_405["$relative"]="$signature"; else CUR_502["$relative"]="$signature"; fi
    done < <(find "$root" -type d -name .git -prune -o -type f -printf '%P\t%T@|%C@|%s\n')
}

file_signature() {
    if [[ -f "$1" ]]; then find "$1" -maxdepth 0 -type f -printf '%T@|%C@|%s'; fi
}

copy_file() {
    local source="$1" destination="$2" direction="$3"
    mkdir -p -- "$(dirname -- "$destination")"
    cp -av -- "$source" "$destination" 2>&1 | sed "s/^/[$direction] /" | tee -a "$LOG_FILE"
}

delete_file() {
    local path="$1" direction="$2"
    rm -f -- "$path"
    log_line "[$direction] Eliminado: $path"
}

refresh_path() {
    local relative="$1" left right
    left="$SOURCE_405/$relative"
    right="$SOURCE_502/$relative"
    if [[ -f "$left" ]]; then CUR_405["$relative"]="$(file_signature "$left")"; else unset 'CUR_405[$relative]'; fi
    if [[ -f "$right" ]]; then CUR_502["$relative"]="$(file_signature "$right")"; else unset 'CUR_502[$relative]'; fi
}

sync_one_path() {
    local relative="$1" left right current_left current_right old_left old_right
    left="$SOURCE_405/$relative"
    right="$SOURCE_502/$relative"
    current_left="${CUR_405[$relative]-}"
    current_right="${CUR_502[$relative]-}"
    old_left="${OLD_405[$relative]-}"
    old_right="${OLD_502[$relative]-}"

    if [[ -n "$current_left" && -n "$current_right" ]]; then
        if [[ "$current_left" != "$current_right" ]]; then
            if [[ -z "$old_left" && -z "$old_right" ]]; then
                local time_left time_right
                time_left="$(stat -c '%y' -- "$left")"
                time_right="$(stat -c '%y' -- "$right")"
                if [[ "$time_left" > "$time_right" ]]; then
                    copy_file "$left" "$right" '405 -> 502'
                elif [[ "$time_right" > "$time_left" ]]; then
                    copy_file "$right" "$left" '502 -> 405'
                else
                    log_line "Conflicto inicial, conservado en ambos: $relative"
                fi
            elif [[ "$current_left" != "$old_left" && "$current_right" == "$old_right" ]]; then
                copy_file "$left" "$right" '405 -> 502'
            elif [[ "$current_right" != "$old_right" && "$current_left" == "$old_left" ]]; then
                copy_file "$right" "$left" '502 -> 405'
            elif [[ "$current_left" != "$old_left" && "$current_right" != "$old_right" ]]; then
                local time_left time_right
                time_left="$(stat -c '%y' -- "$left")"
                time_right="$(stat -c '%y' -- "$right")"
                if [[ "$time_left" > "$time_right" ]]; then
                    copy_file "$left" "$right" '405 -> 502'
                elif [[ "$time_right" > "$time_left" ]]; then
                    copy_file "$right" "$left" '502 -> 405'
                else
                    log_line "Conflicto simultáneo, conservado en ambos: $relative"
                fi
            fi
        fi
    elif [[ -n "$current_left" ]]; then
        if [[ -n "$old_left" && -n "$old_right" && "$current_left" == "$old_left" ]]; then
            delete_file "$left" '502 -> 405'
        else
            copy_file "$left" "$right" '405 -> 502'
        fi
    elif [[ -n "$current_right" ]]; then
        if [[ -n "$old_left" && -n "$old_right" && "$current_right" == "$old_right" ]]; then
            delete_file "$right" '405 -> 502'
        else
            copy_file "$right" "$left" '502 -> 405'
        fi
    fi
}

save_state() {
    local temporary="${STATE_FILE}.tmp.$$" relative
    declare -A paths=()
    for relative in "${!CUR_405[@]}" "${!CUR_502[@]}"; do paths["$relative"]=1; done
    {
        for relative in "${!paths[@]}"; do
            printf '%s\x1f%s\x1f%s\n' "$relative" "${CUR_405[$relative]-}" "${CUR_502[$relative]-}"
        done
    } > "$temporary"
    mv -f -- "$temporary" "$STATE_FILE"
}

sync_full() {
    [[ -d "$SOURCE_405" && -d "$SOURCE_502" ]] || {
        printf 'No encuentro ambas carpetas del proyecto:\n  %s\n  %s\n' "$SOURCE_405" "$SOURCE_502" >&2
        return 2
    }
    local lock="${TMPDIR:-/tmp}/moodle-sync-$SLUG.lock"
    exec 9>"$lock"
    if ! flock -n 9; then
        log_line "Ya hay una sincronización en curso para $PROJECT."
        return 0
    fi
    log_line "Sincronizando y comparando estado de $PROJECT."
    load_state
    snapshot_tree "$SOURCE_405" 405
    snapshot_tree "$SOURCE_502" 502
    declare -A paths=()
    local relative
    for relative in "${!OLD_405[@]}" "${!OLD_502[@]}" "${!CUR_405[@]}" "${!CUR_502[@]}"; do paths["$relative"]=1; done
    for relative in "${!paths[@]}"; do sync_one_path "$relative"; done
    snapshot_tree "$SOURCE_405" 405
    snapshot_tree "$SOURCE_502" 502
    save_state
    log_line "Sincronización completada para $PROJECT."
}

sync_events() {
    local relative
    for relative in "$@"; do
        if [[ -d "$SOURCE_405/$relative" || -d "$SOURCE_502/$relative" ]]; then
            log_line "Directorio cambiado; actualizando inventario: $relative"
            sync_full
            return
        fi
    done
    local lock="${TMPDIR:-/tmp}/moodle-sync-$SLUG.lock"
    exec 9>"$lock"
    if ! flock -n 9; then return 0; fi
    load_state
    local relative left right sig_left sig_right
    for relative in "$@"; do
        [[ "$relative" == .git || "$relative" == .git/* ]] && continue
        left="$SOURCE_405/$relative"
        right="$SOURCE_502/$relative"
        sig_left="$(file_signature "$left")"
        sig_right="$(file_signature "$right")"
        if [[ -n "$sig_left" ]]; then CUR_405["$relative"]="$sig_left"; else unset 'CUR_405[$relative]'; fi
        if [[ -n "$sig_right" ]]; then CUR_502["$relative"]="$sig_right"; else unset 'CUR_502[$relative]'; fi
        log_line "Cambio detectado: $relative"
        sync_one_path "$relative"
        refresh_path "$relative"
    done
    save_state
}

watch_off() {
    watchman trigger-del "$SOURCE_405" "$TRIGGER_405" >/dev/null 2>&1 || true
    watchman trigger-del "$SOURCE_502" "$TRIGGER_502" >/dev/null 2>&1 || true
    watchman watch-del "$SOURCE_405" >/dev/null 2>&1 || true
    watchman watch-del "$SOURCE_502" >/dev/null 2>&1 || true
    log_line "Vigilancia detenida para $PROJECT."
}

watch_on() {
    command -v watchman >/dev/null 2>&1 || { printf 'No encuentro Watchman en PATH.\n' >&2; return 2; }
    sync_full
    watchman watch "$SOURCE_405"
    watchman watch "$SOURCE_502"
    watchman -- trigger "$SOURCE_405" "$TRIGGER_405" -- "$SCRIPT" sync
    watchman -- trigger "$SOURCE_502" "$TRIGGER_502" -- "$SCRIPT" sync
    log_line "Watchman activo para $PROJECT. La consola mostrará cada cambio; Ctrl+C detiene la vigilancia."
    trap 'watch_off; exit 130' INT TERM
    tail -n 0 -F "$LOG_FILE"
}

case "${1:-sync}" in
    sync)
        shift || true
        if [[ $# -gt 0 && -n "${WATCHMAN_ROOT:-}" ]]; then sync_events "$@"; else sync_full; fi
        ;;
    watch) watch_on ;;
    stop) watch_off ;;
    *) printf 'Uso: %s [sync|watch|stop]\n' "$0" >&2; exit 2 ;;
esac
