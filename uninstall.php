<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('rb_settings');
delete_option('rb_secret');
delete_option('rb_cache_gen');
delete_option('rb_onboarding_done');
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rb_%' OR option_name LIKE '_transient_timeout_rb_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_rb\_%'");
