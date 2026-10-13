-- 2026-07-27 — Seguimiento persistente de las descargas de Soulseek/slskd.
--
-- Antes el estado de una descarga vivía solo en memoria del navegador (store Pinia) y en la lista
-- volátil de transferencias de slskd: un F5 o una purga de slskd perdía el seguimiento y la
-- importación nunca llegaba a ocurrir. Esta tabla es ahora la fuente de verdad; el backend
-- reconcilia contra slskd e importa (ver DownloadReconciler).
--
-- Aplicar a una BD existente:
--   docker compose exec -T mysql mysql -u spoticlone_user -p spoticlone_db < este_fichero.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slskd_username VARCHAR(255) NOT NULL,
    -- Ruta remota COMPLETA del directorio: la clave real. Usar solo el basename provocaba
    -- colisiones entre álbumes distintos con subcarpetas homónimas (CD1, Disc 1, Various).
    remote_dir VARCHAR(1024) NOT NULL,
    -- sha256(username|remote_dir): VARCHAR(1024) en utf8mb4 no cabe en un índice (límite 3072 B).
    remote_dir_hash CHAR(64) NOT NULL,
    -- basename(remote_dir) = carpeta que slskd crea bajo DOWNLOADS_ROOT (primera hipótesis;
    -- DownloadImporter::resolveLocalFolder la busca si no está donde se espera).
    local_folder VARCHAR(512) NOT NULL,
    target_artist VARCHAR(255) DEFAULT NULL,
    album_title VARCHAR(512) DEFAULT NULL,
    -- Nº de ficheros encolados: criterio de completitud estable aunque slskd purgue transferencias.
    expected_files INT NOT NULL DEFAULT 0,
    state ENUM('queued','downloading','complete','importing','imported','failed')
        NOT NULL DEFAULT 'queued',
    error VARCHAR(1024) DEFAULT NULL,
    imported_path VARCHAR(1024) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_download (user_id, remote_dir_hash),
    INDEX idx_downloads_state (state),
    INDEX idx_downloads_user_state (user_id, state),
    CONSTRAINT fk_downloads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
