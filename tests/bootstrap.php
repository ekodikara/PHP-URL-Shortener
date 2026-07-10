<?php
/*
 * Test bootstrap. Sets up the minimal constants/globals the pure helper
 * functions need, WITHOUT pulling in config.php (which connects to MySQL as a
 * side effect). This keeps unit tests fast and DB-free.
 */
define('CODE_LENGTH', 6);
define('ALLOWED_CHARS', '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
define('YEARLY_DISCOUNT', 0.12);

$GLOBALS['PLANS'] = array(
    'free'    => array('name' => 'Free trial', 'price' => 0,  'is_trial' => true),
    'pro'     => array('name' => 'Pro',        'price' => 7,  'is_trial' => false),
    'premium' => array('name' => 'Premium',    'price' => 12, 'is_trial' => false),
);
$GLOBALS['RESERVED_SLUGS'] = array('login', 'admin', 'dashboard', 'api', 'mcp', 'health');

// config.php normally defines this; provide the same behaviour for tests.
if (!function_exists('plan_config')) {
    function plan_config($plan)
    {
        return isset($GLOBALS['PLANS'][$plan]) ? $GLOBALS['PLANS'][$plan] : $GLOBALS['PLANS']['free'];
    }
}

require __DIR__ . '/../inc/helpers.php';
