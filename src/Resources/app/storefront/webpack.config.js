const path = require('path');
const fs = require('fs');

// Dynamically resolve Shopware core path
let shopwareCorePath;
if (fs.existsSync(path.resolve(__dirname, '../../../../../../../vendor/shopware/storefront/Resources/app/storefront/src'))) {
    shopwareCorePath = '../../../../../../../vendor/shopware/storefront/Resources/app/storefront/src';
} else if (fs.existsSync(path.resolve(__dirname, '../../../../../../../vendor/shopware/platform/src/Storefront/Resources/app/storefront/src'))) {
    shopwareCorePath = '../../../../../../../vendor/shopware/platform/src/Storefront/Resources/app/storefront/src';
} else {
    throw new Error('Unable to locate Shopware core modules. Verify your Shopware installation.');
}

let shopwareVersion = '6.4'; // Default to pre-6.5
try {
    const versionOutput = require('child_process')
        .execSync(`${path.resolve(__dirname, '../../../../../../../bin/console')} -V`)
        .toString();
    console.log(versionOutput , ' versionOutput')
    const match = versionOutput.match(/(\d+\.\d+)/);

    console.log(match , 'match')
    if (match) {
        shopwareVersion = match[1];
    }
} catch (error) {
    console.log('error ' , error);
}

const isNewerThan65 = parseFloat(shopwareVersion) > 6.5;
console.log(isNewerThan65)
console.log(`Shopware version detected: ${shopwareVersion} (${isNewerThan65 ? '>=6.5' : '<6.5'})`);

module.exports = {
    entry: {
        'buckaroo-payments': path.resolve(__dirname, 'src/main.js'),
    },
    output: {
        path: path.resolve(
            __dirname,
            `dist/storefront/js/${isNewerThan65 ? '' : 'buckaroo-payments'}`
        ),
        filename: isNewerThan65
            ? 'buckaroo-payments/buckaroo-payments.js' // File directly in the js folder
            : 'buckaroo-payments.js', // File inside its folder
    },
    resolve: {
        alias: {
            src: path.resolve(__dirname, shopwareCorePath),
        },
        extensions: ['.js', '.json'],
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env'],
                    },
                },
            },
        ],
    },
};
