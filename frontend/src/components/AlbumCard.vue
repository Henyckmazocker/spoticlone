<template>
  <div class="card" :class="{ 'is-missing': album.missing }" @click="open">
    <img v-if="album.cover_url" class="card-cover" :src="resolveUrl(album.cover_url)" alt="" />
    <div v-else class="card-cover cover-placeholder"><Icon name="album" :size="48" /></div>
    <button
      class="icon-btn card-fav"
      :class="{ active: album.is_favorite }"
      :title="album.is_favorite ? 'Quitar de favoritos' : 'Añadir a favoritos'"
      :disabled="busy"
      @click.stop="toggleFav"
    >
      <Icon name="favorite" :filled="album.is_favorite" :size="18" />
    </button>
    <div class="card-title">{{ album.title }}</div>
    <div class="card-sub">{{ sub }}</div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useFavoritesStore } from '@/store/favorites';
import { resolveUrl } from '@/services/api';
import Icon from '@/components/Icon.vue';

const props = defineProps({
  album: { type: Object, required: true },
  sub: { type: String, default: '' }
});
const emit = defineEmits(['favorite-change']);

const router = useRouter();
const fav = useFavoritesStore();
const busy = ref(false);

function open() { router.push({ name: 'album', params: { id: props.album.id } }); }

async function toggleFav() {
  busy.value = true;
  try {
    const isFav = await fav.toggleAlbum(props.album.id);
    // eslint-disable-next-line vue/no-mutating-props
    props.album.is_favorite = isFav;
    emit('favorite-change', isFav);
  } catch (e) { /* ignore */ } finally {
    busy.value = false;
  }
}
</script>
