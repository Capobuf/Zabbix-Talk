Zabbix Talk
Specifica funzionale e UX • Bozza v0.10 • 26 ottobre 2026
Documento di lavoro. Le parti CONFERMATE derivano dalle richieste espresse; le PROPOSTE sono da validare; le DECISIONI APERTE saranno definite progressivamente.
1. Visione e obiettivi
Zabbix Talk è un'interfaccia operativa Telegram integrata con Zabbix, pensata per gestire ambienti di più clienti MSP con poche notifiche realmente importanti. Zabbix resta il sistema autorevole per monitoraggio, dati, trigger, dipendenze ed eventi. Telegram serve per individuare, consultare e gestire rapidamente gli incidenti.
* CONFERMATO — Usare Telegram Rich Messages con interazioni direttamente nel messaggio; evitare il formato e l'esperienza del media type Telegram standard.
* CONFERMATO — Ridurre alert fatigue, falsi positivi, ripetizioni, notifiche di recupero irrilevanti e tempeste di messaggi.
* CONFERMATO — Collegare gruppi host Zabbix a destinazioni Telegram (gruppo e topic). È possibile raggruppare switch/server per topic, dedicare un topic a un cliente o a un host, ma non esistono categorie obbligatorie. Consultare stato e informazioni degli host direttamente da Telegram; grafici non prioritari.
* CONFERMATO —