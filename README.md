# spoticlone

Streaming de tu biblioteca de música local, estilo Spotify. Web + Android (Capacitor), con
backend PHP (clean architecture) y frontend Vue 3 — misma arquitectura que `statCoin`/`libraryVue`.

## Características
- Escaneo de la biblioteca local (`Artista/Álbum/pistas`) leyendo etiquetas con getID3.
- Streaming de audio con **HTTP Range** (seek/buffering) vía URL firmada (funciona en `<audio>` y
  en Android sin cabeceras de auth).
- Navegación por artistas y álbumes, búsqueda, carátulas.
- Reproductor persistente (cola, shuffle, repeat), **playlists**, **favoritos** y **play count**.
- Multi-usuario con Google OAuth (login de desarrollo con JWT incluido).
- Enriquecido opcional de carátulas con Last.fm/Spotify.

## Arranque rápido
```bash
cp .env.example .env
# Edita MUSIC_LIBRARY_PATH (ruta del host a tu música) y JWT_SECRET.
docker compose up --build
```
- Frontend: http://localhost:8086
- Backend API: http://localhost:8892/index.php
- MySQL: localhost:3311

Luego entra, ve a **Escanear** para indexar la biblioteca, y empieza a reproducir.

## Producción
Frontend Vue compilado servido por nginx + backend Apache + MySQL propio, expuesto por el túnel
Cloudflare en `spoticlone.dcahomelab.com`:
```bash
docker compose -p spoticlone_prod -f docker-compose.prod.yml up -d --build
docker network connect spoticlone_prod cloudflared
# Dashboard Cloudflare → Public Hostname: spoticlone.dcahomelab.com -> http://spoticlone-frontend-prod:80
```
Prueba local en http://localhost:8087. Usa BD propia (`spoticlone_db_prod`): tras desplegar,
ejecuta **Escanear** una vez. Pon un `JWT_SECRET` fuerte en el `.env` (firma las URLs de stream).

Detalles de arquitectura y comandos en [`CLAUDE.md`](./CLAUDE.md).
