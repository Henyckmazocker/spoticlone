<template>
  <div v-if="artist">
    <div class="detail-head">
      <img v-if="artist.image_url" class="big-cover round" :src="artist.image_url" alt="" />
      <div v-else class="big-cover round cover-placeholder"><Icon name="artist" :size="80" /></div>
      <div>
        <div class="meta-type">Artista</div>
        <div class="meta-title">{{ artist.name }}</div>
        <div class="muted">{{ albums.length }} álbum(es)</div>
        <div style="display:flex; gap:8px; margin-top:12px">
          <button class="btn" :disabled="radioLoading" @click="playRadio">
            <Icon name="play" :size="16" /> Radio
          </button>
          <button class="btn-ghost" style="color:var(--danger)" @click="confirmOpen = true">
            <Icon name="trash" :size="16" /> Eliminar artista
          </button>
        </div>
      </div>
    </div>

    <h2>Álbumes</h2>
    <div class="grid">
      <AlbumCard
        v-for="al in albums"
        :key="al.id"
        :album="al"
        :sub="al.missing ? 'Borrado del disco' : `${al.year || ''} · ${al.track_count} pistas`"
      />
    </div>

    <ConfirmDialog
      :open="confirmOpen"
      title="Eliminar artista"
      :message="`Se eliminarán los archivos de todos los álbumes de «${artist.name}». Se borran los ficheros; el historial se conserva.`"
      confirm-text="Eliminar artista"
      @confirm="doDelete"
      @cancel="confirmOpen = false"
    />
  </div>
  <p v-else class="muted">Cargando…</p>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useLibraryStore } from '@/store/library';
import { usePlayerStore } from '@/store/player';
import { apiCall } from '@/services/api';
import AlbumCard from '@/components/AlbumCard.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Icon from '@/components/Icon.vue';

const props = defineProps({ id: { type: [String, Number], required: true } });
const lib = useLibraryStore();
const router = useRouter();
const player = usePlayerStore();
const artist = ref(null);
const albums = ref([]);
const confirmOpen = ref(false);
const radioLoading = ref(false);

async function playRadio() {
  radioLoading.value = true;
  try {
    const { tracks } = await apiCall('get_artist_radio', { artistId: Number(props.id) });
    if (tracks && tracks.length) player.setQueueAndPlay(tracks, 0);
  } catch (e) { /* */ } finally {
    radioLoading.value = false;
  }
}

async function doDelete() {
  await lib.deleteArtist(Number(props.id));
  confirmOpen.value = false;
  router.push('/artists');
}

async function load() {
  const d = await lib.getArtist(Number(props.id));
  artist.value = d.artist;
  // Ghosts (deleted from disk, kept for their history) go last; sort is stable, so the backend
  // order holds within each group.
  albums.value = [...d.albums].sort((a, b) => Number(!!a.missing) - Number(!!b.missing));
}

onMounted(load);
watch(() => props.id, load);
</script>
