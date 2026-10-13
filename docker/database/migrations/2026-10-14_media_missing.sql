-- 2026-10-14 — Borrado conservando historial (plan «Borrado Conservando Historial», M1).
--
-- Columna `missing_at` en `albums` y `tracks`: un álbum/pista con `missing_at IS NOT NULL` es un
-- «fantasma» — sus ficheros ya no están en disco pero la fila se conserva, y con ella sus ids,
-- `play_history`, `favorites`, `favorite_albums` y `playlist_tracks`. Ni FKs ni cascadas cambian.
-- Se marca al borrar desde la app o al podar el escaneo; se limpia al reenganchar una pista.
--
-- Idempotente: MySQL 8 no tiene ADD COLUMN IF NOT EXISTS, así que cada ALTER solo se ejecuta si
-- information_schema dice que la columna aún no existe. En prod, aplicar ANTES de desplegar el
-- backend que lee `missing_at`, o todas esas consultas devuelven 500 (misma trampa que
-- favorite_albums).
--
-- Aplicar a una BD existente:
--   docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" spoticlone_db' < este_fichero.sql

SET NAMES utf8mb4;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'albums' AND COLUMN_NAME = 'missing_at') = 0,
    'ALTER TABLE albums ADD COLUMN missing_at TIMESTAMP NULL DEFAULT NULL AFTER folder_path,
                        ADD INDEX idx_album_missing (missing_at)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks' AND COLUMN_NAME = 'missing_at') = 0,
    'ALTER TABLE tracks ADD COLUMN missing_at TIMESTAMP NULL DEFAULT NULL AFTER mtime,
                        ADD INDEX idx_track_missing (missing_at)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
