ALTER TABLE documents MODIFY status ENUM('imported','analyzing','analyzed','ready','signed','sent','archived','error') NOT NULL DEFAULT 'imported';
