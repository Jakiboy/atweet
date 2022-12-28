<?php

/**
 * WordPress auto-publish tweet (Beta).
 * Hook: Front filter.
 */
add_filter('template_include', 'templates');
function templates($template) {
    if ( isset($_SERVER['REDIRECT_URL']) ) {
        // App authentication
        if ( $_SERVER['REDIRECT_URL'] == '/twitter/authenticate/' ) {
            return wp_normalize_path('/{path-to}/twitter/authenticate.php');
        }
        // App tweet
        if ( $_SERVER['REDIRECT_URL'] == '/twitter/tweet/' ) {
            return wp_normalize_path('/{path-to}/twitter/tweet.php');
        }
        // App callback
        if ( $_SERVER['REDIRECT_URL'] == '/twitter/callback/' ) {
            return wp_normalize_path('/{path-to}/twitter/callback.php');
        }
        // App index
        if ( $_SERVER['REDIRECT_URL'] == '/twitter/' ) {
            return wp_normalize_path('/{path-to}/twitter/index.php');
        }
    }
    return $template;
}

/**
 * WordPress internal API cron schedule (Beta).
 * Hook: Front filter.
 */
add_filter('cron_schedules', 'internalApiCronSchedule');
function internalApiCronSchedule($schedules) {
    $schedules['30-min'] = [
        'interval' => 1800,
        'display'  => __('Every 30 minutes')
    ];
    return $schedules;
}

/**
 * WordPress internal API (Beta).
 * Hook: Front action.
 */
add_action('init', 'internalApi');
function internalApi() {
    if ( !is_admin() ) {
        require_once wp_normalize_path('/{path-to}/twitter/inc/Twitter.php');
        Twitter::initInternalAPI();
    }
    if ( !wp_next_scheduled('update-twitter-remote-access') ) {
        wp_schedule_event(time(),'30-min','update-twitter-remote-access');
    }
}

/**
 * WordPress internal API cron (Beta).
 * Hook: Admin action.
 */
add_action('update-twitter-remote-access', [$this, 'internalApiCron']);
function internalApiCron() {
    // Notice: Uses wp-cron.php
    require_once wp_normalize_path('/{path-to}/twitter/inc/Twitter.php');
    if ( !Twitter::isMainWebsite() ) {
        $twitter = new Twitter();
        $twitter->refreshToken();
    }
}

/**
 * WordPress auto-publish tweet (Beta).
 * Hook: Admin action.
 */
add_action('publish_post', 'tweet', 10, 2);
function tweet($id, $post) {
    // Notice: Uses wp-cron.php
    require_once wp_normalize_path('/{path-to}/twitter/inc/Twitter.php');
    $twitter = new Twitter();
    $title = $post->post_title;
    $url = get_permalink($id);
    $response = $twitter->tweet("{$title} {$url}");
    $url = $twitter->getShortener() // Shortener t.co
}
