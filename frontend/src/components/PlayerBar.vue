<template>
  <div class="player-bar">
    <!-- Now playing -->
    <div class="pb-now">
      <button
        class="pb-now-trigger"
        :disabled="!cur"
        title="Abrir reproductor"
        @click="cur && ui.openNowPlaying()"
      >
        <img v-if="cur && cur.cover_url" class="pb-cover" :src="resolveUrl(cur.cover_url)" alt="" />
        <div v-else class="pb-cover cover-placeholder">♪</div>
        <div class="pb-info" v-if="cur">
          <div class="pb-title">{{ cur.title }}</div>
          <div class="pb-artist">{{ cur.artist }}</div>
        </div>
      </button>
      <button v-if="cur" class="icon-btn" :class="{ active: cur.is_favorite }" title="Favorito" @click="toggleFav">
        <Icon name="favorite" :filled="cur.is_favorite" />
      </button>
    </div>

    <!-- Center: controls + progress -->
    <div class="pb-center">
      <div class="pb-controls">
        <button class="icon-btn" :class="{ active: player.shuffle }" title="Aleatorio" @click="player.toggleShuffle()">
          <Icon name="shuffle" />
        </button>
        <button class="icon-btn" title="Anterior" @click="player.prev()"><Icon name="prev" /></button>
        <button class="pb-play" :title="player.isPlaying ? 'Pausa' : 'Reproducir'" @click="player.toggle()">
          <Icon :name="player.isPlaying ? 'pause' : 'play'" :size="18" />
        </button>
        <button class="icon-btn" title="Siguiente" @click="player.next()"><Icon name="next" /></button>
        <button class="icon-btn" :class="{ active: player.repeat !== 'off' }" title="Repetir" @click="player.cycleRepeat()">
          <Icon :name="player.repeat === 'one' ? 'repeat-one' : 'repeat'" />
        </button>
      </div>
      <div class="pb-progress">
        <span class="pb-time">{{ fmt(player.currentTime) }}</span>
        <input
          class="seek"
          type="range"
          min="0"
          :max="player.duration || 0"
          step="1"
          :value="player.currentTime"
          @input="onSeek"
        />
        <span class="pb-time">{{ fmt(player.duration) }}</span>
      </div>
    </div>

    <!-- Right: queue + volume -->
    <div class="pb-right">
      <button class="icon-btn" :class="{ active: ui.queueOpen }" title="Cola" @click="ui.toggleQueue()">
        <Icon name="queue" />
      </button>
      <span class="icon-btn"><Icon name="volume" /></span>
      <input class="seek vol" type="range" min="0" max="1" step="0.01" :value="player.volume" @input="onVol" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePlayerStore } from '@/store/player';
import { useUiStore } from '@/store/ui';
import { resolveUrl, apiCall } from '@/services/api';
import { formatDuration } from '@/utils/format';
import Icon from '@/components/Icon.vue';

const player = usePlayerStore();
const ui = useUiStore();
const cur = computed(() => player.current);
const fmt = formatDuration;

function onSeek(e) { player.seek(Number(e.target.value)); }
function onVol(e) { player.setVolume(Number(e.target.value)); }

async function toggleFav() {
  if (!cur.value) return;
  try {
    const isFav = await apiCall('toggle_favorite', { trackId: cur.value.id });
    cur.value.is_favorite = isFav.is_favorite;
  } catch (e) { /* ignore */ }
}
</script>
