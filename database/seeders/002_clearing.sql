INSERT IGNORE INTO clearing_error_reasons (code, label) VALUES
    ('NO_CASE_NUMBER', 'Keine Fallnummer erkannt'),
    ('INVALID_CASE_NUMBER', 'Fallnummer ungültig oder unleserlich'),
    ('NO_PATIENT_MATCH', 'Keine passende Patientenmappe gefunden'),
    ('MULTIPLE_MATCHES', 'Mehrere gleichwertige Treffer'),
    ('UNKNOWN_DOCUMENT_TYPE', 'Dokumenttyp unbekannt'),
    ('LOW_CONFIDENCE', 'KI-Konfidenz unter Schwellwert'),
    ('MISSING_PATIENT_DATA', 'Name oder Geburtsdatum unvollständig'),
    ('OCR_OR_VISION_FAILED', 'Texterkennung (Vision) fehlgeschlagen'),
    ('ANALYSIS_FAILED', 'KI-Analyse fehlgeschlagen'),
    ('JSON_INVALID', 'KI lieferte ungültige Antwort');
