<?php
/**
 * SafeSchema intentionally keeps post metadata on uninstall to prevent data loss.
 * Delete _safeschema_json_ld, _safeschema_mode, and _safeschema_validation_hash
 * manually if permanent removal is required.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
