-- 2026-10-15 — Detección de duplicados (plan «Detección de Duplicados», M2).
--
-- Columna `replace_album_id` en `downloads`: una descarga encolada con «Reemplazar» recuerda qué
-- álbum vivo de la biblioteca sustituye. El reconciliador (M3) lo convierte en fantasma antes de
-- importar y la limpia en todas las filas hermanas (`clearReplace`). NULL = descarga normal.
-- `ON DELETE SET NULL`: si el álbum se «olvida» mientras la descarga está en cola, se importa como
-- álbum nuevo en vez de fallar.
--
-- Idempotente: MySQL 8 no tiene ADD COLUMN IF NOT EXISTS, así que el ALTER solo se ejecuta si
-- information_schema dice que la columna aún no existe. En prod, aplicar ANTES de desplegar el
-- backend: el INSERT de `upsertQueued` nombra la columna, y sin ella todo `slsk_download` da 500.
--
-- Aplicar a una BD existente:
--   docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" spoticlone_db' < este_fichero.sql

SET NAMES utf8mb4;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'downloads' AND COLUMN_NAME = 'replace_album_id') = 0,
    'ALTER TABLE downloads ADD COLUMN replace_album_id INT NULL DEFAULT NULL AFTER album_title,
                           ADD CONSTRAINT fk_downloads_replace FOREIGN KEY (replace_album_id)
                               REFERENCES albums(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
