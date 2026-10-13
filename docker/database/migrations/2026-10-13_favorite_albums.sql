-- 2026-10-13 — Favoritos de álbum (plan «Favoritos de Álbum», M1).
--
-- Tabla hermana de `favorites`: un álbum se marca como favorito por usuario, independiente de sus
-- pistas. Se descartó añadir un `album_id` nullable a `favorites` (PK con nulos y migrar la tabla
-- del camino caliente). La cascada desde `albums` limpia las filas al borrar un álbum.
--
-- Idempotente (CREATE TABLE IF NOT EXISTS). En prod, aplicar ANTES de desplegar el backend que
-- usa AlbumPresenter, o get_artist/get_album/search devuelven 500.
--
-- Aplicar a una BD existente:
--   docker compose exec -T mysql sh -c 'mysql -uspoticlone_user -p"$MYSQL_PASSWORD" spoticlone_db' < este_fichero.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS favorite_albums (
    user_id INT NOT NULL,
    album_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, album_id),
    CONSTRAINT fk_favalb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_favalb_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
