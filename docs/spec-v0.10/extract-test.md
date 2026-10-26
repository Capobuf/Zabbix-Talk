Zabbix Talk
Specifica funzionale e UX • Bozza v0.10 • 26 ottobre 2026
Documento di lavoro. Le parti CONFERMATE derivano dalle richieste espresse; le PROPOSTE sono da validare; le DECISIONI APERTE saranno definite progressivamente.
1. Visione e obiettivi
Zabbix Talk è un'interfaccia operativa Telegram integrata con Zabbix, pensata per gestire ambienti di più clienti MSP con poche notifiche realmente importanti. Zabbix resta il sistema autorevole per monitoraggio, dati, trigger, dipendenze ed eventi. Telegram serve per individuare, consultare e gestire rapidamente gli incidenti.
* CONFERMATO — Usare Telegram Rich Messages con interazioni direttamente nel messaggio; evitare il formato e l'esperienza del media type Telegram standard.
* CONFERMATO — Ridurre alert fatigue, falsi positivi, ripetizioni, notifiche di recupero irrilevanti e tempeste di messaggi.
* CONFERMATO — Collegare gruppi host Zabbix a destinazioni Telegram (gruppo e topic). È possibile raggruppare switch/server per topic, dedicare un topic a un cliente o a un host, ma non esistono categorie obbligatorie. Consultare stato e informazioni degli host direttamente da Telegram; grafici non prioritari.
* CONFERMATO — Integrare una pagina di configurazione Zabbix Talk all'interno della UI di Zabbix.
* PRINCIPIO — Non duplicare nel prodotto un motore di monitoraggio, l'inventario o un amministratore parallelo delle notifiche.
2. Preferenze funzionali già espresse
* CONFERMATO — Quando il gruppo Telegram è dedicato al cliente, non occorre ripeterne il nome in ogni messaggio. Se invece il gruppo è condiviso fra clienti, Zabbix Talk deve identificare chiaramente il cliente per evitare confusione.
* PROPOSTA — Consentire di collegare bot, gruppi e topic Telegram già esistenti; non presumere che siano già predisposti.
* CONFERMATO — Il comportamento visivo del topic deve essere configurabile. Modalità candidate: timeline di incidenti, messaggi aggiornabili per host, riepilogo fissato con approfondimenti on demand; nessuna modalità deve essere imposta a tutte le destinazioni.
* PROPOSTA — Rendere i Rich Messages sintetici e contestuali; definire in seguito dati mostrati, priorità degli avvisi e formato di data e ora.
* CONFERMATO — La gestione di problemi transitori, soglie, ritardi e durata dei trigger appartiene alla configurazione nativa Zabbix. Zabbix Talk non introduce propri timer per decidere se un evento merita una notifica.
* CONFERMATO — Consentire direttamente da Telegram di esaminare il problema e intervenire; tra le operazioni richieste ci sono snooze, esclusione effettiva del controllo Zabbix e futura riattivazione. Il dettaglio della UX dei pulsanti resta aperto.
* PROPOSTA — Sfruttare dipendenze e correlazione native Zabbix per ridurre gli avvisi a cascata (rete, UPS e sistemi dipendenti); valutare deduplicazione e recupero dopo indisponibilità Telegram.
* CONFERMATO — Tutta la configurazione necessaria alle notifiche Telegram avviene nel modulo Zabbix Talk, compr