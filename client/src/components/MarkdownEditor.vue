<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";

import { useToast } from "@/composables/useToast";
import { EMOJI_PICKER_GROUPS } from "@/data/emojiPickerGroups";
import { markdownToSafeHtml } from "@/utils/markdown";

const toast = useToast();

const props = withDefaults(
  defineProps<{
    modelValue: string;
    placeholder?: string;
    rows?: number;
    /** Upload image via Media API and insert markdown image line. */
    enableImageUpload?: boolean;
    /** When set, typing @ shows a user picker (ticket / staff context). */
    mentionUsers?: { id: number; name: string }[];
    /** Taller emoji panel + more columns (ticket replies, chat). */
    expandedEmojiPicker?: boolean;
    /** Match admin dark mode surfaces (borders / textarea). */
    darkSurface?: boolean;
  }>(),
  { enableImageUpload: false, mentionUsers: () => [], expandedEmojiPicker: false, darkSurface: false },
);

const emit = defineEmits<{
  (event: "update:modelValue", value: string): void;
}>();

/** User IDs inserted via @ picker — sent with ticket reply for notifications. */
const mentionedUserIds = defineModel<number[]>("mentionedUserIds", { default: () => [] });

const textareaRef = ref<HTMLTextAreaElement | null>(null);
const imageInputRef = ref<HTMLInputElement | null>(null);
const imageUploading = ref(false);
const mode = ref<"write" | "preview">("write");

const emojiPickerOpen = ref(false);
const emojiGroupKey = ref(EMOJI_PICKER_GROUPS[0]?.key ?? "smileys");
const emojiButtonRef = ref<HTMLButtonElement | null>(null);

const mentionDropdownOpen = ref(false);
const mentionStartIndex = ref(0);
const mentionQuery = ref("");
const mentionSelectedIndex = ref(0);

const safeHtml = computed(() => markdownToSafeHtml(props.modelValue || ""));

const allEmojisDeduped = computed(() => {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const g of EMOJI_PICKER_GROUPS) {
    for (const em of g.emojis) {
      if (!seen.has(em)) {
        seen.add(em);
        out.push(em);
      }
    }
  }
  return out;
});

const emojiTabs = computed(() => {
  if (props.expandedEmojiPicker) {
    return [{ key: "__all__", label: "All", emojis: [] as string[] }, ...EMOJI_PICKER_GROUPS];
  }
  return EMOJI_PICKER_GROUPS;
});

const emojiGridEmojis = computed(() => {
  if (emojiGroupKey.value === "__all__") {
    return allEmojisDeduped.value;
  }
  const g = EMOJI_PICKER_GROUPS.find((x) => x.key === emojiGroupKey.value);
  return g?.emojis ?? [];
});

const tbBtn = computed(() =>
  props.darkSurface
    ? "rounded-md border border-slate-600 px-2 py-1 text-xs text-slate-200 hover:bg-slate-800"
    : "rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50",
);

const emojiPanelWidth = computed(() =>
  props.expandedEmojiPicker ? "w-[min(100vw-1rem,28rem)]" : "w-[min(100vw-2rem,22rem)]",
);

const emojiGridMaxH = computed(() =>
  props.expandedEmojiPicker ? "max-h-[min(50vh,22rem)]" : "max-h-48",
);

const emojiGridCols = computed(() => (props.expandedEmojiPicker ? "grid-cols-10" : "grid-cols-8"));

const mentionCandidates = computed(() => {
  const users = props.mentionUsers ?? [];
  if (!users.length) return [];
  const q = mentionQuery.value.toLowerCase().trim();
  return users.filter((u) => !q || u.name.toLowerCase().includes(q));
});

watch(
  () => props.modelValue,
  (v) => {
    if (!String(v || "").trim()) {
      mentionedUserIds.value = [];
    }
  },
);

function updateContent(value: string) {
  emit("update:modelValue", value);
}

function surroundSelection(prefix: string, suffix = prefix) {
  const el = textareaRef.value;
  if (!el) return;

  const start = el.selectionStart;
  const end = el.selectionEnd;
  const value = props.modelValue || "";
  const selected = value.slice(start, end) || "text";
  const nextValue = `${value.slice(0, start)}${prefix}${selected}${suffix}${value.slice(end)}`;
  updateContent(nextValue);

  requestAnimationFrame(() => {
    el.focus();
    el.setSelectionRange(start + prefix.length, start + prefix.length + selected.length);
  });
}

function insertLine(snippet: string) {
  const el = textareaRef.value;
  if (!el) return;

  const start = el.selectionStart;
  const value = props.modelValue || "";
  const nextValue = `${value.slice(0, start)}${snippet}${value.slice(start)}`;
  updateContent(nextValue);

  requestAnimationFrame(() => {
    el.focus();
    const cursor = start + snippet.length;
    el.setSelectionRange(cursor, cursor);
  });
}

function insertAtCursor(snippet: string) {
  insertLine(snippet);
}

function triggerImagePick() {
  imageInputRef.value?.click();
}

async function onImageSelected(ev: Event) {
  const input = ev.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  imageUploading.value = true;
  try {
    const { uploadMedia } = await import("@/api/cms");
    const res = await uploadMedia(file);
    const url = res.data.url;
    const base = file.name.replace(/\.[^.]+$/, "") || "image";
    insertLine(`![${base}](${url})\n`);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Image upload failed");
  } finally {
    imageUploading.value = false;
    input.value = "";
  }
}

function toggleEmojiPicker() {
  emojiPickerOpen.value = !emojiPickerOpen.value;
}

function pickEmoji(ch: string) {
  insertAtCursor(ch);
  emojiPickerOpen.value = false;
  nextTick(() => textareaRef.value?.focus());
}

function updateMentionStateFromEl(el: HTMLTextAreaElement) {
  const users = props.mentionUsers ?? [];
  if (!users.length) {
    mentionDropdownOpen.value = false;
    return;
  }
  const cursor = el.selectionStart ?? 0;
  const before = el.value.slice(0, cursor);
  const lastAt = before.lastIndexOf("@");
  if (lastAt < 0) {
    mentionDropdownOpen.value = false;
    return;
  }
  const prev = lastAt === 0 ? " " : before[lastAt - 1];
  if (!(prev === " " || prev === "\n" || lastAt === 0)) {
    mentionDropdownOpen.value = false;
    return;
  }
  const afterAt = before.slice(lastAt + 1);
  if (afterAt.includes("\n")) {
    mentionDropdownOpen.value = false;
    return;
  }
  mentionStartIndex.value = lastAt;
  mentionQuery.value = afterAt;
  mentionDropdownOpen.value = true;
  mentionSelectedIndex.value = 0;
}

function onTextareaInput(e: Event) {
  const el = e.target as HTMLTextAreaElement;
  updateContent(el.value);
  updateMentionStateFromEl(el);
}

function insertMention(item: { id: number; name: string }) {
  const el = textareaRef.value;
  if (!el) return;
  const text = props.modelValue || "";
  const start = mentionStartIndex.value;
  const end = start + 1 + mentionQuery.value.length;
  const before = text.slice(0, start);
  const after = text.slice(end);
  const replacement = `@${item.name} `;
  updateContent(before + replacement + after);
  mentionDropdownOpen.value = false;
  const ids = [...(mentionedUserIds.value ?? [])];
  if (!ids.includes(item.id)) {
    ids.push(item.id);
  }
  mentionedUserIds.value = ids;
  nextTick(() => {
    el.focus();
    const pos = start + replacement.length;
    el.setSelectionRange(pos, pos);
  });
}

function handleTextareaKeydown(e: KeyboardEvent) {
  if (mentionDropdownOpen.value && mentionCandidates.value.length) {
    const cand = mentionCandidates.value;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      mentionSelectedIndex.value = (mentionSelectedIndex.value + 1) % cand.length;
      return;
    }
    if (e.key === "ArrowUp") {
      e.preventDefault();
      mentionSelectedIndex.value = (mentionSelectedIndex.value - 1 + cand.length) % cand.length;
      return;
    }
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      insertMention(cand[mentionSelectedIndex.value]!);
      return;
    }
    if (e.key === "Escape") {
      e.preventDefault();
      mentionDropdownOpen.value = false;
      return;
    }
  }
}

function onDocPointerDown(e: MouseEvent) {
  const t = e.target as Node;
  if (emojiButtonRef.value?.contains(t)) return;
  const panel = (e.target as HTMLElement).closest?.("[data-emoji-picker-panel]");
  if (panel) return;
  emojiPickerOpen.value = false;
}

onMounted(() => {
  if (props.expandedEmojiPicker) {
    emojiGroupKey.value = "__all__";
  }
  document.addEventListener("pointerdown", onDocPointerDown, true);
});

onUnmounted(() => {
  document.removeEventListener("pointerdown", onDocPointerDown, true);
});
</script>

<template>
  <div
    class="rounded-lg border bg-white"
    :class="
      darkSurface
        ? 'border-slate-600 bg-slate-950 text-slate-100'
        : 'border-slate-200 bg-white'
    "
  >
    <div
      class="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2"
      :class="darkSurface ? 'border-slate-700' : 'border-slate-100'"
    >
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="rounded-md px-2 py-1 text-xs font-medium transition-colors"
          :class="
            mode === 'write'
              ? darkSurface
                ? 'bg-violet-600 text-white'
                : 'bg-slate-900 text-white'
              : darkSurface
                ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          "
          @click="mode = 'write'"
        >
          Write
        </button>
        <button
          type="button"
          class="rounded-md px-2 py-1 text-xs font-medium transition-colors"
          :class="
            mode === 'preview'
              ? darkSurface
                ? 'bg-violet-600 text-white'
                : 'bg-slate-900 text-white'
              : darkSurface
                ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          "
          @click="mode = 'preview'"
        >
          Preview
        </button>
      </div>
      <div v-if="mode === 'write'" class="flex flex-wrap items-center gap-1">
        <button type="button" :class="tbBtn" @click="surroundSelection('**')">Bold</button>
        <button type="button" :class="tbBtn" @click="surroundSelection('_')">Italic</button>
        <button type="button" :class="tbBtn" @click="surroundSelection('<u>', '</u>')">Underline</button>
        <button type="button" :class="tbBtn" @click="surroundSelection('~~')">Strike</button>
        <button type="button" :class="tbBtn" @click="insertLine('## Heading\\n')">H2</button>
        <button type="button" :class="tbBtn" @click="insertLine('- List item\\n')">List</button>
        <button type="button" :class="tbBtn" @click="insertLine('[Link text](https://example.com)\\n')">Link</button>
        <button type="button" :class="tbBtn" @click="insertLine('![Image alt](https://example.com/image.png)\\n')">Image</button>
        <button type="button" :class="tbBtn" @click="insertLine('[Menu link](/admin/kerisi/ticket)\\n')">Menu Link</button>
        <div class="relative inline-block">
          <button
            ref="emojiButtonRef"
            type="button"
            :class="[
              tbBtn,
              emojiPickerOpen
                ? darkSurface
                  ? 'border-violet-500 bg-violet-950/50'
                  : 'border-violet-400 bg-violet-50'
                : '',
            ]"
            @click.stop="toggleEmojiPicker"
          >
            Emoji
          </button>
          <div
            v-if="emojiPickerOpen"
            data-emoji-picker-panel
            class="absolute left-0 top-full z-50 mt-1 rounded-lg border p-2 shadow-lg"
            :class="[
              emojiPanelWidth,
              darkSurface
                ? 'border-slate-600 bg-slate-900 text-slate-100'
                : 'border-slate-200 bg-white',
            ]"
            @pointerdown.stop
          >
            <div
              class="mb-2 flex max-h-24 flex-wrap gap-1 overflow-y-auto border-b pb-2"
              :class="darkSurface ? 'border-slate-700' : 'border-slate-100'"
            >
              <button
                v-for="g in emojiTabs"
                :key="g.key"
                type="button"
                class="shrink-0 rounded px-2 py-0.5 text-[10px] font-medium"
                :class="
                  emojiGroupKey === g.key
                    ? darkSurface
                      ? 'bg-violet-600 text-white'
                      : 'bg-slate-900 text-white'
                    : darkSurface
                      ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                "
                @click="emojiGroupKey = g.key"
              >
                {{ g.label }}
              </button>
            </div>
            <div class="overflow-y-auto" :class="emojiGridMaxH">
              <div class="grid gap-0.5" :class="emojiGridCols">
                <button
                  v-for="(em, idx) in emojiGridEmojis"
                  :key="em + '-' + idx"
                  type="button"
                  class="flex h-9 w-9 items-center justify-center rounded text-lg"
                  :class="darkSurface ? 'hover:bg-slate-800' : 'hover:bg-slate-100'"
                  :title="em"
                  @click="pickEmoji(em)"
                >
                  {{ em }}
                </button>
              </div>
            </div>
          </div>
        </div>
        <button type="button" :class="tbBtn" @click="surroundSelection('`')">Code</button>
        <input
          v-if="enableImageUpload"
          ref="imageInputRef"
          type="file"
          class="hidden"
          accept="image/*"
          @change="onImageSelected"
        />
        <button
          v-if="enableImageUpload"
          type="button"
          :class="[tbBtn, 'disabled:opacity-50']"
          :disabled="imageUploading"
          @click="triggerImagePick"
        >
          {{ imageUploading ? "Uploading…" : "Upload image" }}
        </button>
      </div>
    </div>

    <div v-if="mode === 'write'" class="p-3">
      <div class="relative">
        <textarea
          ref="textareaRef"
          :value="modelValue"
          :rows="rows || 16"
          class="w-full rounded-lg border px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2"
          :class="
            darkSurface
              ? 'border-slate-600 bg-slate-900 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-900/40'
              : 'border-slate-300 focus:border-slate-400 focus:ring-slate-200'
          "
          :placeholder="placeholder || 'Write markdown content...'"
          @input="onTextareaInput"
          @keydown="handleTextareaKeydown"
        />
        <div
          v-if="mentionDropdownOpen && (mentionUsers?.length ?? 0) && mentionCandidates.length"
          class="absolute left-0 right-0 top-full z-40 mt-1 max-h-48 overflow-auto rounded-md border py-1 text-sm shadow-lg"
          :class="darkSurface ? 'border-slate-600 bg-slate-900' : 'border-slate-200 bg-white'"
        >
          <button
            v-for="(item, i) in mentionCandidates"
            :key="item.id"
            type="button"
            class="flex w-full px-3 py-2 text-left text-xs"
            :class="
              i === mentionSelectedIndex
                ? darkSurface
                  ? 'bg-violet-950 text-violet-200'
                  : 'bg-violet-50 text-violet-800'
                : darkSurface
                  ? 'hover:bg-slate-800 text-slate-200'
                  : 'hover:bg-slate-50'
            "
            @mousedown.prevent
            @click="insertMention(item)"
          >
            {{ item.name }}
          </button>
        </div>
      </div>
      <p class="mt-2 text-xs" :class="darkSurface ? 'text-slate-500' : 'text-slate-400'">
        Markdown disokong. Taip
        <code class="rounded px-1" :class="darkSurface ? 'bg-slate-800 text-slate-300' : 'bg-slate-100'">@</code>
        untuk pilih pengguna (notifikasi jika disebut).
        <template v-if="expandedEmojiPicker"> Emoji: tab <strong>All</strong> = keseluruhan set Unicode dalam pemilih. </template>
        Pratonton disanitasi.
      </p>
    </div>

    <div v-else class="markdown-preview p-4" :class="darkSurface ? 'text-slate-200' : ''">
      <div v-if="!modelValue.trim()" class="text-sm text-slate-400">Nothing to preview yet.</div>
      <div v-else v-html="safeHtml" />
    </div>
  </div>
</template>

<style scoped>
.markdown-preview :deep(h1),
.markdown-preview :deep(h2),
.markdown-preview :deep(h3) {
  margin-top: 1rem;
  margin-bottom: 0.5rem;
  font-weight: 700;
  color: rgb(15 23 42);
}

.markdown-preview :deep(p) {
  margin: 0.5rem 0;
  color: rgb(51 65 85);
  line-height: 1.6;
}

.markdown-preview :deep(ul),
.markdown-preview :deep(ol) {
  margin: 0.5rem 0;
  padding-left: 1.25rem;
  color: rgb(51 65 85);
}

.markdown-preview :deep(code) {
  border-radius: 0.25rem;
  background: rgb(241 245 249);
  padding: 0.1rem 0.3rem;
  font-size: 0.85em;
  color: rgb(51 65 85);
}

.markdown-preview :deep(pre) {
  overflow-x: auto;
  border-radius: 0.5rem;
  background: rgb(15 23 42);
  color: rgb(241 245 249);
  padding: 0.75rem;
}

.markdown-preview :deep(pre code) {
  background: transparent;
  padding: 0;
  border-radius: 0;
  color: inherit;
  font-size: 0.85em;
}

.markdown-preview :deep(a) {
  color: rgb(124 58 237);
  text-decoration: underline;
}

.markdown-preview :deep(blockquote) {
  margin: 0.5rem 0;
  border-left: 3px solid rgb(203 213 225);
  padding-left: 0.75rem;
  color: rgb(71 85 105);
}

.markdown-preview :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 0.75rem 0;
  font-size: 0.875rem;
}

.markdown-preview :deep(th),
.markdown-preview :deep(td) {
  border: 1px solid rgb(226 232 240);
  padding: 0.5rem 0.75rem;
  text-align: left;
}

.markdown-preview :deep(th) {
  background: rgb(248 250 252);
  font-weight: 600;
  color: rgb(15 23 42);
}

.markdown-preview :deep(td) {
  color: rgb(51 65 85);
}

.markdown-preview :deep(hr) {
  border: none;
  border-top: 1px solid rgb(226 232 240);
  margin: 1.5rem 0;
}

.markdown-preview :deep(strong) {
  font-weight: 600;
  color: rgb(15 23 42);
}
</style>
