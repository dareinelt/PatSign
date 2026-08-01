# Datenbankschema

Siehe `database/migrations/001_initial.sql` sowie `003_devices.sql` (Geräteverwaltung: `devices`, `device_assignments`, `device_sessions`, `device_history` – mit UUIDs, Foreign Keys und Indizes).

## ER-Diagramm (Mermaid)

```mermaid
erDiagram
  roles ||--o{ users : has
  users ||--o{ audit_logs : creates
  documents ||--o{ signatures : has
  documents ||--o{ audit_logs : logs
  devices ||--o{ device_assignments : receives
  devices ||--o{ device_sessions : runs
  devices ||--o{ device_history : logs
  device_assignments ||--o{ device_sessions : starts
  users ||--o{ device_assignments : assigns
```
