import { defineStore } from 'pinia';
import { apiCall } from '@/services/api';

export const useFavoritesStore = defineStore('favorites', {
  state: () => ({
    tracks: [],
    albums: []
  }),
  actions: {
    async load() {
      const d = await apiCall('get_favorites');
      this.tracks = d.tracks || [];
      this.albums = d.albums || [];
    },
    async toggle(trackId) {
      const d = await apiCall('toggle_favorite', { trackId });
      return d.is_favorite;
    },
    async toggleAlbum(albumId) {
      const d = await apiCall('toggle_album_favorite', { albumId });
      return d.is_favorite;
    }
  }
});
