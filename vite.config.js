import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    resolve: { alias: { vue: 'vue/dist/vue.esm-bundler.js' } },
    plugins: [laravel({ input: ['resources/js/app.js', 'resources/js/mini.js'], refresh: true })],
});
