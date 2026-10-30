=== Upload Unlocker for All in All Migration ===
Contributors: shameemreza
Tags: migration, upload limit, backup, import, restore
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Removes the import size check, adds an Upload backup button, and enables Restore on the Backups page for All-in-One WP Migration.

== Description ==

The free version of All-in-One WP Migration stops imports above your server's upload limit. Its Backups page also sends you to a paid extension when you click Restore.

**Upload Unlocker for All in All Migration** changes that:

1. **Removes the size check on the Import page**, so the page shows Unlimited and lets you pick a backup of any size.
2. **Adds an Upload backup button on the Backups page.** It sends the backup in pieces small enough for your server's PHP limits, so a backup of any size gets into the list without SFTP.
3. **Enables Restore on the Backups page.** Click Restore next to any backup in the list. The file is already on the server, so your PHP upload limits don't matter there.
4. **Raises the WordPress-reported upload limit** to unlimited on migration admin pages. Your Media Library is unaffected.
5. **Raises the PHP memory limit** with `wp_raise_memory_limit()` when a migration admin page loads.
6. **Replaces the upsell notice** and hides the Schedules and Reset Hub upsell menus.

= The Import page still has your server's limits =

The free All-in-One WP Migration plugin sends the whole backup in one request from the Import page. This plugin can't change your server's `post_max_size` or `upload_max_filesize`. If the backup is bigger than either one, the Import page still fails, even though it says Unlimited. You'll see "POST Content-Length exceeds the limit", then "Could not open file for reading".

You can get around it in three ways:

* Upload it with the Upload backup button on the Backups page, then click Restore. This works on any server that accepts uploads of 1 MB or more.
* Copy it into `wp-content/ai1wm-backups` with SFTP or your host's file manager, then click Restore.
* Raise both PHP limits above the size of your backup. The Import page then works, with or without this plugin.

= Restore a large backup from the Backups page =

1. Go to **All-in-One WP Migration > Backups**.
2. Click **Upload backup** and choose the `.wpress` file. Keep the page open until it reloads with the backup in the list.
3. Click **Restore** next to the backup.

If you don't see the Upload backup button, the backups folder isn't writable. If you see a note about 1 MB in its place, your server's upload limit is below 1 MB. Copy the file into `wp-content/ai1wm-backups` with SFTP instead, then go to step 3.

I tested this on October 30, 2026 with WordPress 7.1.4, PHP 8.3, All-in-One WP Migration 7.113, and a server capped at 8 MB. A 36 MB backup failed on the Import page. The Upload backup button sent it in pieces of 5 MB or less, and it restored fully from the Backups page. Copying it into the folder by hand and restoring worked too.

The full walkthrough, including how to raise the PHP limits, is in [Increase the All-in-One WP Migration upload limit for free](https://shameemreza.com/increase-upload-limit-for-all-in-one-wp-migration-plugin/).

= How It Works =

* Uses only standard WordPress APIs: `upload_size_limit` filter, `wp_add_inline_script`, and `wp_raise_memory_limit`.
* Upload backup posts the file to `admin-ajax.php` in pieces of up to 5 MB, each under your `post_max_size` and `upload_max_filesize`. The pieces are joined in the backups folder, and the backup shows in the list once the last one arrives. Only users who can import sites can use it, and it only accepts `.wpress` files.
* Restore works by registering a small JavaScript class, `Ai1wm.FreeExtensionRestore`, that the Backups page already checks for. It runs the migration plugin's own import in manual mode with the backup file on disk, so there's no re-upload.
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
4. For a backup bigger than your server's upload limit, go to **All-in-One WP Migration > Backups**, click **Upload backup**, then click **Restore** next to it. A smaller backup imports from **All-in-One WP Migration > Import** as usual.

== Frequently Asked Questions ==

= Will this work if my hosting has a low upload_max_filesize? =

On the Backups page, yes. Upload backup sends the file in pieces that fit under your limits, and Restore reads it from the server's disk. The server needs to accept uploads of at least 1 MB.

On the Import page, no. The free All-in-One WP Migration plugin sends the whole file in one request there, so a backup bigger than `upload_max_filesize` or `post_max_size` fails. Use the Backups page, or raise both limits.

= Do I still need to change php.ini or .htaccess? =

Only if you want to import through the Import page. Raise both `upload_max_filesize` and `post_max_size` above the size of your backup. Restoring from the Backups page doesn't need either change.

Add this to `.htaccess` (Apache) or `.user.ini` (Nginx / PHP-FPM), with sizes bigger than your backup. Some hosts ignore these files. If yours does, ask the host to raise the limits, or use the Backups page.

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

It hooks into the free version of All-in-One WP Migration with standard WordPress APIs (`upload_size_limit` filter, `wp_add_inline_script`). On the Import page, it lifts the JavaScript size check. On the Backups page, it adds the Upload backup button and defines the `Ai1wm.FreeExtensionRestore` class the free plugin looks for, so Restore runs the plugin's own import from the file on disk instead of showing the paid extension notice.

It doesn't change how the Import page sends the file. An upload there still has to fit under your PHP limits.

= Can I use a code snippet instead of installing this plugin? =

Yes. Add this to your theme's `functions.php` or use a code-snippets plugin like [SnipDrop](https://github.com/shameemreza/snipdrop):

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

= 1.1.0 =
* Added an Upload backup button on the Backups page. It uploads a `.wpress` file in pieces that fit under the server's PHP limits, so a large backup can be restored without SFTP.

= 1.0.1 =
* Updated the readme and code comments: the Import page sends the whole backup in one request, so the server's `post_max_size` and `upload_max_filesize` still limit it.
* Documented restoring large backups from the Backups page.
* Clarified that the memory raise runs on migration page loads, not during the import steps.
* Updated the plugin and readme links to shameemreza.com.
* Tested up to WordPress 7.1.

= 1.0.0 =
* Initial release.
* Removes the client-side upload file-size gate.
* Enables one-click backup restore on the Backups page.
* Raises WordPress upload limit to unlimited on migration pages.
* Boosts PHP memory_limit at runtime via wp_raise_memory_limit().
* Replaces the upsell notice with a clear limit confirmation.
