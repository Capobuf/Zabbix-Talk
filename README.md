# Zabbix Talk

Zabbix Talk integra un modulo di configurazione nel frontend Zabbix e un bridge Telegram locale per notifiche e operazioni sugli incidenti.

**Stato:** scaffold iniziale. Non è ancora un'installazione funzionante: bridge, configurazione Telegram e notifiche saranno implementati nelle prime issue.

## Riferimenti di progetto

- [Specifiche v0.10 — sintesi temporanea; copia integrale ancora da importare](docs/Zabbix-Talk-Specifica-v0.10.md)
- [Demo UI/UX indicativa (HTML completo)](docs/Zabbix-Talk-Demo-UI-UX.html)
- [Documentazione ufficiale dei moduli Zabbix 7.4](https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module)
- [Regole di lavoro per l'agent](AGENTS.md)
- [Skill UI/UX per frontend Zabbix](.agents/skills/zabbix-frontend-ux/SKILL.md)

## Struttura

- `frontend/`: modulo PHP nativo del frontend Zabbix (scaffold presente).
- `bridge/`: servizio locale Telegram, da implementare.
- `scripts/`: installazione e aggiornamento sulla VM Zabbix, da implementare.

## Installazione e sviluppo

La VM che esegue Zabbix sarà anche l'ambiente iniziale di sviluppo. Il percorso di lavoro proposto è `/opt/zabbix-talk`; la posizione effettiva della cartella `modules` del frontend deve essere rilevata sulla VM prima di creare un link simbolico verso `/opt/zabbix-talk/frontend`. Non inserire l'intero repository nella webroot.

Per il bridge sono previsti configurazione protetta in `/etc/zabbix-talk`, stato persistente in `/var/lib/zabbix-talk` e un servizio systemd con interfaccia HTTP limitata a `127.0.0.1`. Questi percorsi non sono ancora stati creati sul server.

Vedere le issue per implementazione, verifiche in ambiente reale e criteri di accettazione.
