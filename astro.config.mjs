import { defineConfig } from 'astro/config';
import tailwind from '@astrojs/tailwind';

export default defineConfig({
  site: 'https://entzaneen.co.za',
  base: '/ChurchWebsite/',
  integrations: [tailwind()],
});
