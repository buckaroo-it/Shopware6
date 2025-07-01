import { join, resolve } from 'path';
import { shopwareAdminViteConfig } from '@shopware-ag/admin-vite-plugin';

export default shopwareAdminViteConfig({
    base: '',
    build: {
        outDir: resolve(__dirname, 'dist'),
        emptyOutDir: true,
        rollupOptions: {
            input: {
                app: resolve(__dirname, 'src', 'main.js'),
            },
        },
    },
    resolve: {
        alias: {
            '@': join(__dirname, 'src'),
        },
    },
}); 