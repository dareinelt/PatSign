# Datenbankschema

Siehe `database/migrations/001_initial.sql`, `003_devices.sql` (Geräteverwaltung: `devices`, `device_assignments`, `device_sessions`, `device_history` – mit UUIDs, Foreign Keys und Indizes) sowie `004_notifications.sql` (Benachrichtigungen über abgeschlossene Hintergrund-Analysen).

## ER-Diagramm (Mermaid)

```mermaid
erDiagram
  roles ||--o{ users : has
  users ||--o{ audit_logs : creates
  documents ||--o{ signatures : has
  documents ||--o{ audit_logs : logs
  documents ||--o{ notifications : notifies
  devices ||--o{ device_assignments : receives
  devices ||--o{ device_sessions : runs
  devices ||--o{ device_history : logs
  device_assignments ||--o{ device_sessions : starts
  users ||--o{ device_assignments : assigns
```
