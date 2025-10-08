# BuckarooPayments Plugin - Installation Guide

## ✅ Automated Installation (Yes, it handles everything itself!)

The plugin now **automatically** handles Shopware 6.6.x and 6.7+ compatibility. No manual intervention needed!

### What Happens Automatically

When you install, update, or activate the plugin, it will **automatically**:

1. ✅ Copy static assets from source to both public locations
2. ✅ Detect the Shopware version at runtime
3. ✅ Use the correct asset paths based on version
4. ✅ Work in any client environment (6.5.x - 6.7+)

### Standard Installation Steps

```bash
# 1. Refresh plugin list
bin/console plugin:refresh

# 2. Install and activate the plugin
bin/console plugin:install BuckarooPayments --activate

# 3. Install assets to public directory
bin/console assets:install

# 4. Clear cache
bin/console cache:clear
```

**That's it!** The plugin handles version compatibility automatically.

### What Happens Behind the Scenes

During `plugin:install`, `plugin:update`, or `plugin:activate`, the plugin's `StaticAssetInstaller` automatically:

- Reads assets from: `src/Resources/app/administration/static/`
- Copies to: `src/Resources/public/administration/static/` (for Shopware < 6.7)
- Copies to: `src/Resources/public/static/` (for Shopware >= 6.7)

At runtime, the JavaScript code:
- Detects Shopware version
- Uses `bundles/buckaroopayments/administration/static/` for < 6.7
- Uses `buckaroopayments/static/` for >= 6.7

## Development Workflow

### When Adding New Payment Icons

1. Add the SVG file to: `src/Resources/app/administration/static/`
2. Run the copy script: `composer run post-build`
3. Commit only the source file (in `app/administration/static/`)

### After Building Administration

```bash
# Build administration
bin/build-administration.sh

# Copy static assets to both locations
cd custom/plugins/BuckaroPayments
composer run post-build
```

Or manually:
```bash
php bin/copy-admin-static-assets.php
```

## File Structure

```
BuckaroPayments/
├── src/
│   ├── Resources/
│   │   ├── app/
│   │   │   └── administration/
│   │   │       └── static/           ← SOURCE (version controlled)
│   │   │           ├── ideal.svg
│   │   │           ├── paypal.svg
│   │   │           └── ...
│   │   └── public/
│   │       ├── administration/
│   │       │   └── static/           ← AUTO-COPIED (for Shopware < 6.7)
│   │       └── static/               ← AUTO-COPIED (for Shopware >= 6.7)
│   └── Installers/
│       └── StaticAssetInstaller.php  ← Handles automatic copying
└── bin/
    └── copy-admin-static-assets.php  ← Manual copy script
```

## Supported Shopware Versions

- ✅ Shopware 6.5.x
- ✅ Shopware 6.6.x
- ✅ Shopware 6.7.x
- ✅ Shopware 6.8.x

## Troubleshooting

### Assets not showing in administration?

```bash
# 1. Reinstall plugin assets
bin/console plugin:reinstall BuckarooPayments

# 2. Or manually copy assets
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php

# 3. Install assets
cd ../../../  # back to Shopware root
bin/console assets:install

# 4. Clear cache
bin/console cache:clear
```

### Permission Issues?

If you installed via GUI (runs as www-data) or CLI (runs as your user), there might be permission mismatches:

```bash
# Fix ownership (replace www-data with your web server user)
sudo chown -R www-data:www-data custom/plugins/BuckaroPayments/src/Resources/public/

# If still having issues, copy manually
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php
sudo chown -R www-data:www-data src/Resources/public/
```

**Note**: The plugin is designed to **not fail installation** if asset copying has permission issues. It will log errors and you can copy assets manually afterward.

📖 For detailed permission handling, see `PERMISSION_GUIDE.md`

### Verify assets are in correct location

```bash
# Check source files exist
ls custom/plugins/BuckaroPayments/src/Resources/app/administration/static/

# Check public files for Shopware < 6.7
ls custom/plugins/BuckaroPayments/src/Resources/public/administration/static/

# Check public files for Shopware >= 6.7
ls custom/plugins/BuckaroPayments/src/Resources/public/static/
```

### Check which version detection is being used

Open browser console in Shopware administration:
```javascript
console.log(Shopware.Context.app.config.version);
```

## Additional Resources

- `SHOPWARE_VERSION_COMPATIBILITY.md` - Detailed technical documentation
- `IMPLEMENTATION_SUMMARY.md` - Implementation details
- Shopware Documentation: https://developer.shopware.com/

## Summary

**Yes, the plugin handles everything automatically!** 🎉

When you install the plugin in any Shopware environment (6.5.x through 6.8.x), it will:
1. Auto-detect the version
2. Copy assets to the correct locations
3. Use the right paths at runtime

No manual intervention required from your clients!

