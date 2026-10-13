import { defineStore } from 'pinia';
import { apiCall, setCsrfToken, setJwt, getJwt } from '@/services/api';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    authChecked: false
  }),
  actions: {
    /** Resolve auth state on app start. JWT (mobile/dev) takes precedence over session. */
    async initialize() {
      if (getJwt()) {
        // Trust the stored token; the backend validates it per-request.
        this.isAuthenticated = true;
        this.authChecked = true;
        try {
          // A cheap authenticated call confirms the token still works.
          await apiCall('me');
        } catch (e) {
          this.logout();
        }
        return;
      }
      try {
        const data = await apiCall('check_auth');
        this.user = data.user;
        this.isAuthenticated = true;
        if (data.csrf_token) setCsrfToken(data.csrf_token);
      } catch (e) {
        this.isAuthenticated = false;
      } finally {
        this.authChecked = true;
      }
    },

    async loginWithGoogle(googleToken) {
      const data = await apiCall('login', { google_token: googleToken });
      this.user = data.user;
      this.isAuthenticated = true;
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.jwt_token) setJwt(data.jwt_token);
    },

    /** Developer/local login: store a hand-minted JWT (for testing without Google). */
    loginWithToken(token) {
      setJwt(token);
      this.isAuthenticated = true;
      this.authChecked = true;
      this.user = { name: 'Usuario local' };
    },

    async logout() {
      try { await apiCall('logout'); } catch (e) { /* ignore */ }
      setJwt(null);
      setCsrfToken(null);
      this.user = null;
      this.isAuthenticated = false;
    }
  }
});
