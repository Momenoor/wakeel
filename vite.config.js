import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
                'resources/css/print.css',
                // Loaded by Alpine (x-load) only where they are used: the Sign PDF and Split PDF pages and the
                // SignaturePad field.
                'resources/js/pdf-signer.js',
                'resources/js/pdf-splitter.js',
                'resources/js/signature-pad-field.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            // Alpine's x-load imports an entry and takes its default export (the component): kept, not
            // shaken off as an app's entries' exports otherwise are.
            preserveEntrySignatures: 'exports-only',
            output: {
                // pdf-lib, shared by the Sign PDF and Split PDF pages, in a file of its own name (not whichever
                // small module the two happen to share with it).
                manualChunks: (id) => (id.includes('/node_modules/pdf-lib/') ? 'pdf-lib' : undefined),
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
