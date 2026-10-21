import { createRouter, createWebHashHistory } from 'vue-router';
import { useAuthStore } from '@/store/auth';
import { onUnauthorized } from '@/services/api';

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { public: true } },
  { path: '/', redirect: '/home' },
  { path: '/home', name: 'home', component: () => import('@/views/Home.vue') },
  { path: '/artists', name: 'artists', component: () => import('@/views/Artists.vue') },
  { path: '/artist/:id', name: 'artist', component: () => import('@/views/ArtistDetail.vue'), props: true },
  { path: '/album/:id', name: 'album', component: () => import('@/views/AlbumDetail.vue'), props: true },
  { path: '/search', name: 'search', component: () => import('@/views/Search.vue') },
  { path: '/discover', name: 'discover', component: () => import('@/views/Discover.vue') },
  { path: '/descargas', name: 'downloads', component: () => import('@/views/Downloads.vue'), meta: { admin: true } },
  { path: '/playlists', name: 'playlists', component: () => import('@/views/Playlists.vue') },
  { path: '/playlist/:id', name: 'playlist', component: () => import('@/views/PlaylistDetail.vue'), props: true },
  { path: '/favorites', name: 'favorites', component: () => import('@/views/Favorites.vue') },
  { path: '/scan', name: 'scan', component: () => import('@/views/AdminScan.vue'), meta: { admin: true } },
  { path: '/:pathMatch(.*)*', redirect: '/home' }
];

// Hash history works both on the web and inside the Capacitor webview.
const router = createRouter({
  history: createWebHashHistory(),
  routes
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  if (!auth.authChecked) {
    await auth.initialize();
  }
  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login' };
  }
  if (to.meta.admin && !auth.isAdmin) {
    return { name: 'home' };
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'home' };
  }
  return true;
});

// Session/JWT expired mid-use: any authenticated call answering 401 sends the user to the login.
onUnauthorized(() => {
  useAuthStore().clearSession();
  if (!router.currentRoute.value.meta.public) router.push({ name: 'login' });
});

export default router;
