-- 2026-10-21 — Peticiones de álbumes (plan «Peticiones de Álbumes», M1).
--
-- Un usuario no admin pide un álbum de Spotify; varios usuarios que piden el mismo se suman a una
-- sola fila (spotify_album_id UNIQUE). `album_request_users` guarda quién la pide (cascada desde
-- users y desde la petición). download_id / album_id pasan a NULL si se borra la descarga o el álbum.
--
-- Idempotente (CREATE TABLE IF NOT EXISTS). Requiere `downloads` y `albums`.
--
-- Aplicar a una BD existente:
--   docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" spoticlone_db' < docker/database/migrations/2026-10-21_album_requests.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS album_requests (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    spotify_album_id VARCHAR(64)   NOT NULL UNIQUE,
    artist           VARCHAR(255)  NOT NULL,
    title            VARCHAR(255)  NOT NULL,
    year             SMALLINT      NULL,
    cover_url        VARCHAR(1024) NULL,          -- URL remota de Spotify, tal cual
    status           ENUM('pending','downloading','fulfilled','rejected') NOT NULL DEFAULT 'pending',
    reject_reason    VARCHAR(255)  NULL,
    download_id      INT NULL,                    -- última descarga lanzada desde la petición
    album_id         INT NULL,                    -- álbum de la biblioteca que la cumplió
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at      TIMESTAMP NULL,
    INDEX idx_album_requests_status (status),
    CONSTRAINT fk_albreq_download FOREIGN KEY (download_id) REFERENCES downloads(id) ON DELETE SET NULL,
    CONSTRAINT fk_albreq_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS album_request_users (
    request_id INT NOT NULL,
    user_id    INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id, user_id),
    INDEX idx_aru_user (user_id),
    CONSTRAINT fk_aru_request FOREIGN KEY (request_id) REFERENCES album_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_aru_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
