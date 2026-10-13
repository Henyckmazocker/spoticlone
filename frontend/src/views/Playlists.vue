<template>
  <div>
    <h1>Playlists</h1>

    <div style="display:flex; gap:10px; margin-bottom:18px;">
      <input type="text" v-model="newName" placeholder="Nombre de la nueva playlist" style="max-width:320px" @keyup.enter="create" />
      <button class="btn" :disabled="!newName.trim()" @click="create">Crear</button>
    </div>

    <div class="grid">
      <div v-for="p in store.playlists" :key="p.id" class="card" @click="$router.push({ name: 'playlist', params: { id: p.id } })">
        <div class="card-cover cover-placeholder"><Icon name="playlist" :size="48" /></div>
        <div class="card-title">{{ p.name }}</div>
        <div class="card-sub">{{ p.track_count }} pistas</div>
      </div>
    </div>
    <p v-if="!store.playlists.length" class="muted">Todavía no tienes playlists.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePlaylistsStore } from '@/store/playlists';
import Icon from '@/components/Icon.vue';

const store = usePlaylistsStore();
const newName = ref('');

async function create() {
  const name = newName.value.trim();
  if (!name) return;
  await store.create(name);
  newName.value = '';
}

onMounted(() => store.load());
</script>
