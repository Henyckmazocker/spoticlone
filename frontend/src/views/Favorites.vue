<template>
  <div>
    <h1 style="display:flex; align-items:center; gap:10px;"><Icon name="favorite" filled :size="28" /> Favoritos</h1>

    <section v-if="fav.albums.length">
      <h2>Álbumes</h2>
      <div class="grid">
        <AlbumCard
          v-for="al in fav.albums"
          :key="al.id"
          :album="al"
          :sub="al.artist"
          @favorite-change="(isFav) => onAlbumFavoriteChange(al.id, isFav)"
        />
      </div>
    </section>

    <section v-if="fav.tracks.length">
      <h2>Pistas</h2>
      <div style="margin-bottom:16px">
        <button class="btn" @click="playAll"><Icon name="play" :size="16" /> Reproducir todo</button>
      </div>
      <TrackList :tracks="fav.tracks" @favorite-change="onTrackFavoriteChange" />
    </section>

    <p v-if="loaded && !fav.albums.length && !fav.tracks.length" class="muted">Aún no tienes favoritos.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useFavoritesStore } from '@/store/favorites';
import { usePlayerStore } from '@/store/player';
import TrackList from '@/components/TrackList.vue';
import AlbumCard from '@/components/AlbumCard.vue';
import Icon from '@/components/Icon.vue';

const fav = useFavoritesStore();
const player = usePlayerStore();
const loaded = ref(false);

function playAll() {
  if (fav.tracks.length) player.setQueueAndPlay(fav.tracks, 0);
}

function onAlbumFavoriteChange(albumId, isFav) {
  if (!isFav) fav.albums = fav.albums.filter((a) => a.id !== albumId);
}

function onTrackFavoriteChange(trackId, isFav) {
  if (!isFav) fav.tracks = fav.tracks.filter((t) => t.id !== trackId);
}

onMounted(async () => {
  try { await fav.load(); } finally { loaded.value = true; }
});
</script>
