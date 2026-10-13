import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Estado de UI transversal (no persistente): visibilidad del panel de cola y de la vista grande
 * de reproducción ("Now Playing").
 */
export const useUiStore = defineStore('ui', () => {
  const queueOpen = ref(false);
  const nowPlayingOpen = ref(false);

  function toggleQueue() { queueOpen.value = !queueOpen.value; }
  function closeQueue() { queueOpen.value = false; }

  function openNowPlaying() { nowPlayingOpen.value = true; }
  function closeNowPlaying() { nowPlayingOpen.value = false; }
  function toggleNowPlaying() { nowPlayingOpen.value = !nowPlayingOpen.value; }

  return {
    queueOpen, toggleQueue, closeQueue,
    nowPlayingOpen, openNowPlaying, closeNowPlaying, toggleNowPlaying
  };
});
