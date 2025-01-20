const path = require('path');
const fs = require('fs');

module.exports = {
    mode: 'production',
    entry: './src/main.js',
    output: {
        path: path.resolve(__dirname, '..', '..', 'public'),
        filename: 'buckaroo-payments.js',
    }
};
