<template>
  <transition name="queue-fade">
    <div v-if="ui.queueOpen" class="queue-backdrop" @click="ui.closeQueue()"></div>
  </transition>

  <transition name="queue-slide">
    <aside v-if="ui.queueOpen" class="queue-panel" :class="{ 'over-now-playing': ui.nowPlayingOpen }" aria-label="Cola de reproducción">
      <header class="queue-head">
        <h2>Cola</h2>
        <div style="flex:1"></div>
        <button class="icon-btn" title="Limpiar lo siguiente" :disabled="!upcoming.length" @click="player.clearUpcoming()">
          <Icon name="remove" />
        </button>
        <button class="icon-btn" title="Cerrar" @click="ui.closeQueue()">
          <Icon name="logout" />
        </button>
      </header>

      <div class="queue-scroll">
        <template v-if="cur">
          <div class="queue-section">Reproduciendo ahora</div>
          <div class="queue-row playing">
            <img v-if="cur.cover_url" class="queue-cover" :src="resolveUrl(cur.cover_url)" alt="" />
            <div v-else class="queue-cover cover-placeholder">♪</div>
            <div class="queue-info">
              <div class="queue-title">{{ cur.title }}</div>
              <div class="queue-artist">{{ cur.artist }}</div>
            </div>
          </div>
        </template>

        <template v-if="upcoming.length">
          <div class="queue-section">A continuación</div>
          <draggable
            v-model="upcoming"
            item-key="_qid"
            handle=".queue-drag"
            :animation="150"
            :delay="180"
            :delay-on-touch-only="true"
            :touch-start-threshold="6"
            ghost-class="queue-ghost"
            class="queue-list"
          >
            <template #item="{ element, index: i }">
              <div class="queue-row" @click="jump(i)">
                <button class="icon-btn queue-drag" title="Reordenar" @click.stop>
                  <Icon name="drag" />
                </button>
                <img v-if="element.cover_url" class="queue-cover" :src="resolveUrl(element.cover_url)" alt="" />
                <div v-else class="queue-cover cover-placeholder">♪</div>
                <div class="queue-info">
                  <div class="queue-title">{{ element.title }}</div>
                  <div class="queue-artist">{{ element.artist }}</div>
                </div>
                <button class="icon-btn" title="Quitar de la cola" @click.stop="remove(i)">
                  <Icon name="remove" :size="16" />
                </button>
              </div>
            </template>
          </draggable>
        </template>

        <p v-if="!cur && !upcoming.length" class="muted" style="padding:16px">La cola está vacía.</p>
      </div>
    </aside>
  </transition>
</template>

<script setup>
import { computed } from 'vue';
import draggable from 'vuedraggable';
import { usePlayerStore } from '@/store/player';
import { useUiStore } from '@/store/ui';
import { resolveUrl } from '@/services/api';
import Icon from '@/components/Icon.vue';

const player = usePlayerStore();
const ui = useUiStore();

const cur = computed(() => player.current);

// "A continuación" = lo que hay tras el índice actual. El getter expone los elementos con un
// id estable (_qid) para que draggable los identifique; el setter reescribe ese tramo de la
// cola con el nuevo orden (la pista actual y su índice no se tocan).
const upcoming = computed({
  get() {
    const start = player.index + 1;
    return player.queue.slice(start).map((t, i) => ({ ...t, _qid: `${start + i}:${t.id}` }));
  },
  set(reordered) {
    player.setUpcoming(reordered.map(({ _qid, ...t }) => t));
  }
});

function jump(relIndex) { player.jumpTo(player.index + 1 + relIndex); }
function remove(relIndex) { player.removeAt(player.index + 1 + relIndex); }
</script>
