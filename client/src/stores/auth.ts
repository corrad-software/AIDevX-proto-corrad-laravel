import { defineStore } from "pinia";

import { getMe, login, logout, updateProfile as apiUpdateProfile, changePassword as apiChangePassword, uploadAvatar as apiUploadAvatar, removeAvatar as apiRemoveAvatar } from "@/api/auth";
import { ensureCsrfCookie } from "@/api/client";
import type { User } from "@/types";

export const useAuthStore = defineStore("auth", {
  state: () => ({
    user: null as User | null,
    loading: false,
    initialized: false,
  }),
  getters: {
    isAuthenticated: (state) => Boolean(state.user),
  },
  actions: {
    async initialize() {
      if (this.initialized) return;
      this.initialized = true;
      try {
        await ensureCsrfCookie();
        const response = await getMe();
        this.user = response.data.user;
      } catch {
        this.user = null;
      }
    },
    async signIn(email: string, password: string) {
      this.loading = true;
      try {
        // Use user from login response — avoids second /me round-trip (fixes "Invalid response from server"
        // when /me returned non-JSON e.g. session/cookie or proxy issues).
        const res = await login(email, password);
        this.user = res.data.user;
      } finally {
        this.loading = false;
      }
    },
    async signOut() {
      await logout();
      this.user = null;
    },
    async updateProfile(data: { name?: string; email?: string }) {
      const response = await apiUpdateProfile(data);
      this.user = response.data.user;
    },
    async changePassword(data: { currentPassword: string; newPassword: string }) {
      await apiChangePassword(data);
    },
    async uploadAvatar(file: File) {
      const response = await apiUploadAvatar(file);
      this.user = response.data.user;
    },
    async removeAvatar() {
      const response = await apiRemoveAvatar();
      this.user = response.data.user;
    },
    async refreshUser() {
      const response = await getMe();
      this.user = response.data.user;
    },
    /** Clear local user when API returns 401 (expired session) without calling logout endpoint. */
    clearStaleSession() {
      this.user = null;
    },
  },
});
