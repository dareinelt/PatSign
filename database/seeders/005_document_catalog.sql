-- Dokumentenkatalog: Rolle "Dokumentenmanagement" und Standardkategorien.
-- Benutzer dieser Rolle verwalten ausschließlich den Dokumentenkatalog;
-- Administratoren besitzen automatisch alle Katalogberechtigungen.

INSERT IGNORE INTO roles (name) VALUES ('dokumentenmanagement');

INSERT IGNORE INTO document_template_categories (name) VALUES
    ('Aufnahme'),
    ('Datenschutz'),
    ('Einwilligung'),
    ('Behandlung'),
    ('Forschung'),
    ('Sonstiges');
