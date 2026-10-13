<template>
  <div>
    <h1>Buscar</h1>
    <input
      type="search"
      v-model="q"
      placeholder="Artistas, álbumes o canciones…"
      @input="onInput"
      autofocus
    />

    <template v-if="results">
      <section v-if="results.artists.length">
        <h2>Artistas</h2>
        <div class="grid">
          <div v-for="a in results.artists" :key="a.id" class="card" @click="$router.push({ name: 'artist', params: { id: a.id } })">
            <img v-if="a.image_url" class="card-cover round" :src="a.image_url" alt="" />
            <div v-else class="card-cover round cover-placeholder"><Icon name="artist" :size="48" /></div>
            <div class="card-title">{{ a.name }}</div>
          </div>
        </div>
      </section>

      <section v-if="results.albums.length">
        <h2>Álbumes</h2>
        <div class="grid">
          <AlbumCard v-for="al in results.albums" :key="al.id" :album="al" :sub="al.artist" />
        </div>
      </section>

      <section v-if="results.tracks.length">
        <h2>Canciones</h2>
        <TrackList :tracks="results.tracks" />
      </section>

      <p v-if="!results.artists.length && !results.albums.length && !results.tracks.length" class="muted">
        Sin resultados para “{{ q }}”.
      </p>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useLibraryStore } from '@/store/library';
import AlbumCard from '@/components/AlbumCard.vue';
import TrackList from '@/components/TrackList.vue';
import Icon from '@/components/Icon.vue';

const lib = useLibraryStore();
const q = ref('');
const results = ref(null);
let timer = null;

function onInput() {
  clearTimeout(timer);
  const term = q.value.trim();
  if (!term) { results.value = null; return; }
  timer = setTimeout(async () => {
    results.value = await lib.search(term);
  }, 250);
}
</script>
