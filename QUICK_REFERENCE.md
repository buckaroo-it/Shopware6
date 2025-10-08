# Quick Reference - BuckarooPayments Plugin

## 🎯 TL;DR - Does it handle everything itself?

**YES!** ✅ The plugin automatically handles Shopware 6.5.x - 6.8.x compatibility.

When installed via **any method** (GUI or CLI), it will:
1. ✅ Auto-detect Shopware version
2. ✅ Copy assets to correct locations
3. ✅ Use proper paths at runtime
4. ✅ Work without manual intervention

**Even if permission issues occur**, the plugin won't fail - it logs errors and you can fix assets manually.

---

## 📦 Standard Installation

```bash
bin/console plugin:refresh
bin/console plugin:install BuckarooPayments --activate
bin/console assets:install
bin/console cache:clear
```

**Done!** Assets are automatically copied during installation.

---

## 🔧 If Assets Don't Show (Permission Issues)

```bash
# Manual asset copy
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php

# Fix permissions (replace www-data with your web server user)
sudo chown -R www-data:www-data src/Resources/public/

# Install and clear cache
cd ../../..
bin/console assets:install
bin/console cache:clear
```

---

## 🛠️ Development Workflow

### Adding New Payment Icon

```bash
# 1. Add SVG to source
cp new-icon.svg src/Resources/app/administration/static/

# 2. Copy to public locations
composer run post-build

# 3. Done! Commit only the source file
git add src/Resources/app/administration/static/new-icon.svg
git commit -m "Add new payment icon"
```

### After Building Administration

```bash
# In Shopware root
bin/build-administration.sh

# In plugin directory
cd custom/plugins/BuckaroPayments
composer run post-build
```

---

## 📁 File Structure (What to Know)

```
✅ VERSION CONTROLLED:
   src/Resources/app/administration/static/  ← Source (edit here)

🚫 AUTO-GENERATED (don't edit, don't commit):
   src/Resources/public/administration/static/  ← For Shopware < 6.7
   src/Resources/public/static/                 ← For Shopware >= 6.7
```

---

## 🔍 Verify Installation

```bash
# Check version detection (in browser console)
console.log(Shopware.Context.app.config.version);

# Check files exist
ls custom/plugins/BuckaroPayments/src/Resources/public/administration/static/
ls custom/plugins/BuckaroPayments/src/Resources/public/static/

# Both should have same number of files as source
ls custom/plugins/BuckaroPayments/src/Resources/app/administration/static/
```

---

## 🐛 Troubleshooting

| Problem | Solution |
|---------|----------|
| Assets not showing | Run `php bin/copy-admin-static-assets.php` |
| Permission denied | Fix ownership: `sudo chown -R www-data:www-data src/Resources/public/` |
| 404 errors in console | Run `bin/console assets:install` |
| Wrong version detected | Check `Shopware.Context.app.config.version` in console |

---

## 📖 Detailed Documentation

- `INSTALL_GUIDE.md` - Full installation instructions
- `PERMISSION_GUIDE.md` - File permission handling for all scenarios
- `SHOPWARE_VERSION_COMPATIBILITY.md` - Technical details on version compatibility
- `IMPLEMENTATION_SUMMARY.md` - Implementation details and workflow

---

## 🎬 Installation Methods Comparison

### Via Admin GUI
✅ Automatic asset copy  
⚠️ May have permission issues (runs as www-data)  
🔧 Fallback: SSH in and run `php bin/copy-admin-static-assets.php`

### Via CLI
✅ Automatic asset copy  
⚠️ May have permission issues (runs as your user)  
🔧 Fallback: Fix permissions or run manually

### Via Composer
✅ Automatic asset copy  
✅ Can run `composer run post-build` afterward  
🔧 Same permission considerations as CLI

**All methods work!** Permission issues are handled gracefully with manual fallback.

---

## 💡 Key Features

1. **Cross-Version Compatible**: Works on Shopware 6.5.x through 6.8.x
2. **Automatic Detection**: Runtime version detection, no configuration needed
3. **Graceful Degradation**: Won't fail installation on permission issues
4. **Manual Fallback**: Always can copy assets manually
5. **Single Source**: One place for all static assets
6. **Clear Errors**: Helpful error messages guide you to solution

---

## 🎯 Remember

- ✅ Plugin **handles everything automatically**
- ✅ **Won't fail** on permission issues
- ✅ **Manual fallback** always available
- ✅ Works in **any client environment**
- ✅ **Zero configuration** required

**Just install and it works!** 🚀

