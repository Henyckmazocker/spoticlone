# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Documentación en español por coherencia con la convención del proyecto (igual que statCoin /
> libraryVue). Nombres de clases, comandos y términos técnicos se dejan tal cual.

## Qué es spoticlone

App web + móvil tipo **Spotify** para hacer **streaming de una biblioteca de música local**
(`/media/david/Elements2/01Media/music`, estructura `Artista/Álbum/pistas`, sobre todo FLAC).
Stack idéntico a `statCoin`/`libraryVue`: **Docker · PHP 8.2 (clean architecture basada en
acciones) · Vue 3 (Vue CLI) + Pinia · Capacitor · MySQL 8**.

## 🧠 Brain

Spec y contexto del proyecto en el segundo cerebro:
`/home/david/Documents/workspace/Brain/03 - Proyectos/Spoticlone.md`. Léela para el panorama
(decisiones, estado, roadmap); este `CLAUDE.md` cubre el detalle técnico del repo.
(Ojo: existe una página distinta `SpotyNear.md` que es **otro** proyecto — app social de música
por proximidad —; no la confundas con spoticlone.)

## Comandos

Todo corre en Docker; no se ejecuta PHP/Node en el host.

```bash
cp .env.example .env        # ajusta MUSIC_LIBRARY_PATH, JWT_SECRET, GOOGLE_CLIENT_ID
docker compose up --build

# URLs
#   Frontend  http://localhost:8086
#   Backend   http://localhost:8892/index.php   (endpoint ÚNICO; la acción va en el body)
#   Stream    http://localhost:8892/stream.php?token=...   (binario, fuera del router)
#   Cover     http://localhost:8892/cover.php?token=...
#   MySQL     localhost:3311  (db spoticlone_db / user spoticlone_user)
```

**Producción** (frontend nginx estático + backend Apache `--no-dev` + MySQL propio, publicado por
el túnel Cloudflare en `spoticlone.dcahomelab.com`):
```bash
docker compose -p spoticlone_prod -f docker-compose.prod.yml up -d --build
docker network connect spoticlone_prod cloudflared      # que el túnel resuelva el frontend
# Dashboard Cloudflare → Public Hostname: spoticlone.dcahomelab.com -> http://spoticlone-frontend-prod:80
# Prueba local: http://localhost:8087  (puertos prod: spoticlone 8087, statCoin 8084, libraryVue 8082)
```
Prod usa BD propia `spoticlone_db_prod` (arranca vacía → ejecutar "Escanear" una vez desplegado).
nginx proxya `/api`, `/stream.php` y `/cover.php` al backend; `/stream.php` va sin buffering y
pasa el header `Range` (206/seek). Secretos desde el `.env` raíz (`JWT_SECRET` fuerte, distinto
del de dev: firma también las URLs de stream/cover).

**Tests (PHPUnit, dentro del contenedor backend):**
```bash
docker compose exec backend composer test
docker compose exec backend vendor/bin/phpunit --filter SignedUrlServiceTest
```

**Login en dev sin Google** (JWT para un usuario existente de `users`):
```bash
docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" spoticlone_db -e "SELECT id, email FROM users"'
# elige un id de la lista y ponlo en USER_ID:
USER_ID=2
docker compose exec -T -e USER_ID=$USER_ID backend php -r 'require "/var/www/html/vendor/autoload.php";
  $_ENV["JWT_SECRET"]=getenv("JWT_SECRET");
  echo (new App\Infrastructure\Auth\JWTService())->generate(["user_id"=>(int) getenv("USER_ID")]);'
```
Pégalo en "Acceso de desarrollo" del login, o úsalo como `Authorization: Bearer` con curl. La fila en
`users` la crea el primer login con Google. **Registro abierto con tope**: cualquier cuenta de Google
puede entrar hasta `MAX_USERS` (por defecto 10; `0` = sin límite); a partir de ahí las cuentas **nuevas**
reciben 403 (`UserLimitReachedException` en `LoginUserUseCase`) y las existentes siguen entrando. **La sesión web** (cookie `SPOTICLONE_SESSION`, con CSRF)
**solo la crea el login con Google**: con JWT el CSRF se salta (`auth_method=jwt`).

## Arquitectura (igual patrón que statCoin — leer eso primero ayuda)

### Backend: endpoint único + router de acciones + middleware declarativo
- **Una sola URL** (`backend/public/index.php` → `bootstrap.php` → `App\Application::run()`).
  Cliente manda `POST` JSON `{ "action": "...", ...payload }`. No hay rutas REST.
- **`config/routes.php`** = mapa maestro (acción → `[Controller, método]` + pila de middleware).
  **`ActionRouter`** ejecuta la pila y despacha con un `match($action)`. **Añadir un endpoint =
  tocar `routes.php` Y el `match`/`getController` de `ActionRouter` Y el método del controller.**
- Middleware: `LoggingMiddleware`, `AuthenticationMiddleware` (inyecta `user_id` por sesión o
  JWT), `CSRFMiddleware` (se salta con `auth_method=jwt`), `ValidationMiddleware`.
- Controllers extienden `BaseController` y devuelven `successResponse()/errorResponse()`.

### El streaming NO pasa por el router (lo nuevo respecto a statCoin)
- El `<audio>`/`<img>` del navegador no puede mandar `Authorization`, así que los binarios se
  sirven por **`public/stream.php`** y **`public/cover.php`**, autenticados por **URL firmada**.
- **`SignedUrlService`** (HMAC con `JWT_SECRET`, sin estado en BD) emite tokens cortos
  `{k,id,uid,exp}`. `get_stream_url` y `TrackPresenter` devuelven rutas `/stream.php?token=...`.
- **URLs para terceros** (`issue_external_urls`, sesión+CSRF o JWT): `{track_ids: [..≤200], ttl?}` →
  URLs **absolutas** `PUBLIC_BASE_URL/stream.php?token=…` con `kind` `'ext'`, servidas por el mismo
  `stream.php` (acepta `k ∈ {stream, ext}`). Todo o nada (404 con `missing`), `ttl` recortado a
  `EXTERNAL_URL_MAX_TTL` (86400). Sin revocación: rotar `JWT_SECRET` invalida todo.
  `PUBLIC_BASE_URL` **no va en `.env`** (dev y prod comparten ese fichero): dev la fija en literal
  (`http://localhost:8892`) y prod usa el dominio por defecto en `docker-compose.prod.yml`.
- **`stream.php`** implementa **HTTP Range** (206 Partial Content, `Accept-Ranges: bytes`) y
  emite por chunks (no `readfile()` del FLAC entero). Valida que el archivo esté dentro de
  `MUSIC_ROOT` (anti path-traversal) además del token.
- Estos endpoints usan **`bootstrap_min.php`** (autoloader + .env, sin sesión ni contenedor).

### Escaneo de la biblioteca — el corazón del producto
`src/Domain/Services/LibraryScanner::scan()` recorre `MUSIC_ROOT`, lee tags con **getID3**
(`james-heinrich/getid3`), y hace upsert idempotente de `artists/albums/tracks`. Clave de
idempotencia: `tracks.file_path` (UNIQUE) + `mtime` (re-escanear no reprocesa lo no cambiado).
Carátula por carpeta de álbum (`cover.jpg`...) o imagen embebida volcada a `storage/covers/`.
Ignora `.afpk`, `*_spectrogram.png`, etc. Registra cada corrida en `scan_runs`.

**Fantasmas (`missing_at` en `albums`/`tracks`).** `delete_album`/`delete_artist`
(`MediaDeletionService`) borran los ficheros del disco pero **no las filas**: marcan `missing_at`, así
que historial, favoritos y playlists sobreviven. Solo `forget_album` (únicamente sobre fantasmas)
borra filas con cascada. Al escanear, un fichero sin fila se **reengancha** a la pista fantasma (álbum
por `Normalize::name` de artista+título; pista por `(disc_no, track_no)` o título) y conserva su id. El
escaneo **completo** poda: marca como fantasma lo que no está en disco, salvo que falte >20 %
(`PRUNE_MAX_RATIO`) o no vea ficheros (¿disco sin montar?). Los listados de descubrimiento (Home,
Search, radio, «para ti») excluyen fantasmas; los de ficha/favoritos/playlists los devuelven con
`missing: true` y `stream_url: null`.
**Ojo al probar en dev: `/music` es la biblioteca real y escribible** — borra solo álbumes de prueba
creados para ello, y no lances un escaneo completo para probar la poda (está cubierta por tests).
Tras el escaneo, `MetadataEnrichmentService` rellena carátulas faltantes vía Last.fm (`LastfmClient`)
o Spotify **si hay claves** (best-effort, nunca rompe el escaneo), y
`RecommendationService::warmSimilarArtists()` precalcula los similares de Last.fm.

### Recomendaciones
`RecommendationService` (híbrido: escucha ponderada por recencia + géneros + favoritos + similares de
Last.fm **solo desde caché**) alimenta `get_recommendations` (filas «para ti» de la Home) y
`get_artist_radio`. Nunca llama a la red en una petición: sin caché o sin clave, degrada a señales
internas. Pesos y límites son constantes con nombre al principio del servicio.

### Descubrir y descargar (Spotify + Soulseek/slskd)
Vista `Discover.vue` / acciones `spotify_search`, `slsk_search`, `slsk_download`,
`slsk_downloads`, `slsk_import` (`SoulseekController`).
El backend corre como **uid/gid 1000** (remap de `www-data` en el Dockerfile) para poder escribir
en `/music` y `/downloads` (propiedad de david=1000). `DownloadImporter` mueve con `rename` y, como
`/music` y `/downloads` son **bind-mounts distintos** dentro del contenedor (rename cruza
dispositivos → EXDEV), hace **fallback a copia recursiva + borrado**. El escáner indexa por **tags
embebidos**, así que lo importado se archiva por su artista/álbum real (la subcarpeta `<artista>`
del importador es solo organizativa). **Spotify = descubrimiento** (metadatos y
carátulas, vía `SpotifyClient`, reutilizado también por el enrichment). **Soulseek = adquisición**
(vía `SlskdClient` → API REST de slskd, `X-API-Key`, `SLSKD_URL=http://slskd:5030` por la red Docker
**`mediaserver`** compartida). Flujo: buscas en Spotify → eliges álbum → `slsk_search` por
`"<artista> <álbum>"` → eliges fuente → `slsk_download` (slskd baja a `/downloads`) → al completar,
`DownloadImporter` **mueve** la carpeta a `MUSIC_ROOT/<artista>/…` (`rename`, o copia + borrado si
cruza dispositivos, ver arriba) y
relanza `scan_library`. Requiere `SLSKD_API_KEY` (de `slskd.yml`); sin ella, las acciones `slsk_*`
devuelven 503 sin romper la app.
**Duplicados:** `LibraryMatcher` (artista+título con `Normalize::name`) añade `library` a cada álbum
de `spotify_search`. `slsk_download` sobre un álbum **vivo** responde 200 con `duplicate` sin encolar,
salvo `on_duplicate: 'skip'|'replace'`. `replace` guarda `downloads.replace_album_id`, y el
`DownloadReconciler` convierte el álbum viejo en fantasma **antes** de importar (relee la marca de BD:
una vez por álbum aunque lleguen CD1 y CD2), así el escaneo reengancha los mismos ids.
**Ojo al probar en dev:** `slsk_download` sin duplicado o con `replace` encola una descarga **real** en
slskd, y un `replace` importado borra del disco el álbum real. Pruébalo con tests o con filas de
`downloads` sintéticas.

### Capa de dominio
- `src/Domain/Repository/Music/**` define **interfaces**; `config/container.php` las mapea a las
  `MySql*Repository` de `src/Infrastructure/Persistence/Music`. Depende SIEMPRE de la interfaz.
- Repos de música devuelven **arrays** (no entidades de dominio), como `MySqlCategoryRepository`
  de statCoin. `TrackPresenter` y `AlbumPresenter` convierten filas → DTO de API (añaden
  `stream_url` firmado, `cover_url` —firmado a `cover.php` si la carátula es local, o la URL remota
  tal cual si solo existe en Last.fm/Spotify— e `is_favorite`). Favoritos de pista (`favorites`) y de álbum
  (`favorite_albums`) son tablas e interfaces de repositorio separadas.
- Auth (User, ValueObjects, UseCases, GoogleOAuthVerifier, JWTService, SessionManager) se reusa
  tal cual de statCoin.

### Frontend (Vue 3)
- Reproductor: **`src/store/player.js`** es un store Pinia que posee un **único `<audio>`**
  (singleton, vive fuera del árbol de rutas → la música no se corta al navegar). Cola, shuffle,
  repeat, y `log_play` al superar ~50% (cap 30s). `PlayerBar.vue` es la barra inferior persistente.
  Integra **`@jofr/capacitor-media-session`** para exponer controles multimedia nativos
  (notificación / lockscreen / botones bluetooth) en Android.
- Reordenado de playlists con **`vuedraggable`** (drag & drop).
- Stores `library/playlists/favorites` envuelven `apiCall`. Vistas en `src/views`.
- `src/services/api.js`: `apiCall(action, payload)` (single endpoint) + `resolveUrl()` que
  resuelve las rutas firmadas relativas (web same-origin) o absolutas (Capacitor prod).
- `createWebHashHistory` (web y webview). Guard global exige auth salvo rutas `public`.

### Base de datos
Esquema en `docker/database/init.sql` (corre solo en BD virgen). Cambios posteriores → archivos
datados en `docker/database/migrations/` aplicados a mano.

## Buenos comportamientos en este repo
- **Endpoint nuevo = tres sitios**: `routes.php`, `ActionRouter` (`match` + `getController`), y el
  método del controller.
- **Respeta la auth dual** (sesión web / JWT móvil) y la **URL firmada** para binarios: no metas
  auth de sesión en `stream.php`/`cover.php`.
- **Depende de interfaces de repositorio**; registra repos nuevos en `config/container.php`.
- **Streaming**: nunca `readfile()` del archivo entero; respeta Range y emite por chunks.
- **utf8mb4 en todo** (nombres en japonés/acentos en la biblioteca real).
- **El volumen de música va en lectura-escritura** (antes `:ro`): se relajó para permitir que
  `DownloadImporter` mueva las descargas de Soulseek a la biblioteca. El streaming sigue solo
  leyendo (`stream.php` nunca escribe). El backend además monta `/downloads` (descargas de slskd).
- **Puertos a propósito**: 8086 / 8892 / 3311 (evitar colisión con libraryVue 8080/8888/3308 y
  statCoin 8083/8890/3309).
- **Tests en el contenedor** (`docker compose exec backend composer test`); warnings hacen fallar.

## Verificación end-to-end
1. `docker compose up --build`; `POST {"action":"ping"}` a :8892 → `pong`.
2. Login dev (JWT) en la web (:8086) → Escanear → ver artistas/álbumes con carátula.
3. Reproducir un FLAC: en Network, `stream.php` responde **206** con `Accept-Ranges`; el seek
   dispara nuevas peticiones Range. Navegar entre vistas no corta la música.
4. Playlists/favoritos/play count persisten por usuario.
5. `docker compose exec backend composer test` en verde.
