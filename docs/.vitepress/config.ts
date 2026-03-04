import { defineConfig } from "vitepress";

export default defineConfig({
  title: "Cellar",
  description: "Your backups, preserved — Documentation",
  head: [["link", { rel: "icon", href: "/logo.svg" }]],
  themeConfig: {
    logo: "/logo.svg",
    nav: [
      { text: "Guide", link: "/guide/getting-started" },
      { text: "API Reference", link: "/api/" },
      { text: "GitHub", link: "https://github.com/your-org/cellar" },
    ],
    sidebar: [
      {
        text: "Introduction",
        items: [
          { text: "What is Cellar?", link: "/guide/" },
          { text: "Getting Started", link: "/guide/getting-started" },
          { text: "Architecture", link: "/guide/architecture" },
        ],
      },
      {
        text: "Core Concepts",
        items: [
          { text: "Vaults", link: "/guide/vaults" },
          { text: "Storages", link: "/guide/storages" },
          { text: "Scheduling", link: "/guide/scheduling" },
          { text: "Custom Documents", link: "/guide/custom-documents" },
        ],
      },
      {
        text: "Deployment",
        items: [
          { text: "Docker Compose", link: "/guide/deployment" },
          { text: "Configuration", link: "/guide/configuration" },
        ],
      },
      {
        text: "API Reference",
        items: [{ text: "Overview", link: "/api/" }],
      },
    ],
    socialLinks: [
      { icon: "github", link: "https://github.com/your-org/cellar" },
    ],
    footer: {
      message: "Released under the Apache 2.0 License.",
      copyright: "Copyright © 2026 Cellar Contributors",
    },
  },
});
