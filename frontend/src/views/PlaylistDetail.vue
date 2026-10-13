<template>
  <div v-if="playlist">
    <div class="detail-head">
      <div class="big-cover cover-placeholder"><Icon name="playlist" :size="80" /></div>
      <div>
        <div class="meta-type">Playlist</div>
        <div class="meta-title">{{ playlist.name }}</div>
        <div class="muted">{{ tracks.length }} pistas</div>
      </div>
    </div>

    <div style="display:flex; gap:12px; margin-bottom:18px;">
      <button class="btn" :disabled="!tracks.length" @click="playAll"><Icon name="play" :size="16" /> Reproducir</button>
      <button class="btn-ghost" :disabled="!tracks.length" @click="player.enqueue(tracks)"><Icon name="add" :size="16" /> Añadir a la cola</button>
      <button class="btn-ghost" @click="remove">Eliminar playlist</button>
    </div>

    <div class="tracklist">
      <draggable
        v-model="tracks"
        item-key="id"
        handle=".track-drag"
        :animation="150"
        :delay="180"
        :delay-on-touch-only="true"
        :touch-start-threshold="6"
        ghost-class="queue-ghost"
        @end="persistOrder"
      >
        <template #item="{ element: t, index: i }">
          <div
            class="track-row"
            :class="{ playing: player.current && player.current.id === t.id }"
          >
            <div class="track-index">
              <button class="icon-btn" @click="playAt(i)"><Icon name="play" :size="16" /></button>
            </div>
            <div class="track-main">
              <div class="track-name">{{ t.title }}</div>
              <div class="track-artist">{{ t.artist }} · {{ t.album }}</div>
            </div>
            <button class="icon-btn track-drag" title="Reordenar"><Icon name="drag" :size="18" /></button>
            <button class="icon-btn" title="Quitar de la playlist" @click="removeTrack(t)"><Icon name="remove" :size="16" /></button>
            <div class="track-dur">{{ fmt(t.duration) }}</div>
          </div>
        </template>
      </draggable>
      <p v-if="!tracks.length" class="muted">Playlist vacía. Añade pistas desde un álbum.</p>
    </div>
  </div>
  <p v-else class="muted">Cargando…</p>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import draggable from 'vuedraggable';
import { usePlaylistsStore } from '@/store/playlists';
import { usePlayerStore } from '@/store/player';
import { formatDuration } from '@/utils/format';
import Icon from '@/components/Icon.vue';

const props = defineProps({ id: { type: [String, Number], required: true } });
const store = usePlaylistsStore();
const player = usePlayerStore();
const router = useRouter();
const fmt = formatDuration;

const playlist = ref(null);
const tracks = ref([]);

function playAll() { if (tracks.value.length) player.setQueueAndPlay(tracks.value, 0); }
function playAt(i) { player.setQueueAndPlay(tracks.value, i); }

async function removeTrack(t) {
  await store.removeTrack(Number(props.id), t.id);
  tracks.value = tracks.value.filter((x) => x.id !== t.id);
}

// Persistir el nuevo orden tras un drag & drop (la acción reorder_playlist ya existe en backend).
async function persistOrder() {
  await store.reorder(Number(props.id), tracks.value.map((t) => t.id));
}

async function remove() {
  await store.remove(Number(props.id));
  router.push('/playlists');
}

async function load() {
  const d = await store.get(Number(props.id));
  playlist.value = d.playlist;
  tracks.value = d.tracks;
}

onMounted(load);
watch(() => props.id, load);
</script>
