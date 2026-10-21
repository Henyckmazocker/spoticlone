-- Revocación de sesiones/JWT: se incrementa en logout o a mano (UPDATE users SET token_version=token_version+1).
ALTER TABLE users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active;
