<template>
  <div class="tracklist">
    <div
      v-for="(t, i) in tracks"
      :key="t.id"
      class="track-row"
      :class="{ playing: isCurrent(t), 'is-missing': t.missing }"
      :title="t.missing ? 'Borrada del disco' : null"
      @dblclick="playAt(i)"
    >
      <div class="track-index">
        <button class="icon-btn" :disabled="t.missing" @click="playAt(i)">
          <Icon :name="isCurrent(t) && player.isPlaying ? 'pause' : 'play'" :size="16" />
        </button>
      </div>
      <div class="track-main">
        <div class="track-name">{{ t.title }}</div>
        <div class="track-artist">
          {{ t.artist }}<template v-if="showAlbum && t.album"> · {{ t.album }}</template>
        </div>
      </div>
      <button class="icon-btn" title="Añadir a la cola" :disabled="t.missing" @click="player.enqueue(t)"><Icon name="add" :size="18" /></button>
      <button class="icon-btn" :class="{ active: t.is_favorite }" title="Favorito" @click="toggleFav(t)">
        <Icon name="favorite" :filled="t.is_favorite" :size="18" />
      </button>
      <div class="track-dur">
        <span v-if="t.plays != null" class="muted" style="margin-right:12px">{{ t.plays }} ▶</span>
        {{ fmt(t.duration) }}
      </div>
    </div>
    <p v-if="!tracks.length" class="muted">No hay pistas.</p>
  </div>
</template>

<script setup>
import { usePlayerStore } from '@/store/player';
import { apiCall } from '@/services/api';
import { formatDuration } from '@/utils/format';
import Icon from '@/components/Icon.vue';

const props = defineProps({
  tracks: { type: Array, default: () => [] },
  showAlbum: { type: Boolean, default: true }
});
// Lets a parent react to a favorite toggle (Favorites drops the track when it is unmarked).
const emit = defineEmits(['favorite-change']);

const player = usePlayerStore();
const fmt = formatDuration;

function isCurrent(t) { return player.current && player.current.id === t.id; }

function playAt(i) {
  if (props.tracks[i].missing) return; // ghost: files deleted, nothing to stream
  if (isCurrent(props.tracks[i])) {
    player.toggle();
  } else {
    player.setQueueAndPlay(props.tracks, i);
  }
}

async function toggleFav(t) {
  try {
    const d = await apiCall('toggle_favorite', { trackId: t.id });
    t.is_favorite = d.is_favorite;
    emit('favorite-change', t.id, d.is_favorite);
  } catch (e) { /* ignore */ }
}
</script>
