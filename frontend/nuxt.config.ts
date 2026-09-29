import process from "node:process";
export default defineNuxtConfig({
  ssr: true,
  css: ["~/assets/main.css"],
  runtimeConfig: {
    apiInternalUrl: process.env.NUXT_API_INTERNAL_URL || "http://web/api/v1",
    public: { apiBase: process.env.NUXT_PUBLIC_API_BASE || "/api/v1" },
  },
  routeRules: { "/app/**": { ssr: false } },
  app: {
    head: {
      title: "中華復甦更新發展協會",
      meta: [
        { name: "viewport", content: "width=device-width, initial-scale=1" },
      ],
    },
  },
  typescript: { strict: true },
});
