<?php
/**
 * Plugin Name: Upload Unlocker for All in All Migration
 * Plugin URI:  https://shameemreza.com/increase-upload-limit-for-all-in-one-wp-migration-plugin/
 * Description: Removes the import size check, adds an Upload backup button, and enables Restore on the Backups page for All-in-One WP Migration.
 * Version:     1.1.1
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

	const VERSION = '1.1.1';

	/**
	 * Practical ceiling for the upload gate.
	 *
	 * The JS gate needs a number, so this one is high enough that
	 * the gate never blocks a file (~ 8 PB).  It is
	 * Number.MAX_SAFE_INTEGER so it stays lossless in JavaScript.
	 *
	 * This only lifts the check in the browser.  The AI1WM import
	 * page sends the whole backup in one request, so the server's
	 * post_max_size and upload_max_filesize still decide what a
	 * browser upload can take.
	 */
	const UPLOAD_LIMIT = 9007199254740991;

	/**
	 * Basename of the companion migration plugin this add-on targets.
	 */
	const TARGET_PLUGIN = 'all-in-one-wp-migration/all-in-one-wp-migration.php';

	/**
	 * Nonce action for the Backups page chunk upload.
	 */
	const UPLOAD_NONCE = 'aiam_upload_chunk';

	/**
	 * Largest chunk the Backups page uploader sends, in bytes.
	 */
	const CHUNK_MAX = 5242880;

	/**
	 * Smallest chunk the uploader steps down to after a 413, in bytes.
	 */
	const CHUNK_MIN = 262144;

	/**
	 * Room left in each request for the other form fields, in bytes.
	 */
	const CHUNK_OVERHEAD = 524288;

	/**
	 * Below this server limit the uploader isn't offered, in bytes.
	 */
	const SERVER_LIMIT_MIN = 1048576;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_upload_script' ), 99 );
		add_action( 'ai1wm_backups_left_end', array( $this, 'render_upload_button' ) );
		add_action( 'wp_ajax_aiam_upload_chunk', array( $this, 'handle_upload_chunk' ) );
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
	 * Bump the PHP memory limit on migration screens.
	 *
	 * This runs on current_screen, which fires on admin page loads
	 * but not in admin-ajax.php, where the import steps run.  So it
	 * doesn't raise memory for the import itself.
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
			. '<a href="https://shameemreza.com/increase-upload-limit-for-all-in-one-wp-migration-plugin/" target="_blank" rel="noopener" style="color:#999">Upload Unlocker</a>'
			. ' &middot; <a href="https://ko-fi.com/shameemreza" target="_blank" rel="noopener" style="color:#999">Buy me a coffee</a>'
			. '</span></p>';
	}

	/**
	 * Override the client-side file-size gate so the import page
	 * accepts files above the reported limit.
	 *
	 * The uploader still posts the whole file in one request, so
	 * the upload only succeeds when it fits under the server's
	 * post_max_size and upload_max_filesize.  For a bigger backup,
	 * copy it into wp-content/ai1wm-backups and use Restore on the
	 * Backups page.
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
	 * The file is already on disk, so nothing is uploaded and the
	 * PHP upload limits don't apply.
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
	 * Print the Upload backup button under the backups list.
	 *
	 * The migration plugin fires this action only when the backups
	 * folder is writable, and also on the paid extensions' Reset
	 * page, so the button is limited to the Backups page here.
	 *
	 * @return void
	 */
	public function render_upload_button(): void {
		if ( ! $this->is_backups_page() ) {
			return;
		}

		if ( 0 === $this->chunk_size() ) {
			printf(
				'<p class="aiam-upload-backup-notice">%s</p>',
				esc_html__( 'Upload backup needs the server to accept uploads of at least 1 MB. Copy the .wpress file into the backups folder with SFTP instead.', 'upload-unlocker-for-aiam' )
			);
			return;
		}

		?>
		<div id="aiam-upload-backup">
			<p>
				<a href="#" id="aiam-upload-backup-button" class="ai1wm-button-gray">
					<i class="ai1wm-icon-upload"></i>
					<?php esc_html_e( 'Upload backup', 'upload-unlocker-for-aiam' ); ?>
				</a>
				<input type="file" id="aiam-upload-backup-file" accept=".wpress" hidden>
			</p>
			<p id="aiam-upload-backup-status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Load the Upload backup script on the Backups page.
	 *
	 * @return void
	 */
	public function enqueue_upload_script(): void {
		if ( ! $this->is_backups_page() || 0 === $this->chunk_size() ) {
			return;
		}

		wp_enqueue_script(
			'aiam-upload-backup',
			plugins_url( 'assets/upload-backup.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);

		$config = array(
			'url'       => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::UPLOAD_NONCE ),
			'chunkSize'    => $this->chunk_size(),
			'minChunkSize' => self::CHUNK_MIN,
			'i18n'         => array(
				/* translators: 1: backup file name, 2: percent uploaded. */
				'progress' => __( 'Uploading %1$s: %2$s%%', 'upload-unlocker-for-aiam' ),
				'done'     => __( 'Upload finished. Reloading the backups list...', 'upload-unlocker-for-aiam' ),
				'wrongExt' => __( 'Choose a .wpress backup made with All-in-One WP Migration.', 'upload-unlocker-for-aiam' ),
				'failed'   => __( 'The upload stopped.', 'upload-unlocker-for-aiam' ),
				'network'  => __( 'The server did not answer.', 'upload-unlocker-for-aiam' ),
				'leave'    => __( 'A backup is still uploading. Leaving this page stops the upload.', 'upload-unlocker-for-aiam' ),
				'tooLarge' => __( 'Your web server turned down even 256 KB pieces. Ask your host to raise its request size limit (client_max_body_size on nginx), or copy the file into wp-content/ai1wm-backups with SFTP.', 'upload-unlocker-for-aiam' ),
			),
		);

		wp_add_inline_script( 'aiam-upload-backup', 'var aiamUpload = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Chunk size for the Backups page uploader, in bytes.
	 *
	 * Each request carries one chunk plus a few form fields, so the
	 * chunk stays under the smaller of post_max_size and
	 * upload_max_filesize with room to spare.  A web server limit
	 * below PHP's can't be read from here, so the script halves the
	 * size whenever a chunk gets a 413.
	 *
	 * @return int Chunk size, or 0 when the server limit is too low.
	 */
	private function chunk_size(): int {
		$limits = array();
		foreach ( array( 'post_max_size', 'upload_max_filesize' ) as $setting ) {
			$bytes = wp_convert_hr_to_bytes( (string) ini_get( $setting ) );
			// A value of 0 means no limit.
			if ( $bytes > 0 ) {
				$limits[] = $bytes;
			}
		}

		$limit = $limits ? min( $limits ) : PHP_INT_MAX;
		if ( $limit < self::SERVER_LIMIT_MIN ) {
			return 0;
		}

		$php_max = $limit - self::CHUNK_OVERHEAD;

		/**
		 * Filters the size of each piece the Upload backup button sends.
		 *
		 * Set it below the web server's request body limit, such as
		 * nginx's client_max_body_size, to skip the 413 step-down.  The
		 * result is kept between 256 KB and what PHP's limits allow.
		 *
		 * @param int $size Piece size in bytes.
		 */
		$size = (int) apply_filters( 'aiam_upload_chunk_size', (int) min( $php_max, self::CHUNK_MAX ) );

		return (int) max( self::CHUNK_MIN, min( $size, $php_max ) );
	}

	/**
	 * Whether the current request is the migration plugin's Backups page.
	 *
	 * @return bool
	 */
	private function is_backups_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the admin page, no data is changed.
		return is_admin() && isset( $_GET['page'] ) && 'ai1wm_backups' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Receive one chunk of a backup uploaded from the Backups page.
	 *
	 * Chunks arrive in order and are appended to <name>.wpress.part
	 * in the backups folder.  The list only shows .wpress files, so
	 * the backup appears once the last chunk renames it.  Each chunk
	 * stays under the PHP upload limits, which is how a backup bigger
	 * than those limits gets onto the server.
	 *
	 * @return void
	 */
	public function handle_upload_chunk(): void {
		if ( false === check_ajax_referer( self::UPLOAD_NONCE, '_ajax_nonce', false ) ) {
			$this->send_upload_error( __( 'Your session expired. Reload the page and try again.', 'upload-unlocker-for-aiam' ), 403 );
		}

		if ( ! current_user_can( 'ai1wm_import_site' ) ) {
			$this->send_upload_error( __( 'Your account is not allowed to upload backups.', 'upload-unlocker-for-aiam' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$name    = isset( $_POST['name'] ) ? sanitize_file_name( wp_basename( wp_unslash( $_POST['name'] ) ) ) : '';
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$total   = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
		$is_last = isset( $_POST['is_last'] ) && '1' === $_POST['is_last'];
		// phpcs:enable

		if ( strlen( $name ) <= 7 || '.wpress' !== strtolower( substr( $name, -7 ) ) ) {
			$this->send_upload_error( __( 'Only .wpress backups made with All-in-One WP Migration can be uploaded here.', 'upload-unlocker-for-aiam' ), 415 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the upload array is checked field by field.
		$chunk = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
		if ( ! is_array( $chunk ) || ! isset( $chunk['error'], $chunk['tmp_name'] ) ) {
			$this->send_upload_error( __( 'The server received no file data. Try again.', 'upload-unlocker-for-aiam' ), 400 );
		}

		if ( UPLOAD_ERR_OK !== (int) $chunk['error'] ) {
			$too_big = in_array( (int) $chunk['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			$this->send_upload_error(
				$too_big
					? __( 'One piece of the upload was bigger than the server allows. Reload the page and try again.', 'upload-unlocker-for-aiam' )
					/* translators: %d: PHP upload error code. */
					: sprintf( __( 'The server could not save part of the upload (PHP upload error %d).', 'upload-unlocker-for-aiam' ), (int) $chunk['error'] ),
				$too_big ? 413 : 400
			);
		}

		if ( ! is_uploaded_file( $chunk['tmp_name'] ) ) {
			$this->send_upload_error( __( 'The server received no file data. Try again.', 'upload-unlocker-for-aiam' ), 400 );
		}

		if ( ! defined( 'AI1WM_BACKUPS_PATH' ) ) {
			$this->send_upload_error( __( 'Could not find the All-in-One WP Migration backups folder.', 'upload-unlocker-for-aiam' ), 500 );
		}

		$target = AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . $name;
		$part   = $target . '.part';

		clearstatcache();

		if ( 0 === $offset && file_exists( $target ) ) {
			$this->send_upload_error(
				/* translators: %s: backup file name. */
				sprintf( __( 'A backup named %s is already in the list. Rename the file or delete that backup, then upload again.', 'upload-unlocker-for-aiam' ), $name ),
				409
			);
		}

		$current = file_exists( $part ) ? (int) filesize( $part ) : 0;
		if ( 0 !== $offset && $current !== $offset ) {
			$this->send_upload_error( __( 'The upload got out of step with the server.', 'upload-unlocker-for-aiam' ), 409, array( 'size' => $current ) );
		}

		if ( $offset + (int) filesize( $chunk['tmp_name'] ) > $total ) {
			$this->send_upload_error( __( 'The upload sent more data than the file size it reported.', 'upload-unlocker-for-aiam' ), 400 );
		}

		// Offset 0 starts over, which replaces a .part file left by an earlier try.
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streams a file of any size without loading it into memory.
		$in      = fopen( $chunk['tmp_name'], 'rb' );
		$out     = fopen( $part, 0 === $offset ? 'wb' : 'ab' );
		$written = ( $in && $out ) ? stream_copy_to_stream( $in, $out ) : false;
		if ( $in ) {
			fclose( $in );
		}
		if ( $out ) {
			fclose( $out );
		}
		// phpcs:enable

		if ( false === $written ) {
			$this->send_upload_error( __( 'Could not write to the backups folder. Check that it has free space and is writable.', 'upload-unlocker-for-aiam' ), 500 );
		}

		clearstatcache();
		$size = (int) filesize( $part );

		if ( ! $is_last ) {
			wp_send_json_success( array( 'name' => $name, 'size' => $size, 'done' => false ) );
		}

		if ( $size !== $total ) {
			wp_delete_file( $part );
			$this->send_upload_error( __( 'The upload ended before the whole file arrived. Upload it again.', 'upload-unlocker-for-aiam' ), 400 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, so this is a plain rename.
		if ( file_exists( $target ) || ! rename( $part, $target ) ) {
			wp_delete_file( $part );
			$this->send_upload_error( __( 'Could not save the finished backup in the backups folder.', 'upload-unlocker-for-aiam' ), 500 );
		}

		wp_send_json_success( array( 'name' => $name, 'size' => $size, 'done' => true ) );
	}

	/**
	 * Stop the request with a JSON error for the Backups page uploader.
	 *
	 * @param  string               $message Error shown to the user.
	 * @param  int                  $status  HTTP status code.
	 * @param  array<string, mixed> $extra   Extra response data.
	 * @return never
	 */
	private function send_upload_error( string $message, int $status, array $extra = array() ) {
		wp_send_json_error( array_merge( array( 'message' => $message ), $extra ), $status );
		exit;
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
