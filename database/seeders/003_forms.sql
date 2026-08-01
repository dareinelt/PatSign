-- Unterstützte Feldtypen der Formularfunktion (erweiterbar über weitere Zeilen).
INSERT IGNORE INTO form_field_types (name, label) VALUES
    ('text', 'Freitext'),
    ('textarea', 'Mehrzeiliger Text'),
    ('number', 'Zahl'),
    ('date', 'Datum'),
    ('time', 'Uhrzeit'),
    ('yesno', 'Ja/Nein'),
    ('checkbox', 'Checkbox'),
    ('radio', 'Radio Buttons'),
    ('dropdown', 'Dropdown'),
    ('multiselect', 'Mehrfachauswahl'),
    ('signature', 'Unterschriftsfeld'),
    ('initials', 'Initialen'),
    ('phone', 'Telefonnummer'),
    ('email', 'E-Mail-Adresse');
