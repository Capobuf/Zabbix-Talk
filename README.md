# Zabbix Talk

Zabbix Talk integra un modulo di configurazione nel frontend Zabbix e un bridge Telegram locale per notifiche e operazioni sugli incidenti.

Le slice #1–#4 e lo stile Telegram #16 sono integrate. La slice [#5](https://github.com/Capobuf/Zabbix-Talk/issues/5), sul branch locale `issue/5-multi-group-routing`, aggiunge le associazioni multi-gruppo e la risoluzione dei conflitti per host. Il comportamento corrente è descritto in fondo; le sezioni delle slice precedenti documentano anche le rispettive prove storiche.

## Riferimenti di progetto

- [Specifiche v0.10 — sintesi temporanea; copia integrale ancora da importare](docs/Zabbix-Talk-Specifica-v0.10.md)
- [Demo UI/UX indicativa (HTML completo)](docs/Zabbix-Talk-Demo-UI-UX.html)
- [Documentazione ufficiale dei moduli Zabbix 7.4](https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module)
- [Regole di lavoro per l'agent](AGENTS.md)
- [Skill UI/UX per frontend Zabbix](.agents/skills/zabbix-frontend-ux/SKILL.md)

## Struttura

- `frontend/`: modulo PHP nativo del frontend Zabbix.
- `bridge/`: health endpoint, Webhook autenticato, long polling e controllo Telegram, Python senza dipendenze esterne.
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

Solo `frontend/` è collegato alla webroot. `/etc/zabbix-talk` è riservata alla configurazione (`root:zabbix-talk`, `0750`); `/var/lib/zabbix-talk` allo stato (`zabbix-talk:zabbix-talk`, `0750`). Host e porta health sono fissi. La configurazione Telegram (token, identità bot, destinazione, offset del polling e ultimo test) è salvata atomicamente in `/var/lib/zabbix-talk/telegram.json`, proprietario `zabbix-talk`, modo `0600`. Il token non è restituito alla UI. Non inserire segreti nel checkout o nel frontend. L'utenza web non ha accesso alle due directory; il bridge può scrivere nella directory di stato, nella directory runtime e nei percorsi temporanei privati concessi da systemd. Il frontend accede al controllo tramite `/run/zabbix-talk/control.sock`, modo `0660`, gruppo `www-data`; le operazioni di controllo non sono esposte su TCP. Il solo endpoint di notifica `POST http://127.0.0.1:8765/notify` richiede una chiave locale, conservata in `/var/lib/zabbix-talk/channel.key` (modo `0600`) e nella macro globale segreta Zabbix `{$ZABBIX_TALK_CHANNEL_KEY}`; il Media Type contiene soltanto il riferimento alla macro. Il token bot resta esclusivamente nel bridge. Le ricevute persistono in `/var/lib/zabbix-talk/notifications.sqlite3`, nella stessa directory privata. Il servizio appartiene anche al gruppo `www-data` per assegnare il socket. Il repository appartiene all'utente di sviluppo e `.git` ha permessi `0700`.

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

Il branch della slice corrente è `issue/5-multi-group-routing`, ancora locale durante la revisione. Usa `git pull` dopo la pubblicazione del branch e la configurazione dell'upstream, oppure su `main` quando la modifica sarà integrata. Non sovrascrivere modifiche locali. Per modifiche al solo Python basta `sudo systemctl restart zabbix-talk`; per PHP aggiorna la pagina. Quando cambiano unità systemd, socket o permessi, riesegui l’installer. La VM usa il codice del checkout, senza build, container o dipendenze Python esterne.

```bash
systemctl status zabbix-talk --no-pager
sudo journalctl -u zabbix-talk -n 50 --no-pager
sudo journalctl -u zabbix-talk -f
curl --noproxy '*' --fail http://127.0.0.1:8765/health
ss -ltn 'sport = :8765'
readlink -f /usr/share/zabbix/ui/modules/zabbix-talk
```

I log di avvio/errori vanno al journal; il bridge non registra URL o header delle richieste. Per errori PHP consulta `/var/log/apache2/error.log` o `/var/log/apache2/zabbix-ssl-error.log`, in base al virtual host. Se manca il menu, verifica abilitazione del modulo e ruolo dell'utente.

Per provare il cambio reale di stato, apri la pagina, esegui `sudo systemctl stop zabbix-talk` e premi **Aggiorna stato**: deve comparire **Bridge non raggiungibile**. Esegui `sudo systemctl start zabbix-talk` e premi di nuovo **Aggiorna stato**: deve comparire **Bridge raggiungibile**. La porta 8765 non deve rispondere usando l'indirizzo LAN della VM. `/notify` senza autenticazione restituisce HTTP 401; `/status`, `/channel` e `/action` non sono esposti su TCP.

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
3. Premi **Verifica collegamento e permessi**, poi **Invia notifica di prova**: il bridge chiama `sendRichMessage` con `rich_message.html` marcato **TEST TRASPORTO**. Annota il `message_id` restituito dalla pagina e controlla Telegram. Premi **Aggiorna notifica di prova**: viene chiamato solo `editMessageText` con `rich_message` sullo stesso ID. Ogni modifica incrementa la revisione visibile. Un nuovo invio crea un nuovo test, senza cancellare lo storico della chat.

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


### Notifiche PROBLEM reali (slice #3)

Nella stessa pagina **Zabbix Talk**, il punto **4. Attiva le notifiche reali** permette di scegliere un solo gruppo host, le severità numeriche abilitate e la destinazione Telegram già collegata al punto 2. Le notifiche sono 24/7. Spunta la conferma amministratore e premi **Salva e attiva notifiche**: senza conferma, Super Admin e CSRF l'operazione è rifiutata. Il gruppo include gli host secondo le condizioni native Zabbix, non un filtro aggiuntivo del bridge.

Il modulo crea Media Type **Zabbix Talk - Webhook**, Action **Zabbix Talk - PROBLEM**, utente **zabbix-talk-notify**, gruppo utenti e ruolo **Zabbix Talk - Notify**, macro globale segreta **{$ZABBIX_TALK_CHANNEL_KEY}**. L'utente tecnico ha una password casuale non conservata, frontend disabilitato, API disabilitata e sola lettura sul gruppo scelto. Il suo media è attivo 24/7 per tutte le severità; il filtro delle severità è soltanto nell'Action. L'operazione PROBLEM ha un solo passo e un solo destinatario. La slice #4 aggiunge una recovery operation nativa «Notify all involved» per il destinatario già notificato; nessuna update operation.

Gli ID e lo stato osservato delle risorse sono salvati nella configurazione nativa del modulo nel database Zabbix, insieme alle risorse nella stessa transazione. Il salvataggio concorrente è serializzato sulla riga del modulo. Il salvataggio ripetuto riutilizza gli ID. Prima di aggiornare vengono rilette le risorse: modifiche esterne bloccano la sovrascrittura e sono mostrate in pagina. Un oggetto omonimo non registrato come gestito non viene adottato. Le altre Action e gli altri canali non vengono modificati. Un errore API o di permessi annulla la transazione; nessuna Action viene attivata senza filtro gruppo e almeno una severità.

Il Webhook usa `HttpRequest` del server Zabbix e invia i soli dati necessari su loopback, con autenticazione Bearer. Nessuna chiamata Telegram proviene dal Media Type. Il bridge usa `sendRichMessage` e mostra host, problema, gravità e data/ora Zabbix; eventid e hostid rimangono interni. I testi sono escapati prima di comporre l'HTML. La chiave di canale non è restituita dal frontend, dai messaggi o dalla diagnostica del bridge. La macro di tipo Secret text non restituisce il valore tramite API/UI e Zabbix ne maschera l'audit. La configurazione del modulo conserva soltanto i metadati della macro, mai il valore. Non registrare i parametri risolti nel debug del Webhook. Il test del trasporto recupera la chiave soltanto dal socket protetto, perché il test nativo non espande le macro.

Il punto **5. Verifica trasporto ed evento reale** offre due prove distinte:

1. **Test trasporto Webhook** esegue il Media Type sul server tramite `CZabbixServer::testMediaType`, usando la sessione corrente. Invia un Rich Message **TEST TRASPORTO**, con esito effettivo e message_id. Non verifica trigger, filtro gruppo, severità o Action.
2. **Crea host di prova** crea soltanto un host isolato `Zabbix-Talk-Test-<moduleid>`, un item trapper `zabbix.talk.test` e un trigger alla severità massima abilitata, nel gruppo scelto. Attendi circa un minuto per la cache del server e premi **Genera PROBLEM di prova**: `history.push` invia il valore 1. Un valore accettato non è ancora una prova completa: premi **Aggiorna stato** dopo l'elaborazione e confronta l'eventid nativo con la ricevuta PROBLEM del bridge e il message_id effettivo in Telegram. Ripetere 1 mentre il trigger è già in PROBLEM non genera un nuovo evento.
3. **Ripristina e rimuovi host di prova** elimina quell'host con item, trigger e storico, senza modificare host operativi. Se contiene nuovi item, trigger o gruppi aggiunti esternamente, la rimozione automatica viene bloccata. Per ripetere la prova, ricrea l'host. Altre Action già applicabili al gruppo possono reagire anche all'host di prova: scegliere preferibilmente un gruppo dedicato al collaudo.

Il server deve accettare `history.push` dalla sessione amministratore per la prova completa. Gli errori per cache non aggiornata, permessi o server non raggiungibile restano visibili; non vengono convertiti in successi. Il modulo non usa né conserva un token Super Admin.

Le ultime otto ricevute mostrano separatamente test del trasporto ed eventi, con stato, errore e message_id. Le ricevute persistono dopo riavvio e un eventid già confermato non produce un secondo invio. Il Media Type ha un solo tentativo: in caso di rete interrotta o risposta ambigua il bridge conserva «invio non confermato» e non reinvia automaticamente; controllare Telegram e **Reports → Action log**. Questo evita duplicazioni senza promettere consegna garantita in caso di guasto. Il recupero/reinvio esplicito degli invii incerti non fa parte di questa slice.

Dopo il cambio del bot o del gruppo/topic Telegram, salva nuovamente il punto 4. Fino ad allora il bridge rifiuta nuovi PROBLEM per la configurazione precedente. I RECOVERY di eventi già inviati restano nella destinazione originale, come descritto per la slice #4. Il routing multiplo resta fuori scope.


Riferimenti ufficiali per il canale locale: [parametri Webhook e macro](https://www.zabbix.com/documentation/7.4/en/manual/config/notifications/media/webhook), [macro segrete](https://www.zabbix.com/documentation/7.4/en/manual/config/macros/secret_macros), [linee guida Webhook e limiti del test](https://www.zabbix.com/documentation/guidelines/en/webhooks).

#### Prove della slice #3 sulla VM

Il 27 ottobre 2026 sono stati verificati nel frontend reale:

- Setup dalla pagina del modulo, usando il gruppo isolato **Zabbix Talk - Test slice 3** (ID 25). Salvataggi ripetuti conservano Media Type 110, utente 5, gruppo utenti 16, ruolo 6 e Action 15; il setup finale include anche l'ID della macro segreta. Nessuna configurazione operativa preesistente è stata modificata.
- Test del trasporto finale riuscito: Rich Message `message_id 22` nel gruppo Telegram collegato senza topic specifico.
- PROBLEM nativo `8671`, sull'host isolato `10811`, trigger `34566`: Rich Message `message_id 23`. La precedente prova `8639` aveva prodotto un solo alert nativo inviato (alert 120), senza errori; un PROBLEM `8642` su un host esterno al gruppo selezionato non aveva prodotto alert Zabbix Talk.
- Riavvio del servizio e riconsegna autenticata dell'evento `8671`: restituito `message_id 23`, `duplicate: true`, senza nuovo invio.
- Modifica controllata del nome della sola Action gestita: rilevata e salvataggio bloccato senza sovrascriverla. Nome ripristinato al termine della verifica.
- POST senza CSRF rifiutato; `/notify` senza autenticazione restituisce 401. Token bot e chiave corrente assenti da pagina e risposta JSON di stato. Macro segreta priva del valore nella risposta API, nessuna chiave corrente nei parametri salvati del Media Type o nella configurazione del modulo, zero corrispondenze della chiave corrente nell'audit Zabbix. La chiave del collaudo iniziale è stata revocata prima del passaggio alla macro segreta.
- Aggiornamento di evento/ricevute senza navigazione, conservando un token non ancora inviato nel campo. Nessun errore JavaScript osservato. Controllate le sezioni su desktop e a 1024 px; sotto il minimo nativo resta lo scorrimento orizzontale della piattaforma.
- Host di prova interno rimosso tramite il pulsante della pagina; host e gruppo della prova esterna eliminati. Il gruppo selezionato e le risorse del setup restano pronti per la revisione. Le ricevute dei messaggi di collaudo rimangono consultabili; gli eventi degli host eliminati non restano nella prova corrente.

I nove test del bridge sono superati, inclusi topic, riconsegna dopo riavvio, escaping del testo, payload respinto, invio incerto e chiave vuota/non valida. La slice #3 è stata provata dal vivo nel gruppo attualmente collegato senza topic; il caso topic è coperto dai test del bridge e dalle prove Telegram della slice #2, non da un nuovo evento reale della slice #3.

Per ritirare questa slice, disabilitare prima **Zabbix Talk - PROBLEM** in **Alerts → Actions → Trigger actions**, quindi fermare/rimuovere il bridge o tornare alla revisione precedente. Il rollback del servizio conserva le risorse Zabbix e lo stato locale; non elimina automaticamente Action, account o macro.

### Ripristino sul messaggio originale (slice #4)

Al punto **4. Attiva le notifiche reali**, conferma e premi **Salva e attiva notifiche** per aggiornare le risorse gestite esistenti. Gli ID restano invariati e il controllo delle modifiche esterne rimane attivo. Il Media Type distingue `problem` e `recovery` tramite `{ALERT.MESSAGE}`; i dati del ripristino usano `{EVENT.RECOVERY.ID}`, `{EVENT.RECOVERY.VALUE}`, data e ora native. La recovery operation notifica chi ha ricevuto il PROBLEM; Zabbix decide quando il problema è risolto.

Il bridge conserva in SQLite eventid, hostid, identità del bot, chat/topic, message_id e contenuto originale. Al RECOVERY chiama `editMessageText` con `rich_message` sullo stesso messaggio: titolo **🟢 RISOLTO**, inizio e ripristino Zabbix, durata quando disponibile. Gli ID restano interni. Per la severità originale 4/5 (High/Disaster) invia poi un solo Rich Message **RISOLTO** nella stessa chat/topic, come risposta Telegram al messaggio originale; per 0–3 esegue solo la modifica, senza un nuovo messaggio.

Per provare il flusso dalla pagina:

1. Al punto 5 crea l’host isolato, attendi l’aggiornamento della cache Zabbix e genera il PROBLEM. Premi **Aggiorna stato** finché compare la ricevuta e controlla Telegram.
2. Premi **Genera RECOVERY di prova**. Il valore 0 va all’item trapper; attendi l’elaborazione e aggiorna lo stato. Confronta eventid PROBLEM/RECOVERY e message_id originale. Per Alta/Disastro la ricevuta mostra anche il nuovo message_id di ripristino.
3. Per verificare la sola modifica, rimuovi l’host, abilita una severità 0–3 al punto 4 e ricrea la prova. Il test usa sempre la massima severità selezionata. Al termine genera il RECOVERY prima di rimuovere l’host e ripristina le severità desiderate.

Le associazioni e la deduplicazione di entrambi gli eventi sopravvivono al riavvio. Un cambio della destinazione Telegram non sposta i vecchi messaggi: anche il messaggio aggiuntivo va nella chat/topic originale. Il bot deve restare lo stesso e poter operare nella vecchia destinazione; un cambio bot viene segnalato senza usare il nuovo bot per modificare messaggi altrui.

Le ricevute precedenti alla slice #4 restano consultabili; non contengono tutti i dati necessari al RECOVERY e non vengono ricostruite per supposizione. Per questi eventi il ripristino è rifiutato con errore visibile nell’Action log. Nessun messaggio sostitutivo se manca l’originale. In caso di messaggio eliminato o errore Telegram, la ricevuta non dichiara successo. Se l’originale è stato aggiornato ma il nuovo ripristino non è confermato, il dettaglio distingue i due esiti. Prima di ogni chiamata è salvato lo stato in corso: dopo errori o interruzioni non si ritenta automaticamente, evitando doppi invii; controlla Telegram e **Reports → Action log**.

Riferimenti ufficiali: [recovery operation](https://www.zabbix.com/documentation/7.4/en/manual/api/reference/action/object#action-recovery-operation), [macro evento e ripristino](https://www.zabbix.com/documentation/7.4/en/manual/appendix/macros/supported_by_location), [editMessageText](https://core.telegram.org/bots/api#editmessagetext).

#### Prove della slice #4 sulla VM

Il 26 ottobre 2026 (27 ottobre nell’orario del frontend) sono stati verificati dal browser nel frontend reale e tramite Bot API:

- PROBLEM Disaster `8676`, host isolato `10812`, trigger `34567` → Rich Message `25`. Dopo riavvio del bridge, RECOVERY nativo `8677` → modifica confermata dello stesso messaggio `25` e singolo nuovo ripristino `26`. Riconsegna autenticata del RECOVERY → `duplicate: true`, stessi ID, nessun nuovo invio.
- PROBLEM Media `8679`, host isolato `10814`, trigger `34569` → messaggio `27`; RECOVERY `8680` → modifica confermata di `27`, senza messaggio aggiuntivo.
- Salvataggi ripetuti riutilizzano Media Type `110`, Action `15`, utente `5`, gruppo utenti `16`, ruolo `6`, macro `4`. Una sola recovery operation nativa «Notify all involved». Il timeout Webhook è 30 secondi per consentire le due chiamate Telegram del recovery 4/5.
- Aggiornamento parziale di eventi/ricevute conserva il testo del token non ancora inviato; nessun errore JavaScript osservato. Controllata la sezione di collaudo su desktop e a 1024 px, con lo scorrimento orizzontale nativo sotto la larghezza minima Zabbix.

I 14 test del bridge sono superati: includono tutte le severità 0–5, destinazione originale dopo cambio chat/topic, deduplicazione dopo riavvio, originale eliminato, fallimento del solo messaggio aggiuntivo e migrazione delle ricevute della slice #3. Le prove reali usano il gruppo collegato senza topic; topic e cambio destinazione sono verificati con risposte API controllate. Le prove non configurano un secondo gruppo Telegram.

Gli host di collaudo sono stati rimossi dalla pagina e le severità ripristinate a 2, 3, 4, 5. Le risorse gestite restano attive per la revisione; le ricevute conservano gli esiti. La PR #14 della dipendenza #3 è ancora aperta; la slice #4 resta sul branch locale fino all’approvazione dell’anteprima.

### Stile delle notifiche Telegram — issue #16

La [issue #16](https://github.com/Capobuf/Zabbix-Talk/issues/16), sviluppata sul branch `issue/16-telegram-notification-style`, introduce il formato approvato dall’utente nel client Telegram, inclusi gli altri tipi di notifica e il RECOVERY compatto.

I **PROBLEM di tutte le severità** mostrano LED e host come titolo `h3`, descrizione completa in `blockquote` e orario nel `footer`. L’host ha maggiore rilievo tipografico del problema. I LED standard sono ⚪, 🔵, 🟡, 🟠, 🔴, 🔴 per le severità 0–5. Non compaiono etichette ridondanti di gravità, identificativi tecnici o pulsanti. Dimensioni e aspetto dei blocchi sono gestiti dal client Telegram.

Gli hostname oltre 32 caratteri sono abbreviati solo nella presentazione: primi 10 e ultimi 10 caratteri, con `-…-` al centro e rimozione dei separatori duplicati alle giunzioni. Per esempio, `SW-MILANO-PRODUZIONE-ARMADIO-NORD-ACCESSO-03` diventa `SW-MILANO-…-ACCESSO-03`. Il nome originale rimane nei dati e nelle ricevute. La larghezza e il ritorno a capo restano decisi dal client Telegram; non è garantita una sola riga.

Il footer formatta esclusivamente gli orari ricevuti da Zabbix, senza conversioni di fuso: `Inizio | 27/10 | 03:34`. Anno e secondi restano nei dati originali. Per il RECOVERY mostra `27/10 | 03:34 → 03:36`; se cambia giorno, include anche la seconda data, per esempio `26/10 | 23:59 → 27/10 | 00:01`. I formati temporali non riconosciuti sono conservati e sottoposti a escaping come gli altri testi. Il rilevamento automatico di link e menzioni resta disabilitato.

Il **RECOVERY** usa il titolo **🟢 RISOLTO**, senza hostname né segno di spunta; il footer conserva la durata quando disponibile da Zabbix. Il messaggio originale viene aggiornato. Il messaggio aggiuntivo previsto per High/Disaster usa `reply_parameters.message_id` per rispondere all’originale; le severità 0–3 restano con sola modifica. **UPDATE** usa la stessa struttura con **AGGIORNAMENTO**: è solo un rendering, il canale operativo continua a rifiutarlo. **TEST TRASPORTO** usa titolo e citazione per lo scopo della verifica, senza host o orari inventati; la modifica mostra la revisione nel footer. Action, routing e decisioni sugli eventi restano quelli esistenti.

Su richiesta dell’utente sono stati eliminati sia il set Telegram della custom emoji sia i relativi file locali; la cancellazione del set è stata confermata dall’API. Non viene utilizzato alcun effetto luminoso CSS o animato. Titolo, citazione e footer sono elementi Rich HTML nativi, non effetti della demo HTML.

Riferimenti: [Rich HTML ufficiale Telegram](https://core.telegram.org/bots/api#rich-html-style), [sendRichMessage](https://core.telegram.org/bots/api#sendrichmessage), [ReplyParameters](https://core.telegram.org/bots/api#replyparameters).

#### Prova e revisione sulla VM

Nel gruppo Telegram collegato **test-bot** sono disponibili gli esempi aggiornati con dati storici letti da Zabbix. Host, descrizione, gravità e orari sono quelli effettivi; la data è stata verificata nella pagina nativa degli eventi, nel fuso mostrato dal frontend.

| Esempio | Footer | Evento Zabbix | Messaggio Telegram |
| --- | --- | --- | --- |
| NAS-2, risolto dopo indisponibilità ICMP | 27/10 \| 03:34 → 03:36 | [8782](https://zabbix.nirolabel.it/zabbix/tr_events.php?triggerid=33638&eventid=8782), ripristino 8783 | 66 risposta al PROBLEM 59 |
| NAS-1, Faulty state of volume 3 | Inizio \| 27/10 \| 02:01 | [8735](https://zabbix.nirolabel.it/zabbix/tr_events.php?triggerid=33716&eventid=8735) | 60 |
| NAS-1, Faulty state of volume 4 | Inizio \| 12/10 \| 02:01 | [3035](https://zabbix.nirolabel.it/zabbix/tr_events.php?triggerid=33717&eventid=3035) | 61 |
| Zabbix server, utilizzo icmp pinger oltre 75%, severità Media | Inizio \| 27/10 \| 22:35 | 8906 | 62; 64 per il solo rendering UPDATE |
| Test del collegamento | Revisione \| 1 | Nessun evento | 65 |

Gli esempi storici non hanno generato eventi, eseguito Action o inserito ricevute operative. Il test 58 è stato inviato e modificato tramite il bridge in esecuzione. Il gruppo privato non offre link pubblici ai singoli messaggi; gli esempi precedenti restano nello storico della chat.

Per la revisione dal telefono:

1. Apri **test-bot** e confronta i messaggi **NAS-1**: LED accanto all’host, problema nella citazione e data senza anno nel footer.
2. Controlla **NAS-2**, con titolo **🟢 RISOLTO**, intervallo compatto e risposta al messaggio originale.
3. Confronta **Zabbix server**, **AGGIORNAMENTO** e **TEST TRASPORTO** per la gerarchia comune.

Telegram ha confermato i blocchi `heading` con dimensione 3, `blockquote`, `footer` e i testi aggiornati. Il messaggio 66 risponde al PROBLEM 59; la successiva modifica usa la freccia tipografica `→`. L’utente ha approvato la resa visiva finale e autorizzato push e merge. Superati i 18 test del bridge; il servizio è stato riavviato con il nuovo formato.

Durante la lettura dello storico MariaDB risultava fermo e Zabbix server già in arresto. È stato avviato il solo database per consentire la lettura API e delle pagine evento; successivamente MariaDB risultava nuovamente inattivo e Zabbix server ancora in arresto. Il ripristino di questi servizi resta fuori da questa rifinitura; le prove Telegram descritte sono state confermate.


### Destinazioni multiple e conflitti — slice #5

Apri **Zabbix Talk → 4. Destinazioni e notifiche reali → Gestisci associazioni, anteprima host e conflitti** (`zabbix.php?action=zabbix.talk.destinations`). Il punto 2 registra più destinazioni tramite il comando monouso inviato da un amministratore Telegram; ogni chat/topic rimane disponibile. La selezione nella panoramica riguarda prove e creazione topic, senza cambiare le associazioni salvate.

1. Associa ciascun gruppo host alla chat/topic registrata, oppure scegli **Nessuna associazione**. Sono inclusi i sottogruppi, come nelle condizioni native delle Action Zabbix.
2. Premi **Anteprima**: mostra host e appartenenze attuali. Più gruppi verso la medesima coppia chat/topic equivalgono a una destinazione unica. Per destinazioni diverse compare **Conflitto: invio bloccato**; puoi scegliere esplicitamente una delle candidate per quell'host.
3. Conferma e premi **Salva e attiva routing**. Il salvataggio ricalcola l'anteprima. Puoi salvare conflitti irrisolti: quegli host restano bloccati, gli altri possono notificare. Una scelta non più candidata non viene utilizzata.

Il setup riusa una sola Action con condizioni OR sui gruppi e OR sulle severità, combinate in AND, un solo destinatario tecnico e un solo passo. Le severità rimangono filtrate esclusivamente da Zabbix. Rimuovere tutte le associazioni disabilita l'Action conservando condizioni di gruppo: non crea un'Action attiva senza filtro. Come per ogni disabilitazione nativa, non promette l'elaborazione dei RECOVERY pendenti: completa il collaudo prima di disattivare il canale.

Prima di ogni nuovo PROBLEM, il bridge legge evento, gruppi associati e appartenenze dell'host tramite API Zabbix. Il ruolo tecnico ammette soltanto `event.get`, `hostgroup.get`, `host.get`, con lettura sui gruppi autorizzati e frontend disabilitato. Il token generato per l'utente tecnico viene rinnovato al salvataggio e conservato soltanto nel file privato del bridge; nel modulo resta l'ID del token. Nessun token Super Admin. Per consentire i RECOVERY nativi dopo una modifica delle associazioni sono conservati i precedenti diritti di lettura; gli altri gruppi non vengono concessi automaticamente. Se un host viene spostato fuori da tutti i gruppi leggibili, i permessi nativi possono impedire anche il RECOVERY.

Un cambio di appartenenza viene verificato al successivo PROBLEM, senza dipendere dall'ultima anteprima. Zero candidate produce silenzio; conflitto o API non disponibile bloccano l'invio e restituiscono un errore all'Action log. L'anteprima si ricalcola riaprendo la pagina. Eventi con più host sono rifiutati: questa slice non sceglie arbitrariamente il primo host del trigger. I nomi dei gruppi associati vengono riletti, evitando routing basato su vecchi prefissi dopo una rinomina. La revisione del routing nel Media Type e nel bridge deve coincidere: una sincronizzazione incompleta blocca i nuovi PROBLEM; correggi il bridge e salva nuovamente. I messaggi già confermati restano deduplicati e i RECOVERY usano chat/topic/message_id originali.

Per creare un topic, seleziona nella panoramica una destinazione appartenente a un forum, indica il nome e premi **Crea topic nel gruppo selezionato**. Richiede bot amministratore con `can_manage_topics`; un errore resta visibile, senza successo simulato o retry automatico. Per un topic esistente usa il comando dal topic: non viene tentata alcuna enumerazione dei topic.

Le risorse modificate esternamente continuano a bloccare il salvataggio. Anche l'ereditarietà dei permessi applicata da Zabbix alla creazione di sottogruppi può cambiare il gruppo utenti gestito e richiedere il ripristino esplicito della configurazione osservata. Per nuovi sottogruppi controlla i diritti reali e salva nuovamente; non sono ampliati dal bridge durante un invio.

#### Prove sulla VM del 27 ottobre 2026

- Pagina reale aperta e salvata via HTTPS e CSRF nativo. Riutilizzati Media Type 110, Action 15, utente 5, gruppo utenti 16, ruolo 6, macro 4. Il token di lettura ha interrogato realmente le appartenenze dell'host isolato 10816.
- PROBLEM nativo 9382 → messaggio 67; RECOVERY 9383 → modifica del 67 e risposta 68. La risposta cita il messaggio originale già aggiornato a RISOLTO: la specifica prescrive aggiornamento più nuovo messaggio per severità 4/5, mentre la citazione è una scelta di presentazione della #16.
- Host isolato appartenente ai gruppi 25 e 27 (sottogruppo), entrambi verso `test-bot`: PROBLEM 9386 → un solo alert Zabbix Talk 157, confermato senza errori, e un solo messaggio 69. RECOVERY 9387 → modifica del 69 e risposta 70. Al termine ripristinata l'appartenenza originale per riusare i pulsanti del collaudo.
- Rimozione completa delle associazioni → confermata Action disabilitata dal salvataggio; poi ripristinate le due associazioni di prova. Aggiornamento parziale della panoramica riuscito. Verificata la pagina su desktop e a 1024 px con i componenti nativi.
- Superati 22 test del bridge: includono destinazioni differenti, conflitto dopo cambio di appartenenza, scelta esplicita/stale, sottogruppi, silenzio senza associazioni, revisione obsoleta, errore API, evento multi-host, persistenza e permessi di creazione topic. Sintassi PHP verificata sui file modificati.

La prova live usa un solo gruppo Telegram senza topic. Due chat/topic differenti, conflitti fra destinazioni e creazione di topic sono verificati con risposte API controllate, non con un secondo cliente reale. L'host isolato rimane risolto e disponibile per la revisione; i due gruppi di collaudo restano associati. Nessun push o PR prima dell'approvazione dell'anteprima.

Riferimenti: [condizioni e sottogruppi nelle Action](https://www.zabbix.com/documentation/7.4/en/manual/config/notifications/action/conditions), [host.get](https://www.zabbix.com/documentation/7.4/en/manual/api/reference/host/get), [token.generate](https://www.zabbix.com/documentation/7.4/en/manual/api/reference/token/generate), [createForumTopic](https://core.telegram.org/bots/api#createforumtopic).
