# File Permission Guide for BuckarooPayments Plugin

## Understanding Permission Issues

### Installation Methods & Users

Different installation methods run under different system users:

| Installation Method | Runs As | Permissions |
|-------------------|---------|-------------|
| **Shopware Admin GUI** | Web server user (www-data, nginx, apache) | Limited, web server permissions |
| **CLI `bin/console`** | SSH/Terminal user (developer, deploy user) | Depends on logged-in user |
| **Composer install** | SSH/Terminal user | Depends on logged-in user |
| **Deployment scripts** | Deploy user | Depends on deployment configuration |

### Potential Issues

1. **Permission Mismatch**: CLI user creates files that web server can't read
2. **Ownership Conflict**: Web server creates files that CLI user can't modify
3. **Directory Write Access**: Installer can't create directories in `public/`
4. **File Copy Failures**: Source files exist but can't be copied due to permissions

## How This Plugin Handles Permissions

### 1. Graceful Degradation

The plugin **won't fail installation** if asset copying fails:

```php
try {
    $this->copyDirectory($sourceDir, $targetDir);
} catch (\Exception $e) {
    // Log error but continue installation
    error_log('BuckarooPayments: Failed to copy static assets: ' . $e->getMessage());
}
```

✅ Plugin installs successfully even with permission issues  
✅ You can fix assets manually afterward  
✅ Payment functionality remains intact  

### 2. Clear Error Messages

If copying fails, you'll see helpful error messages in logs:

```
BuckarooPayments: Failed to copy static assets to /path/to/public/static: 
Destination directory is not writable. 
Please check file permissions or run manually: php bin/copy-admin-static-assets.php
```

### 3. Manual Fallback

You can always copy assets manually using the CLI script:

```bash
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php
```

Or via Composer:

```bash
composer run post-build
```

## Recommended Solutions

### Solution 1: Proper File Permissions (Best Practice)

Set correct permissions on the plugin directory:

```bash
# Set ownership to web server user
sudo chown -R www-data:www-data custom/plugins/BuckaroPayments

# Set proper permissions
sudo find custom/plugins/BuckaroPayments -type d -exec chmod 755 {} \;
sudo find custom/plugins/BuckaroPayments -type f -exec chmod 644 {} \;

# Make bin scripts executable
sudo chmod +x custom/plugins/BuckaroPayments/bin/*.sh
sudo chmod +x custom/plugins/BuckaroPayments/bin/*.php
```

Replace `www-data` with your web server user:
- **Apache**: `www-data`, `apache`, or `httpd`
- **Nginx**: `nginx` or `www-data`
- **Other**: Check with `ps aux | grep nginx` or `ps aux | grep apache`

### Solution 2: Shared Group Ownership

Allow both CLI user and web server to manage files:

```bash
# Create a shared group (if doesn't exist)
sudo groupadd shopware

# Add users to the group
sudo usermod -a -G shopware www-data
sudo usermod -a -G shopware your-username

# Set group ownership
sudo chgrp -R shopware custom/plugins/BuckaroPayments

# Set permissions with group write
sudo find custom/plugins/BuckaroPayments -type d -exec chmod 775 {} \;
sudo find custom/plugins/BuckaroPayments -type f -exec chmod 664 {} \;

# Set SGID bit so new files inherit group
sudo find custom/plugins/BuckaroPayments -type d -exec chmod g+s {} \;
```

### Solution 3: ACL (Access Control Lists) - Most Flexible

Modern Linux systems support ACLs for fine-grained permissions:

```bash
# Set ACL for web server user
sudo setfacl -R -m u:www-data:rwX custom/plugins/BuckaroPayments

# Set default ACL for new files
sudo setfacl -R -d -m u:www-data:rwX custom/plugins/BuckaroPayments

# Set ACL for your user
sudo setfacl -R -m u:your-username:rwX custom/plugins/BuckaroPayments
sudo setfacl -R -d -m u:your-username:rwX custom/plugins/BuckaroPayments
```

Verify ACLs:
```bash
getfacl custom/plugins/BuckaroPayments
```

### Solution 4: Post-Installation Manual Copy

If installation via GUI fails to copy assets:

```bash
# SSH into server
cd /path/to/shopware

# Run as CLI user
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php

# Fix permissions afterward
sudo chown -R www-data:www-data src/Resources/public/
```

## Installation Workflow by Method

### Installing via Shopware Admin GUI

1. Upload plugin ZIP via Extensions > My extensions > Upload extension
2. GUI installs plugin (runs as www-data)
3. ✅ Assets should copy automatically
4. If assets missing, SSH in and run:
   ```bash
   cd custom/plugins/BuckaroPayments
   php bin/copy-admin-static-assets.php
   sudo chown -R www-data:www-data src/Resources/public/
   ```

### Installing via CLI

```bash
# 1. Place plugin in custom/plugins/
cd /path/to/shopware

# 2. Install plugin
bin/console plugin:refresh
bin/console plugin:install BuckaroPayments --activate

# 3. ✅ Assets should copy automatically

# 4. If permission issues occurred, run manually:
cd custom/plugins/BuckaroPayments
php bin/copy-admin-static-assets.php

# 5. Fix permissions
sudo chown -R www-data:www-data src/Resources/public/

# 6. Install assets
cd /path/to/shopware
bin/console assets:install
bin/console cache:clear
```

### Installing via Composer

```bash
# 1. Add to composer.json or install directly
composer require buckaroo/shopware6

# 2. Activate plugin
bin/console plugin:refresh
bin/console plugin:install BuckaroPayments --activate

# 3. ✅ Assets should copy automatically

# 4. Copy assets manually (if needed)
cd custom/plugins/BuckaroPayments
composer run post-build

# 5. Set permissions
sudo chown -R www-data:www-data src/Resources/public/
```

## Troubleshooting Permission Issues

### Check Current Permissions

```bash
# Check directory ownership
ls -la custom/plugins/BuckaroPayments/src/Resources/

# Check if web server can write
sudo -u www-data test -w custom/plugins/BuckaroPayments/src/Resources/public && echo "Writable" || echo "Not writable"

# Check file permissions
ls -la custom/plugins/BuckaroPayments/src/Resources/public/administration/static/
```

### Check Web Server User

```bash
# For Nginx
ps aux | grep nginx | grep -v grep

# For Apache
ps aux | grep apache | grep -v grep
# or
ps aux | grep httpd | grep -v grep

# Check PHP-FPM user (if using PHP-FPM)
ps aux | grep php-fpm | grep -v grep
```

### Check Error Logs

```bash
# Shopware logs
tail -f var/log/dev.log
tail -f var/log/prod.log

# PHP error log (location varies)
tail -f /var/log/php-fpm/error.log
tail -f /var/log/php/error.log
tail -f /var/log/apache2/error.log
tail -f /var/log/nginx/error.log

# Look for "BuckarooPayments" entries
grep -i "buckaroopayments" /var/log/*.log
```

### Verify Assets Were Copied

```bash
# Check source exists
ls -la custom/plugins/BuckaroPayments/src/Resources/app/administration/static/

# Check targets exist
ls -la custom/plugins/BuckaroPayments/src/Resources/public/administration/static/
ls -la custom/plugins/BuckaroPayments/src/Resources/public/static/

# Count files (should match)
find custom/plugins/BuckaroPayments/src/Resources/app/administration/static/ -type f | wc -l
find custom/plugins/BuckaroPayments/src/Resources/public/administration/static/ -type f | wc -l
find custom/plugins/BuckaroPayments/src/Resources/public/static/ -type f | wc -l
```

## Deployment Best Practices

### Development Environment

```bash
# Use your own user, ensure web server can read
chown -R your-user:www-data custom/plugins/BuckaroPayments
chmod -R 775 custom/plugins/BuckaroPayments
```

### Staging/Production Environment

```bash
# Use web server user for ownership
chown -R www-data:www-data custom/plugins/BuckaroPayments
chmod -R 755 custom/plugins/BuckaroPayments
chmod -R 644 custom/plugins/BuckaroPayments/src/Resources/public/
```

### Automated Deployment

Add to your deployment script:

```bash
#!/bin/bash
# After deploying plugin files:

cd custom/plugins/BuckaroPayments

# Copy assets
php bin/copy-admin-static-assets.php

# Fix permissions
chown -R www-data:www-data src/Resources/public/
chmod -R 755 src/Resources/public/

# Back to Shopware root
cd ../../..

# Install assets globally
bin/console assets:install
bin/console cache:clear
```

## Docker Environments

In Docker, permissions can be tricky. Add to your Dockerfile or entrypoint:

```dockerfile
# Ensure web server owns plugin files
RUN chown -R www-data:www-data /var/www/html/custom/plugins/BuckaroPayments

# Or run asset copy as part of container startup
RUN cd /var/www/html/custom/plugins/BuckaroPayments && \
    php bin/copy-admin-static-assets.php && \
    chown -R www-data:www-data src/Resources/public/
```

## Summary

✅ **Plugin won't fail** due to permission issues - it logs errors and continues  
✅ **Manual fallback** always available: `php bin/copy-admin-static-assets.php`  
✅ **Clear error messages** guide you to the solution  
✅ **Multiple solutions** depending on your server setup  

**Recommended approach**: Use Solution 2 (Shared Group Ownership) or Solution 3 (ACL) for production servers.

