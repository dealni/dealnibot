-- Migração aditiva para instalações existentes.
-- O runner verifica o estado antes de aplicar; a transação impede schema parcial.
BEGIN IMMEDIATE;
ALTER TABLE usuarios ADD COLUMN consentiu_anuncios INTEGER NOT NULL DEFAULT 0
  CHECK (consentiu_anuncios IN (0, 1));
ALTER TABLE usuarios ADD COLUMN consentido_em TEXT NULL;
COMMIT;
