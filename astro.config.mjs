import { defineConfig } from 'astro/config';
import tailwind from '@astrojs/tailwind'; // or whatever integration you use

export default defineConfig({
  site: 'https://entzaneen.co.za',
  base: '/ChurchWebsite',
  integrations: [tailwind()],
});
