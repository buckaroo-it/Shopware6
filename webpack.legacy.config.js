/**
 * Webpack configuration for producing a Shopware 6.6 (and below) compatible
 * administration bundle. Shopware 6.6 without the ADMIN_VITE feature flag
 * expects a single JS file at:
 *   Resources/public/administration/js/buckaroo-payments.js
 * and an optional CSS file at:
 *   Resources/public/administration/css/buckaroo-payments.css
 *
 * Shopware 6.7+ uses Vite and reads .vite/entrypoints.json instead.
 * Both builds can coexist in the same directory — Shopware picks the
 * correct one based on the installed version at runtime.
 *
 * Build command: npm run build:legacy
 */

'use strict';

const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

const adminSrc = path.resolve(__dirname, 'src/Resources/app/administration/src');
const adminPublic = path.resolve(__dirname, 'src/Resources/public/administration');

module.exports = {
    mode: 'production',

    entry: path.join(adminSrc, 'main.js'),

    output: {
        path: path.join(adminPublic, 'js'),
        filename: 'buckaroo-payments.js',
        // The bundle is loaded as a plain script tag in the Shopware admin SPA.
        // No module export needed — all registrations happen as side-effects on
        // the global `Shopware` object (Shopware.Component.register, etc.).
        clean: false,
    },

    // The global `Shopware` object is provided by the Shopware administration
    // SPA at runtime. All plugin code accesses it via `const { X } = Shopware;`
    externals: {
        Shopware: 'Shopware',
    },

    module: {
        rules: [
            // Twig templates are imported as plain strings and passed to
            // Shopware.Component.register() as the `template` option.
            // Matches both .html.twig and .twig extensions.
            {
                test: /\.html\.twig$|\.twig$/,
                type: 'asset/source',
            },
            // SCSS → extracted to a separate CSS file so Shopware can
            // serve it independently via administration/css/{name}.css
            {
                test: /\.s?css$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    {
                        loader: 'sass-loader',
                        options: {
                            // Use the modern Dart Sass API
                            api: 'modern',
                        },
                    },
                ],
            },
        ],
    },

    plugins: [
        new MiniCssExtractPlugin({
            // Path is relative to output.path (js/), so '../css/' resolves
            // to administration/css/buckaroo-payments.css
            filename: '../css/buckaroo-payments.css',
        }),
    ],

    optimization: {
        // Keep a single output file — no code splitting for admin extensions
        splitChunks: false,
    },

    // Source maps help with debugging in the Shopware admin
    devtool: 'source-map',
};
