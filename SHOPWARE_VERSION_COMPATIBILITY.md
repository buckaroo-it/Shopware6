# Shopware Version Compatibility Guide

## Asset Management Changes in Shopware 6.7+

### The Problem

Starting with Shopware 6.7, the administration build system changed from Webpack to Vite, which affects how static assets are handled:

- **Shopware < 6.7**: Static assets are served from `public/bundles/[plugin]/administration/static/`
- **Shopware >= 6.7**: Static assets are served from `public/bundles/[plugin]/static/`

When running `bin/build-administration.sh`, Shopware removes files from one location depending on the version, which creates compatibility issues for plugins that need to support multiple versions.

### The Solution

This plugin implements a **dual-location strategy** with automated asset copying:

#### 1. Source Files Location

All source static assets (SVG icons, images) should be maintained in:
```
src/Resources/app/administration/static/
```

This is the single source of truth for all administration static assets.

#### 2. Post-Build Asset Copying

After building the administration, run the post-build script to copy assets to both public locations:

```bash
composer run post-build
```

Or directly:
```bash
php bin/copy-admin-static-assets.php
```

This script copies assets from the source location to both:
- `src/Resources/public/administration/static/` (for Shopware < 6.7)
- `src/Resources/public/static/` (for Shopware >= 6.7)

#### 3. Runtime Path Resolution

The JavaScript code automatically detects the Shopware version and uses the correct asset path:

```javascript
computed: {
    isShopware67OrNewer() {
        const version = Shopware.Context.app.config.version || '';
        const versionParts = version.split('.');
        const majorVersion = parseInt(versionParts[0], 10);
        const minorVersion = parseInt(versionParts[1], 10);
        return majorVersion > 6 || (majorVersion === 6 && minorVersion >= 7);
    }
},
methods: {
    getPaymentImagePath(logo) {
        const basePath = this.isShopware67OrNewer 
            ? 'buckaroopayments/static/' 
            : 'bundles/buckaroopayments/administration/static/';
        return this.assetFilter(basePath + logo);
    }
}
```

### Build Workflow

When building the plugin for distribution or deployment:

1. **Build Administration Assets**:
   ```bash
   cd path/to/shopware/root
   bin/build-administration.sh
   ```

2. **Copy Static Assets**:
   ```bash
   cd custom/plugins/BuckarooPayments
   composer run post-build
   ```

3. **Install Assets** (in client environment):
   ```bash
   bin/console assets:install
   ```

### Adding New Static Assets

When adding new static assets (e.g., payment method icons):

1. Add the file to `src/Resources/app/administration/static/`
2. Run `composer run post-build` to copy to public locations
3. Reference it in your component using `getPaymentImagePath()`

### Why This Approach?

**Advantages:**
- ✅ Single source of truth for assets
- ✅ Automatic compatibility with both Shopware < 6.7 and >= 6.7
- ✅ No manual file duplication needed
- ✅ Works in any client environment
- ✅ Follows Shopware best practices

**Alternative Approaches Considered:**

1. **Import assets in JavaScript** - Not suitable for dynamic asset loading
2. **Version-specific builds** - Creates maintenance overhead
3. **Manual duplication** - Error-prone and hard to maintain
4. **Symlinks** - Not portable across different operating systems

### For Plugin Developers

If you maintain this plugin:

- Keep all source static assets in `src/Resources/app/administration/static/`
- Never manually edit files in `src/Resources/public/`
- Always run `composer run post-build` after adding/modifying static assets
- The `public/` directories are generated and should be treated as build artifacts

### For Plugin Users

When installing or updating this plugin:

1. Install the plugin normally
2. Build administration if you've made changes: `bin/build-administration.sh`
3. Run: `bin/console assets:install`
4. Clear cache: `bin/console cache:clear`

The plugin will automatically detect your Shopware version and use the correct asset paths.

