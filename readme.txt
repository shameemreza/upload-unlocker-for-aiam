=== Upload Unlocker for All in All Migration ===
Contributors: shameemreza
Tags: migration, upload limit, backup, import, restore
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Removes upload file-size limits, enables backup restore, and raises PHP memory for All-in-One WP Migration.

== Description ==

All-in-One WP Migration is one of the most popular WordPress migration tools, but the free version blocks file uploads above your server's PHP limit. The thing is, the plugin's built-in uploader already splits files into small 5 MB chunks that any server can handle.

**Upload Unlocker for All in All Migration** fixes this by:

1. **Removing the client-side file-size gate** that blocks large imports.
2. **Enabling one-click backup restore** so the "Restore" button on the Backups page just works.
3. **Raising the WordPress-reported upload limit** to unlimited on migration admin pages (your regular Media Library is unaffected).
4. **Boosting PHP memory limit** via `wp_raise_memory_limit()` so large imports have enough room.
5. **Replacing the upsell notice** with a clean confirmation of the new limit.

= How It Works =

* Uses only standard WordPress APIs: `upload_size_limit` filter, `wp_add_inline_script`, and `wp_raise_memory_limit`.
* Backup restore works by registering a lightweight JavaScript class that the base migration plugin's Backups page already checks for. It drives the existing import pipeline in manual mode using the backup file on disk, so no re-upload is needed.
* **Does not modify** any All-in-One WP Migration plugin files.
* All changes are scoped to migration admin screens only.

= Requirements =

* WordPress 5.0+
* PHP 7.4+
* All-in-One WP Migration (free version) must be installed and active.

== Installation ==

1. Upload the `upload-unlocker-for-aiam` folder to `/wp-content/plugins/`.
2. Activate **Upload Unlocker for All in All Migration** from the Plugins screen.
3. Make sure **All-in-One WP Migration** is also active.
4. Go to **All-in-One WP Migration > Import** and import your backup. The file size limit is gone.

== Frequently Asked Questions ==

= Will this work if my hosting has a low upload_max_filesize? =

Yes. All-in-One WP Migration's uploader splits every file into 5 MB chunks. Each individual HTTP request stays well within typical PHP limits. The only thing blocking large imports was a JavaScript check that compared the *total* file size against your server's reported limit, and this plugin removes that check.

= Do I still need to change php.ini or .htaccess? =

Usually not. The plugin automatically raises `memory_limit` at runtime, and All-in-One WP Migration already handles `max_execution_time` internally during each import step. Since the upload happens in 5 MB chunks, `upload_max_filesize` and `post_max_size` only need to be above 5 MB, which almost every host already has.

If your host is unusually restrictive and you still see PHP errors, add this to `.htaccess` (Apache) or `.user.ini` (Nginx / PHP-FPM):

**For .htaccess (Apache):**

`
php_value upload_max_filesize 256M
php_value post_max_size 256M
php_value memory_limit 512M
php_value max_execution_time 600
php_value max_input_time 600
`

**For .user.ini (Nginx / PHP-FPM):**

`
upload_max_filesize = 256M
post_max_size = 256M
memory_limit = 512M
max_execution_time = 600
max_input_time = 600
`

= What does this plugin actually do? =

It hooks into the free version of All-in-One WP Migration using standard WordPress APIs (`upload_size_limit` filter, `wp_add_inline_script`) and raises the limits that the free plugin enforces in JavaScript. The free plugin already uploads in 5 MB chunks and has full server-side restore support. This plugin simply removes the client-side gates that prevent you from using those capabilities with large files.

= Can I use a code snippet instead of installing this plugin? =

Absolutely. Add this to your theme's `functions.php` or use a code-snippets plugin like [SnipDrop](https://github.com/shameemreza/snipdrop):

`
// Raise the WordPress-reported upload limit on migration pages.
add_filter( 'upload_size_limit', function ( $limit ) {
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    if ( str_starts_with( $page, 'ai1wm_' ) ) {
        return 9007199254740991; // Effectively unlimited
    }
    return $limit;
} );

// Replace the upsell text with a simple confirmation.
add_filter( 'ai1wm_pro', function () {
    return '<p class="max-upload-size">Maximum upload file size: Unlimited.</p>';
}, 20 );

// Override the client-side file-size gate and enable backup restore.
add_action( 'admin_enqueue_scripts', function () {
    if ( wp_script_is( 'ai1wm_import', 'enqueued' ) || wp_script_is( 'ai1wm_import', 'registered' ) ) {
        wp_add_inline_script(
            'ai1wm_import',
            'if(typeof ai1wm_uploader!=="undefined"){ai1wm_uploader.max_file_size=Math.max(ai1wm_uploader.max_file_size,9007199254740991);}',
            'after'
        );
    }
    if ( wp_script_is( 'ai1wm_backups', 'enqueued' ) || wp_script_is( 'ai1wm_backups', 'registered' ) ) {
        $js = '(function(){if(typeof Ai1wm==="undefined"||typeof Ai1wm.Import==="undefined")return;'
            . 'Ai1wm.FreeExtensionRestore=function(a,s){'
            . 'var m=new Ai1wm.Import();'
            . 'm.setParams([{name:"storage",value:Date.now().toString()},{name:"archive",value:a},{name:"ai1wm_manual_restore",value:"1"}]);'
            . 'm.start([{name:"priority",value:10}]);};})();';
        wp_add_inline_script( 'ai1wm_backups', $js, 'after' );
    }
}, 99 );

// Raise PHP memory limit on migration pages.
add_action( 'admin_init', function () {
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    if ( str_starts_with( $page, 'ai1wm_' ) ) {
        wp_raise_memory_limit( 'admin' );
    }
} );
`

== Changelog ==

= 1.0.0 =
* Initial release.
* Removes the client-side upload file-size gate.
* Enables one-click backup restore on the Backups page.
* Raises WordPress upload limit to unlimited on migration pages.
* Boosts PHP memory_limit at runtime via wp_raise_memory_limit().
* Replaces the upsell notice with a clear limit confirmation.
