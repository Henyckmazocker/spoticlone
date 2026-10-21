import { defineStore } from 'pinia';
import { apiCall, setCsrfToken, setJwt, getJwt } from '@/services/api';
import { Capacitor } from '@capacitor/core';
import { usePlayerStore } from '@/store/player';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    authChecked: false
  }),
  getters: {
    isAdmin: (s) => !!s.user?.is_admin
  },
  actions: {
    /** Resolve auth state on app start. JWT (mobile/dev) takes precedence over session. */
    async initialize() {
      // Web in production authenticates with the session cookie only: drop any legacy JWT.
      if (!Capacitor.isNativePlatform() && process.env.NODE_ENV === 'production') {
        setJwt(null);
      }
      if (getJwt()) {
        // Trust the stored token; the backend validates it per-request.
        this.isAuthenticated = true;
        this.authChecked = true;
        try {
          // A cheap authenticated call confirms the token still works.
          const me = await apiCall('me');
          if (me?.user) this.user = me.user;
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
      // Only the native app asks for a JWT; the web uses the session cookie.
      const payload = { google_token: googleToken };
      if (Capacitor.isNativePlatform()) payload.client = 'native';
      const data = await apiCall('login', payload);
      this.user = data.user;
      this.isAuthenticated = true;
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.jwt_token) setJwt(data.jwt_token);
    },

    /** Developer/local login: store a hand-minted JWT (for testing without Google). */
    async loginWithToken(token) {
      setJwt(token);
      try {
        // Load the real user so is_admin is known before the first navigation.
        const me = await apiCall('me');
        this.user = me?.user ?? { name: 'Usuario local' };
      } catch (e) {
        setJwt(null);
        throw e;
      }
      this.isAuthenticated = true;
      this.authChecked = true;
    },

    async logout() {
      try { await apiCall('logout'); } catch (e) { /* ignore */ }
      this.clearSession();
    },

    /** Forget the local session without calling the backend (it already rejected us). */
    clearSession() {
      usePlayerStore().stop();
      setJwt(null);
      setCsrfToken(null);
      this.user = null;
      this.isAuthenticated = false;
    }
  }
});
