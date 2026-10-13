<template>
  <div v-if="album">
    <div class="detail-head" :class="{ 'is-missing': album.missing }">
      <img v-if="album.cover_url" class="big-cover" :src="resolveUrl(album.cover_url)" alt="" />
      <div v-else class="big-cover cover-placeholder"><Icon name="album" :size="80" /></div>
      <div>
        <div class="meta-type">Álbum</div>
        <div class="meta-title">{{ album.title }}</div>
        <div class="muted">
          <router-link :to="{ name: 'artist', params: { id: album.artist_id } }" style="color:var(--text)">
            {{ album.artist }}
          </router-link>
          <span v-if="album.year"> · {{ album.year }}</span>
          · {{ tracks.length }} pistas
        </div>
        <div v-if="album.missing" class="muted" style="margin-top:6px">
          Borrado del disco: se conserva su historial. Re-descárgalo para volver a escucharlo.
        </div>
      </div>
    </div>

    <div style="display:flex; gap:12px; align-items:center; margin-bottom:18px;">
      <button v-if="album.missing" class="btn" @click="redownload">⬇ Re-descargar</button>
      <button v-else class="btn" @click="playAll"><Icon name="play" :size="16" /> Reproducir</button>
      <button
        class="btn-ghost"
        :style="album.is_favorite ? 'color:var(--accent)' : ''"
        :disabled="favBusy"
        @click="toggleFav"
      >
        <Icon name="favorite" :filled="album.is_favorite" :size="16" />
        {{ album.is_favorite ? 'En favoritos' : 'Favorito' }}
      </button>
      <select v-model="targetPlaylist" class="btn-ghost" style="padding:8px" @change="addAllToPlaylist">
        <option :value="''" disabled>Añadir a playlist…</option>
        <option v-for="p in playlists.playlists" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <button v-if="album.missing" class="btn-ghost" style="color:var(--danger)" @click="forgetOpen = true">
        <Icon name="trash" :size="16" /> Olvidar
      </button>
      <button v-else class="btn-ghost" style="color:var(--danger)" @click="confirmOpen = true">
        <Icon name="trash" :size="16" /> Eliminar álbum
      </button>
    </div>

    <TrackList :tracks="tracks" :show-album="false" />

    <ConfirmDialog
      :open="confirmOpen"
      title="Eliminar álbum"
      :message="`Se eliminarán los archivos de «${album.title}» del dispositivo. El álbum quedará en gris con su historial de escucha, y podrás re-descargarlo.`"
      confirm-text="Eliminar álbum"
      @confirm="doDelete"
      @cancel="confirmOpen = false"
    />
    <ConfirmDialog
      :open="forgetOpen"
      title="Olvidar álbum"
      :message="`Se olvidará «${album.title}». Se borrará su historial de escucha, sus favoritos y su sitio en las playlists. Esta acción no se puede deshacer.`"
      confirm-text="Olvidar"
      @confirm="doForget"
      @cancel="forgetOpen = false"
    />
  </div>
  <p v-else class="muted">Cargando…</p>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useLibraryStore } from '@/store/library';
import { usePlaylistsStore } from '@/store/playlists';
import { usePlayerStore } from '@/store/player';
import { useFavoritesStore } from '@/store/favorites';
import { resolveUrl } from '@/services/api';
import TrackList from '@/components/TrackList.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Icon from '@/components/Icon.vue';

const props = defineProps({ id: { type: [String, Number], required: true } });
const router = useRouter();
const lib = useLibraryStore();
const playlists = usePlaylistsStore();
const player = usePlayerStore();
const fav = useFavoritesStore();

const album = ref(null);
const tracks = ref([]);
const targetPlaylist = ref('');
const confirmOpen = ref(false);
const forgetOpen = ref(false);
const favBusy = ref(false);

function playAll() {
  if (tracks.value.length) player.setQueueAndPlay(tracks.value, 0);
}

async function toggleFav() {
  favBusy.value = true;
  try {
    album.value.is_favorite = await fav.toggleAlbum(album.value.id);
  } catch (e) { /* ignore */ } finally {
    favBusy.value = false;
  }
}

async function addAllToPlaylist() {
  if (!targetPlaylist.value) return;
  await playlists.addTracks(targetPlaylist.value, tracks.value.map((t) => t.id));
  targetPlaylist.value = '';
}

async function doDelete() {
  const artistId = album.value.artist_id;
  await lib.deleteAlbum(Number(props.id));
  confirmOpen.value = false;
  router.push({ name: 'artist', params: { id: artistId } });
}

// Ghost album → Discover with its names, which starts the Soulseek search right away.
function redownload() {
  router.push({ name: 'discover', query: { artist: album.value.artist, album: album.value.title } });
}

async function doForget() {
  const artistId = album.value.artist_id;
  const d = await lib.forgetAlbum(Number(props.id));
  forgetOpen.value = false;
  if (d && d.artist_removed) router.push('/artists');
  else router.push({ name: 'artist', params: { id: artistId } });
}

async function load() {
  const d = await lib.getAlbum(Number(props.id));
  album.value = d.album;
  tracks.value = d.tracks;
}

onMounted(async () => {
  await load();
  try { await playlists.load(); } catch (e) { /* */ }
});
watch(() => props.id, load);
</script>
