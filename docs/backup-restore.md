# Backup- und Restore-Konzept

## Backup
- MySQL täglicher Dump
- `storage/` und `logs/` per Snapshot
- Netzwerk-Share revisionssicher spiegeln

## Restore
- MySQL Dump einspielen
- `storage/` zurückkopieren
- Integrität stichprobenartig anhand Audit-Log prüfen
