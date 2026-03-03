<?php
/**
 * Plugin Name: Upload Unlocker for All in All Migration
 * Plugin URI:  https://shameem.me/increase-upload-limit-for-all-in-one-wp-migration-plugin/
 * Description: Removes upload file-size limits, enables backup restore, and raises PHP memory for All-in-One WP Migration.
 * Version:     1.0.0
 * Author:      Shameem Reza
 * Author URI:  https://shameem.me
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: upload-unlocker-for-aiam
 *
 * @package Upload_Unlocker_For_AIAM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class AIAM_Upload_Unlocker {

	const VERSION = '1.0.0';

	/**
	 * Practical ceiling for the upload gate.
	 *
	 * AI1WM uses 5 MB chunks so the real file size is irrelevant,
	 * but the JS gate still needs a number.  We use a value high
	 * enough that it will never be the bottleneck (~ 8 PB).  This
	 * is Number.MAX_SAFE_INTEGER so it stays lossless in JavaScript.
	 */
	const UPLOAD_LIMIT = 9007199254740991;

	/**
	 * Basename of the companion migration plugin this add-on targets.
	 */
	const TARGET_PLUGIN = 'all-in-one-wp-migration/all-in-one-wp-migration.php';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up all hooks.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'check_dependency' ) );

		if ( ! $this->is_target_active() ) {
			return;
		}

		add_filter( 'upload_size_limit', array( $this, 'raise_upload_limit' ) );
		add_filter( 'ai1wm_pro', array( $this, 'replace_pro_message' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'patch_client_side_limit' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enable_backup_restore' ), 99 );
		add_action( 'current_screen', array( $this, 'raise_php_limits' ) );
		add_action( 'admin_menu', array( $this, 'remove_promo_menus' ), 99 );
		add_filter( 'plugin_action_links_upload-unlocker-for-aiam/upload-unlocker-for-aiam.php', array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Check whether the target migration plugin is active.
	 *
	 * @return bool
	 */
	private function is_target_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::TARGET_PLUGIN );
	}

	/**
	 * Show an admin notice when the target plugin is missing.
	 *
	 * @return void
	 */
	public function check_dependency(): void {
		if ( $this->is_target_active() ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html__(
						'Upload Unlocker for All in All Migration requires All-in-One WP Migration to be installed and active.',
						'upload-unlocker-for-aiam'
					)
				);
			}
		);
	}

	/**
	 * Bump the PHP memory limit on migration screens so large
	 * imports have enough room.
	 *
	 * Execution-time limits are NOT raised here because the
	 * migration plugin already calls set_time_limit(0) internally
	 * during each import step.
	 *
	 * @return void
	 */
	public function raise_php_limits(): void {
		if ( ! $this->is_migration_screen() ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * Raise the WordPress-reported upload limit on migration pages.
	 *
	 * @param  int $limit Current upload size limit in bytes.
	 * @return int
	 */
	public function raise_upload_limit( int $limit ): int {
		if ( ! $this->is_migration_screen() ) {
			return $limit;
		}
		return max( $limit, self::UPLOAD_LIMIT );
	}

	/**
	 * Replace the upsell notice with an "Unlimited" confirmation
	 * and a minimal credit line.
	 *
	 * @return string
	 */
	public function replace_pro_message(): string {
		return '<p class="max-upload-size">'
			. '<span style="color:#23282d">' . esc_html__( 'Maximum upload file size: Unlimited.', 'upload-unlocker-for-aiam' ) . '</span>'
			. '<br><span style="font-size:12px;color:#999">'
			. '<a href="https://shameem.me/increase-upload-limit-for-all-in-one-wp-migration-plugin/" target="_blank" rel="noopener" style="color:#999">Upload Unlocker</a>'
			. ' &middot; <a href="https://ko-fi.com/shameemreza" target="_blank" rel="noopener" style="color:#999">Buy me a coffee</a>'
			. '</span></p>';
	}

	/**
	 * Override the client-side file-size gate so the built-in
	 * chunked uploader can proceed with large files.
	 *
	 * @return void
	 */
	public function patch_client_side_limit(): void {
		if ( ! $this->is_migration_screen() ) {
			return;
		}

		if ( ! wp_script_is( 'ai1wm_import', 'enqueued' ) && ! wp_script_is( 'ai1wm_import', 'registered' ) ) {
			return;
		}

		$js = sprintf(
			'if(typeof ai1wm_uploader!=="undefined"){ai1wm_uploader.max_file_size=Math.max(ai1wm_uploader.max_file_size,%d);}',
			self::UPLOAD_LIMIT
		);

		wp_add_inline_script( 'ai1wm_import', $js, 'after' );
	}

	/**
	 * Enable one-click backup restore on the Backups page.
	 *
	 * The free migration plugin's backups JS checks for a
	 * FreeExtensionRestore class before showing the upgrade
	 * prompt.  We define that class so clicking "Restore"
	 * feeds the backup file into the existing import pipeline.
	 *
	 * @return void
	 */
	public function enable_backup_restore(): void {
		if ( ! wp_script_is( 'ai1wm_backups', 'enqueued' ) && ! wp_script_is( 'ai1wm_backups', 'registered' ) ) {
			return;
		}

		$js = <<<'JS'
(function() {
	if ( typeof Ai1wm === 'undefined' || typeof Ai1wm.Import === 'undefined' ) {
		return;
	}
	Ai1wm.FreeExtensionRestore = function( archive, size ) {
		var model   = new Ai1wm.Import();
		var storage = Date.now().toString();
		model.setParams([
			{ name: 'storage',               value: storage },
			{ name: 'archive',               value: archive },
			{ name: 'ai1wm_manual_restore',  value: '1' }
		]);
		model.start([
			{ name: 'priority', value: 10 }
		]);
	};
})();
JS;

		wp_add_inline_script( 'ai1wm_backups', $js, 'after' );
	}

	/**
	 * Add action links on the Plugins list page.
	 *
	 * @param  array<string> $links Existing action links.
	 * @return array<string>
	 */
	public function plugin_action_links( array $links ): array {
		$donate = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			'https://ko-fi.com/shameemreza',
			esc_html__( 'Buy me a coffee', 'upload-unlocker-for-aiam' )
		);
		array_unshift( $links, $donate );
		return $links;
	}

	/**
	 * Add meta links to the plugin row on the Plugins page.
	 *
	 * @param  array<string> $meta Existing meta links.
	 * @param  string        $file Plugin basename.
	 * @return array<string>
	 */
	public function plugin_row_meta( array $meta, string $file ): array {
		if ( 'upload-unlocker-for-aiam/upload-unlocker-for-aiam.php' !== $file ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			'https://github.com/shameemreza/upload-unlocker-for-aiam',
			esc_html__( 'GitHub', 'upload-unlocker-for-aiam' )
		);
		$meta[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			'https://ko-fi.com/shameemreza',
			esc_html__( 'Support this project', 'upload-unlocker-for-aiam' )
		);

		return $meta;
	}

	/**
	 * Remove the promotional Schedules and Reset Hub submenus
	 * that are just upsell pages with no real functionality.
	 *
	 * @return void
	 */
	public function remove_promo_menus(): void {
		remove_submenu_page( 'ai1wm_export', 'ai1wm_schedules' );
		remove_submenu_page( 'ai1wm_export', 'ai1wm_reset' );
	}

	/**
	 * Detect whether the current admin screen belongs to the
	 * migration plugin.
	 *
	 * @return bool
	 */
	private function is_migration_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && false !== strpos( $screen->id, 'ai1wm' );
	}
}

AIAM_Upload_Unlocker::get_instance();
