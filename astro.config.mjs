import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://russelraaths.github.io',
  base: '/EveryNationTzaneenAstro',
  integrations: [sitemap()],
});
