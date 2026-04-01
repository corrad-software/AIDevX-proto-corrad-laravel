import { ref, watch } from "vue";
import { defineStore } from "pinia";

import type { ThemeAppearance, ThemeColor } from "@/types";

const COLOR_KEY = "admin.theme.color";
const APPEARANCE_KEY = "admin.theme.appearance";

const THEME_COLORS: ThemeColor[] = ["violet", "blue", "green", "red", "black-white", "grey"];

function isThemeColor(value: string | null): value is ThemeColor {
  return !!value && THEME_COLORS.includes(value as ThemeColor);
}

function isThemeAppearance(value: string | null): value is ThemeAppearance {
  return value === "light" || value === "dark" || value === "system";
}

function systemPrefersDark(): boolean {
  if (typeof window === "undefined") return false;
  return window.matchMedia("(prefers-color-scheme: dark)").matches;
}

export const useUiThemeStore = defineStore("ui-theme", () => {
  const themeColor = ref<ThemeColor>("violet");
  const appearance = ref<ThemeAppearance>("light");

  let mediaListener: ((this: MediaQueryList, ev: MediaQueryListEvent) => void) | null = null;

  function resolveDark(): boolean {
    if (appearance.value === "dark") return true;
    if (appearance.value === "light") return false;
    return systemPrefersDark();
  }

  function applyToDocument() {
    if (typeof document === "undefined") return;
    const root = document.documentElement;
    root.dataset.themeColor = themeColor.value;
    root.classList.toggle("dark", resolveDark());
    root.style.colorScheme = resolveDark() ? "dark" : "light";
  }

  function persist() {
    if (typeof window === "undefined") return;
    localStorage.setItem(COLOR_KEY, themeColor.value);
    localStorage.setItem(APPEARANCE_KEY, appearance.value);
  }

  function attachSystemListener() {
    if (typeof window === "undefined") return;
    const mql = window.matchMedia("(prefers-color-scheme: dark)");
    if (mediaListener) {
      mql.removeEventListener("change", mediaListener);
    }
    mediaListener = () => {
      if (appearance.value === "system") applyToDocument();
    };
    mql.addEventListener("change", mediaListener!);
  }

  function initFromStorage() {
    if (typeof window === "undefined") return;

    const savedAppearance = localStorage.getItem(APPEARANCE_KEY);
    if (isThemeAppearance(savedAppearance)) appearance.value = savedAppearance;

    const savedColor = localStorage.getItem(COLOR_KEY);
    if (isThemeColor(savedColor)) themeColor.value = savedColor;

    applyToDocument();
    attachSystemListener();
  }

  /** Call before Vue paint to reduce flash (see index.html inline script). */
  function initAppearanceFromStorageOnly() {
    if (typeof window === "undefined" || typeof document === "undefined") return;
    const saved = localStorage.getItem(APPEARANCE_KEY);
    if (!isThemeAppearance(saved)) return;
    let dark = false;
    if (saved === "dark") dark = true;
    else if (saved === "system") dark = systemPrefersDark();
    document.documentElement.classList.toggle("dark", dark);
    document.documentElement.style.colorScheme = dark ? "dark" : "light";
  }

  function setThemeColor(color: ThemeColor) {
    themeColor.value = color;
  }

  function setAppearance(value: ThemeAppearance) {
    appearance.value = value;
  }

  watch(themeColor, () => {
    persist();
    applyToDocument();
  });

  watch(appearance, () => {
    persist();
    applyToDocument();
  });

  return {
    themeColor,
    appearance,
    initFromStorage,
    initAppearanceFromStorageOnly,
    setThemeColor,
    setAppearance,
    resolveDark,
  };
});
