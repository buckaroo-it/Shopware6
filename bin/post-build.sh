#!/bin/bash

# Post-build script for Buckaroo Payments Plugin
# This script runs after administration build to ensure assets are in correct locations

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_ROOT="$(dirname "$SCRIPT_DIR")"

echo "========================================="
echo "Running post-build tasks..."
echo "========================================="

# Copy static assets to both locations for cross-version compatibility
php "$SCRIPT_DIR/copy-admin-static-assets.php"

echo ""
echo "Post-build tasks completed!"

