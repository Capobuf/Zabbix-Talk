#!/usr/bin/env bash
set -euo pipefail

fail() { echo "Errore: $*" >&2; exit 1; }

[[ $EUID -eq 0 && $# -eq 1 ]] || fail "uso: sudo $0 FRONTEND_ROOT"
repo=$(realpath "$(dirname "$0")/..")
frontend=$(realpath -e "$1")
[[ $repo == /opt/zabbix-talk && -f $frontend/zabbix.php && -d $frontend/modules ]] \
    || fail "percorsi del repository o frontend non validi."
link=$frontend/modules/zabbix-talk
unit=/etc/systemd/system/zabbix-talk.service
if [[ -e $link || -L $link ]]; then
    [[ -L $link && $(readlink -f "$link") == "$repo/frontend" ]] \
        || fail "link non gestito da questo checkout."
fi
if [[ -e $unit || -L $unit ]]; then
    [[ ! -L $unit ]] && head -1 "$unit" | grep -qx '# Managed by Zabbix Talk scripts/install.sh' \
        || fail "unità systemd non gestita da questo installer."
    systemctl disable --now zabbix-talk.service
    rm -- "$unit"
fi
[[ ! -L $link ]] || rm -- "$link"
systemctl daemon-reload
echo "Bootstrap rimosso. Repository, utenza, configurazione e stato conservati. In Zabbix esegui Scan directory."
