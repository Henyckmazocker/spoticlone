import { defineStore } from 'pinia';
import { apiCall } from '@/services/api';

const SEARCH_POLL_MS = 1500;
const SEARCH_TIMEOUT_MS = 25000;
const DOWNLOADS_POLL_MS = 3000;

// Estados que ya no avanzan solos (los decide el backend, aquí solo se pintan).
const TERMINAL = ['imported', 'failed'];

/**
 * Discover (Spotify) + acquire (Soulseek/slskd).
 *
 * El servidor es la fuente de verdad: la tabla `downloads` guarda qué se encoló y el backend
 * reconcilia contra slskd e importa lo completado al sondear `slsk_downloads`. Aquí no hay lógica
 * de importación ni seguimiento de sesión — antes vivían en memoria y se perdían con un F5.
 */
export const useSoulseekStore = defineStore('soulseek', {
  state: () => ({
    downloads: [],
    pollTimer: null
  }),
  getters: {
    // Hay trabajo pendiente mientras algo no haya llegado a un estado terminal.
    hasActive: (s) => s.downloads.some((d) => !TERMINAL.includes(d.state)),
    failed: (s) => s.downloads.filter((d) => d.state === 'failed')
  },
  actions: {
    async spotifySearch(q) {
      return apiCall('spotify_search', { q });
    },

    /**
     * Búsqueda en dos fases: el backend arranca y devuelve un id al instante, y sondeamos hasta
     * que slskd la completa. Antes esto tenía un worker PHP bloqueado hasta 18 s.
     * @param {(p:{state:string,responses:number}) => void} onProgress
     */
    async slskSearch({ artist, album }, onProgress) {
      const { search_id: searchId } = await apiCall('slsk_search', { artist, album });
      const deadline = Date.now() + SEARCH_TIMEOUT_MS;

      for (;;) {
        await sleep(SEARCH_POLL_MS);
        const d = await apiCall('slsk_search_results', { search_id: searchId });
        onProgress?.({ state: d.state, responses: d.response_count });
        if (d.is_complete) return d.results;
        if (Date.now() > deadline) return d.results || [];
      }
    },

    /**
     * Devuelve la respuesta del backend: `{queued}`, `{queued:0, duplicate:{album_id,title,artist}}`
     * si el álbum ya está en la biblioteca y no se dijo qué hacer, o `{queued:0, skipped:true}`.
     * @param {'replace'|'skip'|null} onDuplicate decisión ya tomada para este álbum
     * @param {number|null} requestId petición de álbum que origina la descarga (pasa a `downloading` si se encola)
     */
    async download({ username, files, artist, album, onDuplicate = null, requestId = null }) {
      const r = await apiCall('slsk_download', { username, files, artist, album, on_duplicate: onDuplicate, request_id: requestId });
      // Si se encoló algo, el backend ya ha registrado las filas: refrescar deja Descargas al día.
      if (r?.queued > 0) {
        await this.refreshDownloads();
        this.startPolling();
      }
      return r;
    },

    async refreshDownloads() {
      const d = await apiCall('slsk_downloads');
      this.downloads = d.downloads;
      return this.downloads;
    },

    // force = "importar igualmente": lleva a la biblioteca lo que sí bajó de una descarga fallida.
    async importDownload(id, force = false) {
      return apiCall('slsk_import', { id, force });
    },

    /** Reencola en slskd solo las pistas que fallaron; la fila vuelve a 'queued' y se resondea. */
    async retryMissing(id) {
      const d = await apiCall('slsk_retry_missing', { id });
      await this.refreshDownloads();
      this.startPolling();
      return d.queued;
    },

    async removeDownloads(ids) {              // ids de fila; el backend resuelve las transferencias
      const d = await apiCall('slsk_remove_downloads', { ids });
      return d.removed;
    },

    /**
     * Sondeo global. Vive en el store y no en la vista Descargas a propósito: cada sondeo es lo
     * que dispara la importación en el backend, así que debe seguir corriendo aunque el usuario
     * navegue a otra pantalla.
     */
    startPolling() {
      if (this.pollTimer) return;
      this.pollTimer = setInterval(async () => {
        try {
          await this.refreshDownloads();
        } catch (e) {
          return; // error puntual de red: se reintenta en el siguiente tick
        }
        if (!this.hasActive) this.stopPolling();
      }, DOWNLOADS_POLL_MS);
    },

    stopPolling() {
      if (this.pollTimer) clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }
});

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}
