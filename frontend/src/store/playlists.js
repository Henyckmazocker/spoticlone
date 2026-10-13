import { defineStore } from 'pinia';
import { apiCall } from '@/services/api';

export const usePlaylistsStore = defineStore('playlists', {
  state: () => ({
    playlists: []
  }),
  actions: {
    async load() {
      const d = await apiCall('get_playlists');
      this.playlists = d.playlists;
    },
    async get(playlistId) {
      return apiCall('get_playlist', { playlistId });
    },
    async create(name, description = null, isPublic = false) {
      const d = await apiCall('create_playlist', { name, description, is_public: isPublic });
      await this.load();
      return d.id;
    },
    async update(playlistId, data) {
      await apiCall('update_playlist', { playlistId, ...data });
      await this.load();
    },
    async remove(playlistId) {
      await apiCall('delete_playlist', { playlistId });
      await this.load();
    },
    async addTracks(playlistId, trackIds) {
      await apiCall('add_to_playlist', { playlistId, trackIds });
      await this.load();
    },
    async removeTrack(playlistId, trackId) {
      await apiCall('remove_from_playlist', { playlistId, trackId });
    },
    async reorder(playlistId, trackIds) {
      await apiCall('reorder_playlist', { playlistId, trackIds });
    }
  }
});
