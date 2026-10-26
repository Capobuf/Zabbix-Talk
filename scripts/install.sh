#!/usr/bin/env bash
set -euo pipefail

fail() { echo "Errore: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "eseguire con sudo."
[[ $# -eq 2 ]] || fail "uso: $0 FRONTEND_ROOT UTENTE_SVILUPPO"
repo=$(realpath "$(dirname "$0")/..")
frontend=$(realpath -e "$1")
owner=$2
[[ $repo == /opt/zabbix-talk ]] || fail "il checkout deve essere in /opt/zabbix-talk."
[[ $(id -u "$owner") -ne 0 && $owner != zabbix-talk ]] || fail "specificare un utente di sviluppo non privilegiato."
[[ -f $frontend/zabbix.php && -f $frontend/include/defines.inc.php && -d $frontend/modules ]] \
    || fail "specificare la directory reale del frontend Zabbix."
case "$repo/" in "$frontend/"*|/var/www/*) fail "il repository deve essere fuori dalla webroot.";; esac
[[ -f $repo/.git/HEAD ]] || fail "checkout Git non trovato."
for command in php python3 systemctl runuser curl; do
    command -v "$command" >/dev/null || fail "comando richiesto: $command"
done
php -r 'exit(extension_loaded("curl") ? 0 : 1);' || fail "estensione PHP cURL richiesta."
[[ -x /usr/bin/python3 ]] || fail "Python 3 richiesto in /usr/bin/python3."

link=$frontend/modules/zabbix-talk
if [[ -e $link || -L $link ]]; then
    [[ -L $link && $(readlink -f "$link") == "$repo/frontend" ]] \
        || fail "$link esiste già e non punta a questo frontend; nessuna sostituzione automatica."
fi
unit=/etc/systemd/system/zabbix-talk.service
if [[ -e $unit || -L $unit ]]; then
    [[ ! -L $unit ]] && head -1 "$unit" | grep -qx '# Managed by Zabbix Talk scripts/install.sh' \
        || fail "unità systemd preesistente non gestita da questo installer."
fi
for file in "$repo/frontend/Module.php" "$repo"/frontend/actions/*.php "$repo"/frontend/includes/*.php "$repo"/frontend/views/*.php; do
    php -l "$file"
done
python3 - "$repo" <<'PY'
import ast
import json
import pathlib
import sys
root = pathlib.Path(sys.argv[1])
ast.parse((root / 'bridge/server.py').read_text())
json.loads((root / 'frontend/manifest.json').read_text())
PY

if ! getent passwd zabbix-talk >/dev/null; then
    useradd --system --user-group --home-dir /var/lib/zabbix-talk --no-create-home --shell /usr/sbin/nologin zabbix-talk
fi
[[ $(id -u zabbix-talk) -ne 0 && $(getent passwd zabbix-talk | cut -d: -f7) == /usr/sbin/nologin ]] \
    || fail "l'utenza zabbix-talk esistente deve essere non privilegiata e senza login."
getent group zabbix-talk >/dev/null || fail "gruppo zabbix-talk mancante."
chown -R "$owner:$(id -gn "$owner")" "$repo"
chmod 0755 "$repo" "$repo/bridge" "$repo/frontend" "$repo/frontend/actions" "$repo/frontend/views" "$repo/frontend/includes"
chmod 0644 "$repo/bridge/server.py" "$repo/frontend/manifest.json" \
    "$repo/frontend/Module.php" "$repo"/frontend/actions/*.php "$repo"/frontend/includes/*.php "$repo"/frontend/views/*.php
chmod 0700 "$repo/.git"
install -d -o root -g zabbix-talk -m 0750 /etc/zabbix-talk
install -d -o zabbix-talk -g zabbix-talk -m 0750 /var/lib/zabbix-talk
runuser -u www-data -- test -r "$repo/frontend/manifest.json" \
    || fail "www-data non può leggere il modulo."
runuser -u zabbix-talk -- test -r "$repo/bridge/server.py" \
    || fail "l'utenza di servizio non può leggere il bridge."
[[ -L $link ]] || ln -s "$repo/frontend" "$link"
install -o root -g root -m 0644 "$repo/systemd/zabbix-talk.service" "$unit"
systemctl daemon-reload
systemctl enable zabbix-talk.service
systemctl restart zabbix-talk.service
if ! curl --noproxy '*' --fail --silent --show-error --retry 5 --retry-connrefused --retry-delay 1 \
    --max-time 2 http://127.0.0.1:8765/health; then
    journalctl -u zabbix-talk.service -n 20 --no-pager
    fail "bridge non disponibile; consultare il README per debug e rollback."
fi
runuser -u www-data -- curl --unix-socket /run/zabbix-talk/control.sock --noproxy '*' \
    --fail --silent --output /dev/null http://localhost/status \
    || fail "www-data non può raggiungere il socket di controllo."
systemctl is-active zabbix-talk.service
echo "Installazione aggiornata. In Zabbix: Administration → General → Modules → Scan directory; abilita Zabbix Talk."
