import DOMPurify from "dompurify";
import { marked } from "marked";
import { encode as plantumlEncode } from "plantuml-encoder";

marked.setOptions({
  gfm: true,
  breaks: true,
});

const PLANTUML_SERVER = "https://www.plantuml.com/plantuml/svg";

function renderPlantUmlBlock(code: string): string {
  try {
    const encoded = plantumlEncode(code);
    const url = `${PLANTUML_SERVER}/${encoded}`;
    return `<figure class="plantuml-diagram my-3"><img src="${url}" alt="Diagram" loading="lazy" class="max-w-full rounded border border-gray-200" /></figure>`;
  } catch {
    return `<pre class="bg-gray-100 rounded p-2 overflow-x-auto text-xs"><code>${DOMPurify.sanitize(code)}</code></pre>`;
  }
}

marked.use({
  renderer: {
    code({ text, lang }: { text: string; lang?: string }) {
      const language = (lang || "").toLowerCase();
      if (language === "plantuml" || language === "puml") {
        return renderPlantUmlBlock(text);
      }
      return false as unknown as string;
    },
  },
});

export function markdownToSafeHtml(markdown: string) {
  const rawHtml = marked.parse(markdown || "", { async: false }) as string;
  return DOMPurify.sanitize(rawHtml, {
    USE_PROFILES: { html: true },
    ADD_ATTR: ["loading"],
  });
}
