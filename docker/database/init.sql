-- spoticlone schema. Runs once on first MySQL init (docker-entrypoint-initdb.d).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================================
-- USERS (Google OAuth) — columns match UserDataMapper (do not trim).
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    picture VARCHAR(1024) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL,
    preferences JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    lastfm_username VARCHAR(255) DEFAULT NULL,
    INDEX idx_users_google_id (google_id),
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- ARTISTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS artists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(512) NOT NULL,
    sort_name VARCHAR(512) DEFAULT NULL,
    image_url VARCHAR(1024) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_artist_name (name),
    INDEX idx_artist_sort (sort_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- ALBUMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    artist_id INT NOT NULL,
    title VARCHAR(512) NOT NULL,
    year SMALLINT DEFAULT NULL,
    cover_path VARCHAR(1024) DEFAULT NULL,   -- absolute path to a cover image (folder or extracted)
    cover_url VARCHAR(1024) DEFAULT NULL,    -- remote cover (enrichment)
    folder_path VARCHAR(1024) NOT NULL,
    missing_at TIMESTAMP NULL DEFAULT NULL,  -- ghost: files gone, row kept (history survives)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_album_artist (artist_id),
    INDEX idx_album_missing (missing_at),
    UNIQUE KEY uq_album (artist_id, title),
    CONSTRAINT fk_albums_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- TRACKS — file_path is the scanner's idempotency key (UNIQUE) + mtime for change detection.
-- ============================================================================
CREATE TABLE IF NOT EXISTS tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    artist_id INT NOT NULL,
    title VARCHAR(512) NOT NULL,
    track_no SMALLINT DEFAULT NULL,
    disc_no SMALLINT DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_format VARCHAR(16) DEFAULT NULL,
    file_size BIGINT DEFAULT NULL,
    bitrate INT DEFAULT NULL,
    sample_rate INT DEFAULT NULL,
    mtime BIGINT DEFAULT NULL,
    missing_at TIMESTAMP NULL DEFAULT NULL,  -- ghost: file gone, row kept (history survives)
    genre VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_track_file (file_path(255)),
    INDEX idx_track_album (album_id),
    INDEX idx_track_artist (artist_id),
    INDEX idx_track_title (title(191)),
    INDEX idx_track_missing (missing_at),
    CONSTRAINT fk_tracks_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    CONSTRAINT fk_tracks_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- PLAYLISTS (per user)
-- ============================================================================
CREATE TABLE IF NOT EXISTS playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description VARCHAR(1024) DEFAULT NULL,
    is_public TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_playlist_user (user_id),
    CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS playlist_tracks (
    playlist_id INT NOT NULL,
    track_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (playlist_id, track_id),
    INDEX idx_pt_playlist_pos (playlist_id, position),
    CONSTRAINT fk_pt_playlist FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- FAVORITES (per user / track)
-- ============================================================================
CREATE TABLE IF NOT EXISTS favorites (
    user_id INT NOT NULL,
    track_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, track_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- FAVORITE ALBUMS (per user / album) — independent of track favorites.
-- ============================================================================
CREATE TABLE IF NOT EXISTS favorite_albums (
    user_id INT NOT NULL,
    album_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, album_id),
    CONSTRAINT fk_favalb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_favalb_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- PLAY HISTORY — feeds the play count / "most played" / "recently played".
-- ============================================================================
CREATE TABLE IF NOT EXISTS play_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    track_id INT NOT NULL,
    played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ph_user_time (user_id, played_at),
    INDEX idx_ph_user_track (user_id, track_id),
    CONSTRAINT fk_ph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ph_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SCAN RUNS — bookkeeping for the library scanner.
-- ============================================================================
CREATE TABLE IF NOT EXISTS scan_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    files_seen INT DEFAULT 0,
    tracks_added INT DEFAULT 0,
    tracks_updated INT DEFAULT 0,
    errors INT DEFAULT 0,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    message VARCHAR(1024) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- DOWNLOADS — seguimiento persistente de las descargas de Soulseek/slskd.
-- Fuente de verdad del progreso y de la importación (ver DownloadReconciler);
-- slskd solo aporta el estado de las transferencias, que es volátil.
-- ============================================================================
CREATE TABLE IF NOT EXISTS downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slskd_username VARCHAR(255) NOT NULL,
    -- Ruta remota COMPLETA del directorio: la clave real (el basename colisiona entre álbumes
    -- distintos con subcarpetas homónimas: CD1, Disc 1, Various).
    remote_dir VARCHAR(1024) NOT NULL,
    -- sha256(username|remote_dir): VARCHAR(1024) en utf8mb4 no cabe en un índice (límite 3072 B).
    remote_dir_hash CHAR(64) NOT NULL,
    -- basename(remote_dir) = carpeta que slskd crea bajo DOWNLOADS_ROOT.
    local_folder VARCHAR(512) NOT NULL,
    target_artist VARCHAR(255) DEFAULT NULL,
    album_title VARCHAR(512) DEFAULT NULL,
    -- Álbum vivo que esta descarga sustituye («Reemplazar» en Discover); el reconciliador lo
    -- convierte en fantasma antes de importar. NULL = descarga normal.
    replace_album_id INT NULL DEFAULT NULL,
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
    CONSTRAINT fk_downloads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_downloads_replace FOREIGN KEY (replace_album_id) REFERENCES albums(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
