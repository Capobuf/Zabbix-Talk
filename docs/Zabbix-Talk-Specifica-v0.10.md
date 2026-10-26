# Zabbix Talk — Specifica funzionale e UX, bozza v0.10

> **Importazione non completata.** Questo file **non è la copia integrale** della specifica v0.10 del 26 ottobre 2026. Contiene soltanto i requisiti fondamentali già consolidati, per consentire di lavorare dal repository senza aprire Google Drive. Le issue GitHub contengono il dettaglio operativo delle rispettive slice. Non considerare assenti o superati requisiti che non compaiono in questa sintesi.

## Ambito consolidato

Zabbix Talk integra un modulo di configurazione nel frontend nativo Zabbix e un bridge Telegram leggero sulla stessa VM. Zabbix resta il sistema autorevole di monitoraggio, trigger, dipendenze, eventi e Action. Telegram offre Rich Messages interattivi con consultazione e operazioni sugli incidenti. Nessuna Mini App, nessun frontend pubblicato su Internet, nessun secondo motore di monitoraggio.

- Una installazione Zabbix Talk per istanza Zabbix Server; nessuna sui proxy. Bot Telegram dedicato per installazione come scelta architetturale iniziale.
- Configurazione di bot, gruppi/topic, Media Type, destinatario tecnico e Action native gestibile nel modulo Zabbix Talk, senza creazione manuale obbligatoria in altre pagine.
- Routing a partire dai gruppi host verso `chat_id` e `message_thread_id` opzionale. Zero destinazioni: nessun invio. Una destinazione: un invio. Più destinazioni diverse: conflitto da risolvere esplicitamente, senza fallback né duplicazioni.
- Supporto a un gruppo Telegram per cliente o a un supergruppo condiviso con topic. Nessuna categoria obbligatoria e nessun topic per host creato implicitamente.
- Politica generale Telegram 24/7 iniziale, con severità e fasce orarie configurabili tramite Action native Zabbix ed eventuali eccezioni per gruppi host. Nessun secondo filtro di severità nel bridge, nessun rinvio personalizzato dei problemi nati fuori orario.
- Eventi PROBLEM e RECOVERY dei trigger nella prima versione. Per i recovery: severità High/Disaster aggiornano il messaggio originale e inviano un nuovo messaggio; le altre aggiornano soltanto l'originale. Preferire IDs numerici delle severità.
- Telegram consente ACK nativo Zabbix, snooze condiviso nella singola destinazione (30 minuti, 2 ore, 8 ore, 24 ore, personalizzato) con nuova segnalazione a scadenza solo se il problema è ancora aperto, esclusione effettiva del trigger specifico e riattivazione.
- Tutti gli attuali membri del gruppo Telegram operativo possono agire, senza ruoli supplementari; verificare membership corrente e registrare audit dell'operatore reale.
- Consultazione e ricerca di host anche sani tramite API Zabbix; informazioni e profili dipendono dagli item realmente monitorati. Priorità a uplink/porte per switch, CPU/RAM/servizi per server, nodo/VM per Proxmox, autonomia/alimentazione per UPS.
- Riepilogo aggiornabile con soli host problematici nel topic e riepilogo degli eventi ancora aperti dopo il riavvio, evitando tempeste di notifiche.
- Nessuna modifica automatica di template condivisi o Action estranee al canale Zabbix Talk. Rilevare modifiche esterne delle Action gestite prima di sovrascriverle.

## Riferimenti disponibili in questo repository

- [Demo UI/UX indicativa](Zabbix-Talk-Demo-UI-UX.html): guida a flussi e struttura, non modello da copiare esattamente.
- [Issue di sviluppo](https://github.com/Capobuf/Zabbix-Talk/issues): criteri di accettazione e dettagli delle slice verticali.
- [Istruzioni per l'agent](../AGENTS.md).

**Per gli agent:** non consultare Drive. Se un dettaglio indispensabile non compare in questo repository, segnalarlo esplicitamente anziché inventarlo. La copia integrale della v0.10 resta un'attività di documentazione non completata.
