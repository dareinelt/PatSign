INSERT IGNORE INTO roles (id, name) VALUES
    (1, 'admin'),
    (2, 'operator'),
    (3, 'viewer');

INSERT INTO prompts(type, version, content, is_active, created_by)
SELECT 'vision', 1, 'Lies alle Informationen aus den bereitgestellten Dokumentseiten vollständig aus. Gib den reinen Text zurück.', 1, 'system'
WHERE NOT EXISTS (SELECT 1 FROM prompts WHERE type = 'vision');

INSERT INTO prompts(type, version, content, is_active, created_by)
SELECT 'analysis', 1, 'Extrahiere Dokumententyp, Fallnummer, Nachname, Vorname, Geburtsdatum. Antworte ausschließlich als JSON mit den Schlüsseln document_type, case_number, last_name, first_name, birth_date.', 1, 'system'
WHERE NOT EXISTS (SELECT 1 FROM prompts WHERE type = 'analysis');

INSERT IGNORE INTO document_types(name) VALUES
    ('Aufklaerungsbogen'),
    ('Einwilligung'),
    ('Entlassbrief'),
    ('Unbekannt');
