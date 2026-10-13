import { defineStore } from 'pinia';
import { apiCall } from '@/services/api';

export const useLibraryStore = defineStore('library', {
  state: () => ({
    artists: [],
    artistsLoaded: false,
    scan: null
  }),
  actions: {
    async loadArtists(force = false) {
      if (this.artistsLoaded && !force) return;
      const d = await apiCall('get_artists');
      this.artists = d.artists;
      this.artistsLoaded = true;
    },
    async getArtist(artistId) {
      return apiCall('get_artist', { artistId });
    },
    async getAlbum(albumId) {
      return apiCall('get_album', { albumId });
    },
    async search(q) {
      return apiCall('search', { q });
    },
    async scanLibrary() {
      const d = await apiCall('scan_library');
      this.scan = d;
      this.artistsLoaded = false; // refresh artist list next time
      return d;
    },
    async getScanStatus() {
      const d = await apiCall('get_scan_status');
      this.scan = d.scan;
      return d.scan;
    },
    async deleteAlbum(albumId) {
      await apiCall('delete_album', { albumId });
      this.artistsLoaded = false; // files gone, album stays as a ghost (greyed) → refresh list
    },
    // Hard delete of a ghost album (and of its history); the backend answers 409 for a live one.
    async forgetAlbum(albumId) {
      const d = await apiCall('forget_album', { albumId });
      this.artistsLoaded = false;
      return d; // { forgotten, artist_removed }
    },
    async deleteArtist(artistId) {
      await apiCall('delete_artist', { artistId });
      this.artistsLoaded = false;
    }
  }
});
