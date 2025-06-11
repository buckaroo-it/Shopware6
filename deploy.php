<?php

namespace Deployer;

require 'recipe/shopware.php';

// Plugin specific configuration
set('plugin_name', 'BuckarooPayments');
set('plugin_path', 'custom/plugins/{{plugin_name}}');

// Plugin deployment tasks
task('plugin:deploy', function () {
    run('cd {{release_path}} && bin/console plugin:refresh');
    run('cd {{release_path}} && bin/console plugin:update {{plugin_name}} --clearCache');
    run('cd {{release_path}} && bin/console plugin:activate {{plugin_name}} --clearCache');
    run('cd {{release_path}} && bin/console cache:clear');
});

// Add plugin deployment to the main deployment process
after('deploy:update_code', 'plugin:deploy'); 