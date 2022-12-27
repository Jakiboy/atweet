<?php
/**
 * Twitter OAuth 2.0 implementation for WordPress.
 */

set_time_limit(0);
ini_set('memory_limit','-1');

defined('ABSPATH') || die('forbidden');

require_once __DIR__ . '/inc/Twitter.php';

// Get authorization
$twitter = new Twitter();
$twitter->authenticate();
exit();
