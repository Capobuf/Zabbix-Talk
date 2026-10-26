# Zabbix Talk

Zabbix Talk integra un modulo di configurazione nel frontend Zabbix e un bridge Telegram locale per notifiche e operazioni sugli incidenti.

Il bootstrap [#1](https://github.com/Capobuf/Zabbix-Talk/issues/1) è integrato. Il branch della slice [#2](https://github.com/Capobuf/Zabbix-Talk/issues/2) aggiunge bot Telegram, collegamento gruppo/topic e invio/modifica di un Rich Message di prova. Notifiche di eventi Zabbix e Action restano fuori da questa slice.

## Riferimenti di progetto

- [Specifiche v0.10 — sintesi temporanea; copia integrale ancora da importare](docs/Zabbix-Talk-Specifica-v0.10.md)
- [Demo UI/UX indicativa (HTML completo)](docs/Zabbix-Talk-Demo-UI-UX.html)
- [Documentazione ufficiale dei moduli Zabbix 7.4](https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module)
- [Regole di lavoro per l'agent](AGENTS.md)
- [Skill UI/UX per frontend Zabbix](.agents/skills/zabbix-frontend-ux/SKILL.md)

## Struttura

- `frontend/`: modulo PHP nativo del frontend Zabbix.
- `bridge/`: health endpoint, long polling e controllo Telegram, Python senza dipendenze esterne.
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

Solo `frontend/` è collegato alla webroot. `/etc/zabbix-talk` è riservata alla configurazione (`root:zabbix-talk`, `0750`); `/var/lib/zabbix-talk` allo stato (`zabbix-talk:zabbix-talk`, `0750`). Host e porta health sono fissi. La configurazione Telegram (token, identità bot, destinazione, offset del polling e ultimo test) è salvata atomicamente in `/var/lib/zabbix-talk/telegram.json`, proprietario `zabbix-talk`, modo `0600`. Il token non è restituito alla UI. Non inserire segreti nel checkout o nel frontend. L'utenza web non ha accesso alle due directory; il bridge può scrivere nella directory di stato, nella directory runtime e nei percorsi temporanei privati concessi da systemd. Il frontend accede al controllo tramite `/run/zabbix-talk/control.sock`, modo `0660`, gruppo `www-data`; le operazioni di controllo non sono esposte su TCP. Il servizio appartiene anche al gruppo `www-data` per assegnare il socket. Il repository appartiene all'utente di sviluppo e `.git` ha permessi `0700`.

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

Ogni apertura e **Aggiorna stato** legge lo stato dal socket locale con timeout di due secondi. **Bridge raggiungibile** conferma il canale locale, non la connessione Telegram. La pagina mostra separatamente identità verificata con `getMe`, ultima verifica, ricezione long polling ed eventuali errori. Il health HTTP verifica soltanto il processo locale. I pulsanti **Aggiorna stato** e **Ho inviato il comando: aggiorna collegamento** leggono lo stato tramite la route autenticata `zabbix.talk.status` e aggiornano i dati e i pulsanti nella pagina senza navigazione, conservando un eventuale token ancora in corso di inserimento. L’aggiornamento è manuale. Le modifiche richiedono POST, CSRF nativo e Super Admin; il token non è conservato nei dati di ripopolamento dei form.

### Aggiornamento e sviluppo

Il symlink rende visibili le modifiche PHP del branch attivo. Non cambiare branch durante la revisione di un'altra anteprima. Per aggiornare un branch già pubblicato, dall'account `super`:

```bash
cd /opt/zabbix-talk
git status --short --branch
git pull --ff-only
sudo ./scripts/install.sh /usr/share/zabbix/ui super
```

Il branch della slice corrente è `issue/2-telegram-connection`. Usa `git pull` dopo la pubblicazione del branch e la configurazione dell'upstream, oppure su `main` quando la modifica sarà integrata. Non sovrascrivere modifiche locali. Per modifiche al solo Python basta `sudo systemctl restart zabbix-talk`; per PHP aggiorna la pagina. Quando cambiano unità systemd, socket o permessi, riesegui l’installer. La VM usa il codice del checkout, senza build, container o dipendenze Python esterne.

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


### Collegamento Telegram e prova Rich Message (slice #2)

1. Apri **Zabbix Talk** come Super Admin tramite HTTPS (il virtual host configurato è `https://zabbix.nirolabel.it/zabbix/`; richiede risoluzione del nome dal client). Inserisci il token di un bot dedicato e premi **Salva e verifica bot**. Il campo torna vuoto anche in caso di errore. Un token errato non sostituisce la configurazione funzionante. Cambiare bot azzera destinazione e test; salvare lo stesso token li conserva.
2. Aggiungi il bot a un gruppo esistente e consentigli di inviare messaggi. Nel passaggio **2. Collega il gruppo o il topic**, premi **Genera comando di collegamento**, poi **Copia comando**. La pagina conferma la copia tramite il controllo clipboard nativo Zabbix. Incolla e invia esattamente il comando, compreso `@username`, nel gruppo o nel topic esistente scelto, dal tuo account di amministratore Telegram. Il codice è monouso, scade dopo dieci minuti e viene invalidato al riavvio. Poi premi **Ho inviato il comando: aggiorna collegamento**: lo stato cambia senza ricaricare la pagina. L'ID del topic viene ricavato dal messaggio; la UI mostra l'ID effettivo senza inventare un nome o enumerare topic. Per un gruppo senza topic, il thread è assente. La slice conserva una destinazione per volta; collegarne una diversa azzera l'ultimo test.
3. Premi **Verifica collegamento e permessi**, poi **Invia notifica di prova**: il bridge chiama `sendRichMessage` con `rich_message.html` marcato **TEST Zabbix Talk**. Annota il `message_id` restituito dalla pagina e controlla Telegram. Premi **Aggiorna notifica di prova**: viene chiamato solo `editMessageText` con `rich_message` sullo stesso ID. Ogni modifica incrementa la revisione visibile. Un nuovo invio crea un nuovo test, senza cancellare lo storico della chat.

Per verificare entrambi i casi, collega prima un gruppo senza topic, esegui invio/modifica, poi collega un topic esistente e ripeti. Eliminando manualmente l'ultimo test in Telegram, **Aggiorna notifica di prova** deve mostrare l'errore API reale senza inviare un messaggio sostitutivo. Non servono Media Type, utenti tecnici o Action Zabbix.

Il bot necessita di membership e `can_send_messages` per Rich Messages di solo testo; non richiede permessi per creare/gestire topic o modificare messaggi altrui. Il comando di registrazione richiede l'identità personale di un amministratore del gruppo; un amministratore anonimo viene rifiutato. Telegram garantisce `getChatMember` per altri utenti quando il bot è amministratore: se il controllo dell'operatore non riesce con un bot membro, la UI mostra l'errore; è possibile rendere il bot amministratore senza assegnare ulteriori capacità non necessarie. Topic chiusi, rimossi o non accessibili producono l'errore effettivo durante il test.

Il servizio mantiene un solo thread long polling e un lock di processo. Offset e ultimo test sopravvivono al riavvio. Non utilizzare lo stesso token con altri poller: gli errori 409 sono visibili. Un webhook preesistente blocca il collegamento e non viene rimosso automaticamente. I rate limit 429 mostrano `retry_after` e sospendono le richieste per quel tempo. Nessun reinvio automatico dei test: se rete o risposta si interrompono dopo l'invio, controlla Telegram prima di riprovare. Le risposte e gli errori non includono token; URL, header e corpi delle richieste non vengono registrati.

Riferimenti API ufficiali: [sendRichMessage](https://core.telegram.org/bots/api#sendrichmessage), [InputRichMessage](https://core.telegram.org/bots/api#inputrichmessage), [editMessageText](https://core.telegram.org/bots/api#editmessagetext), [getUpdates](https://core.telegram.org/bots/api#getupdates), [getChatMember](https://core.telegram.org/bots/api#getchatmember), [ChatPermissions](https://core.telegram.org/bots/api#chatpermissions).

Verifica mirata del bridge: `python3 -m unittest discover -s tests -v`. Questi test usano risposte Telegram controllate e verificano topic/gruppo, ID invariato dopo riavvio, errore su messaggio eliminato senza secondo invio, comando scaduto/non autorizzato, mantenimento della configurazione con token respinto e attesa del rate limit. Non sostituiscono la prova con il bot reale.


#### Prove della slice #2 sulla VM

Il 26 ottobre 2026 la pagina è stata aperta nel frontend Zabbix effettivo, con login Super Admin e HTTPS. Verificati POST con CSRF nativo, rifiuto del POST senza CSRF, assenza dei controlli per accessi anonimi e campo token vuoto dopo il salvataggio. Il socket di controllo è accessibile a `www-data` e nega l'accesso a un utente locale estraneo; `/status` su TCP restituisce 404. Le pagine conservano il layout nativo Zabbix, incluso lo scorrimento orizzontale alle larghezze inferiori al minimo della piattaforma.

Con il bot reale configurato dall'amministratore, registrato un topic esistente (`message_thread_id 223`): invio Rich Message riuscito, `message_id 351`; modifica riuscita sullo stesso `message_id`, revisione 1. Nessun errore JavaScript osservato nel percorso. Il token non compare nella risposta di stato del bridge o nel campo della pagina.

Ripetuta la prova con un gruppo reale senza topic: thread assente, invio e modifica sullo stesso `message_id 19`, revisione 1.

Riavviato il servizio reale e ripetuta la modifica del test nel gruppo senza topic: `message_id 19` conservato, revisione 2. I tre passaggi della UI sono numerati. Verificati nel browser aggiornamento manuale senza navigazione (anche conservando un token non ancora inviato), copia esatta del comando con feedback e pulsante di aggiornamento accanto al comando, senza errori JavaScript osservati.

Il nuovo aggiornamento parziale è stato verificato anche fermando e riavviando il servizio reale: stato e disponibilità dei test si aggiornano senza navigazione. Verificato inoltre il rifiuto dell’accesso anonimo alla route JSON.
