import { defineStore } from 'pinia';
import { apiCall } from '@/services/api';

/**
 * Peticiones de álbumes: el usuario no admin pide un álbum de Spotify y sigue su estado.
 * `all` y `reject` son de la cola del admin; el servidor las reserva con AdminMiddleware.
 */
export const useRequestsStore = defineStore('requests', {
  state: () => ({
    mineList: []
  }),
  getters: {
    // Abiertas: las que cuentan para el límite y las que marcan «Pedido» en Discover.
    open: (s) => s.mineList.filter((r) => ['pending', 'downloading'].includes(r.status)),
    openSpotifyIds() {
      return new Set(this.open.map((r) => r.spotify_album_id));
    }
  },
  actions: {
    /** @returns {Promise<{request:object, joined:boolean}>} */
    async request(spotifyId) {
      const d = await apiCall('request_album', { spotify_album_id: spotifyId });
      await this.mine();
      return d;
    },

    async mine() {
      const d = await apiCall('get_my_album_requests');
      this.mineList = d.requests || [];
      return this.mineList;
    },

    async cancel(id) {
      await apiCall('cancel_album_request', { requestId: id });
      await this.mine();
    },

    /** @param {'open'|'all'} status */
    async all(status = 'open') {
      const d = await apiCall('get_album_requests', { status });
      return d.requests || [];
    },

    /** @param {'rejected'|'fulfilled'} status */
    async reject(id, status, reason) {
      const d = await apiCall('reject_album_request', { requestId: id, status, reason });
      return d.request;
    }
  }
});
