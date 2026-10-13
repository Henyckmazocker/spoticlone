<template>
  <Teleport to="body">
    <transition name="np-slide">
      <section
        v-if="ui.nowPlayingOpen && cur"
        class="now-playing"
        :style="bgStyle"
        aria-label="Reproduciendo ahora"
      >
        <header class="np-head">
          <button class="icon-btn" title="Cerrar" @click="ui.closeNowPlaying()">
            <Icon name="chevron-down" :size="26" />
          </button>
          <div class="np-head-label">
            <span class="np-head-eyebrow">Reproduciendo</span>
            <span class="np-head-album" v-if="cur.album">{{ cur.album }}</span>
          </div>
          <button class="icon-btn" :class="{ active: ui.queueOpen }" title="Cola" @click="ui.toggleQueue()">
            <Icon name="queue" />
          </button>
        </header>

        <div class="np-art-wrap">
          <img v-if="cur.cover_url" class="np-art" :src="resolveUrl(cur.cover_url)" alt="" />
          <div v-else class="np-art cover-placeholder">♪</div>
        </div>

        <div class="np-meta">
          <div class="np-meta-text">
            <div class="np-title">{{ cur.title }}</div>
            <div class="np-artist">{{ cur.artist }}</div>
          </div>
          <button class="icon-btn" :class="{ active: cur.is_favorite }" title="Favorito" @click="toggleFav">
            <Icon name="favorite" :filled="cur.is_favorite" :size="24" />
          </button>
        </div>

        <div class="np-progress">
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

        <div class="np-controls">
          <button class="icon-btn" :class="{ active: player.shuffle }" title="Aleatorio" @click="player.toggleShuffle()">
            <Icon name="shuffle" :size="22" />
          </button>
          <button class="icon-btn" title="Anterior" @click="player.prev()"><Icon name="prev" :size="28" /></button>
          <button class="pb-play np-play" :title="player.isPlaying ? 'Pausa' : 'Reproducir'" @click="player.toggle()">
            <Icon :name="player.isPlaying ? 'pause' : 'play'" :size="26" />
          </button>
          <button class="icon-btn" title="Siguiente" @click="player.next()"><Icon name="next" :size="28" /></button>
          <button class="icon-btn" :class="{ active: player.repeat !== 'off' }" title="Repetir" @click="player.cycleRepeat()">
            <Icon :name="player.repeat === 'one' ? 'repeat-one' : 'repeat'" :size="22" />
          </button>
        </div>

        <div class="np-bottom">
          <span class="icon-btn"><Icon name="volume" /></span>
          <input class="seek vol np-vol" type="range" min="0" max="1" step="0.01" :value="player.volume" @input="onVol" />
        </div>
      </section>
    </transition>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { usePlayerStore } from '@/store/player';
import { useUiStore } from '@/store/ui';
import { resolveUrl, apiCall } from '@/services/api';
import { formatDuration } from '@/utils/format';
import { getCoverColor } from '@/utils/coverColor';
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

// ---- Fondo ambiental derivado de la portada (best-effort) ----
const accent = ref(null);
const bgStyle = computed(() =>
  accent.value
    ? { background: `linear-gradient(180deg, ${accent.value} 0%, var(--bg) 60%)` }
    : { background: 'var(--bg)' }
);

watch(
  () => cur.value && cur.value.cover_url,
  (url) => {
    accent.value = null;
    if (!url) return;
    getCoverColor(resolveUrl(url))
      .then(({ r, g, b }) => { accent.value = `rgb(${r}, ${g}, ${b})`; })
      .catch(() => { accent.value = null; });
  },
  { immediate: true }
);

// ---- Cierre con Esc mientras la vista está abierta ----
function onKey(e) { if (e.key === 'Escape') ui.closeNowPlaying(); }
watch(
  () => ui.nowPlayingOpen,
  (open) => {
    if (open) window.addEventListener('keydown', onKey);
    else window.removeEventListener('keydown', onKey);
  }
);
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
