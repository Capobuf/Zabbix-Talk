# Zabbix Talk

Zabbix Talk integra un modulo di configurazione nel frontend Zabbix e un bridge Telegram locale per notifiche e operazioni sugli incidenti.

La slice [#1](https://github.com/Capobuf/Zabbix-Talk/issues/1) implementa il bootstrap e lo stato reale del bridge. Bot, token, notifiche, polling e Action Telegram sono fuori da questa slice.

## Riferimenti di progetto

- [Specifiche v0.10 — sintesi temporanea; copia integrale ancora da importare](docs/Zabbix-Talk-Specifica-v0.10.md)
- [Demo UI/UX indicativa (HTML completo)](docs/Zabbix-Talk-Demo-UI-UX.html)
- [Documentazione ufficiale dei moduli Zabbix 7.4](https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module)
- [Regole di lavoro per l'agent](AGENTS.md)
- [Skill UI/UX per frontend Zabbix](.agents/skills/zabbix-frontend-ux/SKILL.md)

## Struttura

- `frontend/`: modulo PHP nativo del frontend Zabbix.
- `bridge/`: health endpoint locale, Python senza dipendenze esterne.
- `scripts/`: installazione/aggiornamento idempotente e rollback sulla VM.
- `systemd/`: servizio con utenza dedicata e privilegi minimi.

## Installazione e sviluppo

La VM Zabbix è anche l'ambiente di sviluppo. Rilevamenti del 26 ottobre 2026:

| Componente | Valore rilevato |
| --- | --- |
| Zabbix server / frontend | 7.4.15 |
| PHP | 8.3.6, modulo Apache, estensione cURL disponibile |
| Python | 3.12.3, sole librerie standard |
| Web server | Apache 2.4.58, processi PHP come `www-data` |
| Frontend reale | `/usr/share/zabbix/ui`, alias Apache `/zabbix` in `/etc/zabbix/apache.conf` |
| Checkout di sviluppo | `/opt/zabbix-talk`, proprietario `super:super` |
| Link del modulo | `/usr/share/zabbix/ui/modules/zabbix-talk` → `/opt/zabbix-talk/frontend` |
| Bridge | `zabbix-talk.service`, utenza/gruppo `zabbix-talk`, shell `nologin` |
| Health | `http://127.0.0.1:8765/health` |

Accesso LAN: `http://172.16.100.7/zabbix/`. Apache configura anche `https://zabbix.nirolabel.it/zabbix/`, con certificato valido sulla VM; durante il lavoro il nome DNS non si risolve dalla VM. La topologia non richiede modifiche al codice core Zabbix né riavvii di Apache o Zabbix server.

Solo `frontend/` è collegato alla webroot. `/etc/zabbix-talk` è riservata alla configurazione (`root:zabbix-talk`, `0750`); `/var/lib/zabbix-talk` allo stato (`zabbix-talk:zabbix-talk`, `0750`). Questa slice non richiede file di configurazione, credenziali o dati persistenti; host e porta health sono fissi. Non inserire segreti nel checkout o nel frontend. L'utenza web non ha accesso alle due directory; il bridge può scrivere solo nella directory di stato e nei percorsi temporanei privati concessi da systemd. Il repository appartiene all'utente di sviluppo e `.git` ha permessi `0700`.

### Prima installazione

Il repository pubblico usa HTTPS: clone e pull non richiedono credenziali o chiavi da salvare sulla VM. Il push resta soggetto alla revisione prevista da [AGENTS.md](AGENTS.md).

```bash
sudo install -d -o super -g super -m 0755 /opt/zabbix-talk
sudo -u super git clone https://github.com/Capobuf/Zabbix-Talk.git /opt/zabbix-talk
cd /opt/zabbix-talk
# Verifica il percorso nel file Apache prima di passarlo allo script.
sudo ./scripts/install.sh /usr/share/zabbix/ui super
```

Prerequisiti: systemd, Python in `/usr/bin/python3`, PHP CLI con cURL e cURL anche nel PHP del web server, `curl`, utenza web `www-data`. Lo script è limitato al checkout `/opt/zabbix-talk` e a questa topologia Debian/Ubuntu. Richiede la directory frontend esplicita, verifica i file Zabbix, rifiuta link o unità preesistenti estranei, imposta proprietà/permessi e riavvia solo il bridge. Può essere ripetuto senza creare link, utenti o servizi duplicati e senza cancellare configurazione o stato.

Come Super Admin, apri **Administration → General → Modules**, premi **Scan directory** e abilita **Zabbix Talk**. Il link compare nel menu principale; la pagina è `zabbix.php?action=zabbix.talk.overview`. Le successive installazioni non richiedono una nuova registrazione se il modulo è già abilitato.

### Pagina e permessi

La pagina usa i componenti nativi della versione installata (`CHtmlPage`, `CTableInfo`, `CLink`, `CSpan`). Menu e controller richiedono un **Super Admin**. Zabbix applica inoltre le regole native di accesso ai moduli del ruolo; nessun permesso viene modificato dall'installer.

Ogni apertura e **Aggiorna stato** esegue dal PHP una richiesta locale con timeout massimo di un secondo, senza proxy o redirect. **Bridge raggiungibile** richiede HTTP 200 e JSON con `service=zabbix-talk`, `status=ok`; errori HTTP, risposta diversa o servizio spento producono **Bridge non raggiungibile**. Lo stato non è memorizzato né aggiornato automaticamente. Il health verifica il processo locale, non una connessione Telegram.

### Aggiornamento e sviluppo

Il symlink rende visibili le modifiche PHP del branch attivo. Non cambiare branch durante la revisione di un'altra anteprima. Per aggiornare un branch già pubblicato, dall'account `super`:

```bash
cd /opt/zabbix-talk
git status --short --branch
git pull --ff-only
sudo ./scripts/install.sh /usr/share/zabbix/ui super
```

Il branch della slice è `issue/1-bootstrap`. Usa `git pull` dopo la pubblicazione del branch e la configurazione dell'upstream, oppure su `main` quando la modifica sarà integrata. Non sovrascrivere modifiche locali. Per modifiche al solo Python basta `sudo systemctl restart zabbix-talk`; per PHP aggiorna la pagina. La VM usa il codice del checkout, senza build, container o dipendenze Python esterne.

```bash
systemctl status zabbix-talk --no-pager
sudo journalctl -u zabbix-talk -n 50 --no-pager
sudo journalctl -u zabbix-talk -f
curl --noproxy '*' --fail http://127.0.0.1:8765/health
ss -ltn 'sport = :8765'
readlink -f /usr/share/zabbix/ui/modules/zabbix-talk
```

I log di avvio/errori vanno al journal; il bridge non registra URL o header delle richieste. Per errori PHP consulta `/var/log/apache2/error.log` o `/var/log/apache2/zabbix-ssl-error.log`, in base al virtual host. Se manca il menu, verifica abilitazione del modulo e ruolo dell'utente.

Per provare il cambio reale di stato, apri la pagina, esegui `sudo systemctl stop zabbix-talk` e premi **Aggiorna stato**: deve comparire **Bridge non raggiungibile**. Esegui `sudo systemctl start zabbix-talk` e premi di nuovo **Aggiorna stato**: deve comparire **Bridge raggiungibile**. La porta 8765 non deve rispondere usando l'indirizzo LAN della VM.

### Rollback

Per annullare un aggiornamento, conserva il riferimento alla revisione precedente con `git rev-parse HEAD` prima del pull. Ferma il bridge, torna alla revisione funzionante con `git switch --detach <revisione>` (solo con checkout pulito), poi riesegui l'installer di quella revisione. Se la revisione precede questa slice, usa invece la rimozione seguente prima del cambio branch.

Per rimuovere il bootstrap, disabilita **Zabbix Talk** nella pagina Modules e poi:

```bash
cd /opt/zabbix-talk
sudo ./scripts/rollback.sh /usr/share/zabbix/ui
```

Lo script verifica di gestire il proprio link e la propria unità, ferma/disabilita il bridge e rimuove solo questi due elementi. Conserva checkout, utenza di servizio, configurazione e stato. Torna in **Modules → Scan directory** per rimuovere la registrazione nativa. Per reinstallare usa `install.sh` e registra/abilita nuovamente il modulo.

### Verifiche eseguite sulla VM

Il 26 ottobre 2026 il modulo è stato registrato e abilitato dalla pagina nativa Modules. La pagina è stata aperta in Chromium tramite l'indirizzo LAN, senza errori PHP o JavaScript osservati. Fermando il servizio e premendo **Aggiorna stato** è comparso **Bridge non raggiungibile**; riavviandolo, attendendo la risposta health e aggiornando nuovamente è tornato **Bridge raggiungibile**. Il servizio è lasciato abilitato e attivo.

Verificati inoltre: health HTTP 200 con JSON atteso, HTTP 404 su percorsi diversi, socket solo su `127.0.0.1:8765` e connessione rifiutata su `172.16.100.7:8765`; accesso senza login privo dello stato del bridge; `.git`, configurazione e stato non leggibili da `www-data`. Eseguiti rollback, reinstallazione e una seconda installazione consecutiva, mantenendo le directory di configurazione/stato e senza duplicare il modulo. Controllati sintassi PHP/Python/Bash e validità dell'unità systemd.

Le verifiche browser usano strumenti temporanei fuori dal checkout; non sono dipendenze del modulo. L'anteprima sulla VM è stata approvata prima della pubblicazione del branch, come previsto dalle regole di progetto.
