<?php
declare(strict_types=1);

return [
    'app_name' => 'Better YouTube History',
    'web_base' => getenv('WEB_BASE') ?: '',
    'admin_username' => getenv('ADMIN_USERNAME') ?: 'admin',
    // Generate with: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',
    // 32+ chars recommended; set APP_KEY in server env outside web root.
    'app_key' => getenv('APP_KEY') ?: '',
];
