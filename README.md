# Upload Unlocker for All in All Migration

![Upload Unlocker: no import size check, Restore from Backups, no plugin files changed, free](.github/assets/banner.png)

Adds an Upload backup button and enables Restore on the [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/) Backups page, so you can restore a backup of any size without the paid extension or SFTP. It also removes the import size check on the Import page. No migration plugin files are modified.

[![Version 1.1.0](https://img.shields.io/badge/version-1.1.0-blue)](readme.txt)
[![Tested up to WordPress 7.1](https://img.shields.io/badge/WordPress-tested%207.1-blue)](https://wordpress.org/download/)
[![Requires PHP 7.4](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![License: GPLv2 or later](https://img.shields.io/badge/license-GPLv2%20or%20later-blue)](LICENSE)

![The Import page with Upload Unlocker active, showing Maximum upload file size: Unlimited](.github/preview.png)

## What It Does

| Feature | How |
|---|---|
| **No import size check** | Removes the JavaScript file-size gate on the Import page. Your server's PHP limits still apply (see below) |
| **Upload backup button** | Sends a `.wpress` file to the Backups page in pieces of up to 5 MB, each under your server's PHP limits |
| **Restore from Backups** | Restore on the Backups page runs for any `.wpress` file in `wp-content/ai1wm-backups`, with no upload |
| **Higher PHP memory** | Calls `wp_raise_memory_limit()` when a migration page loads |
| **Clean UI** | Replaces the upsell notice with a simple "Unlimited" confirmation |
| **Hides promo menus** | Removes the "Schedules" and "Reset Hub" upsell submenus |

## How It Works

On the Import page, the plugin lifts the JavaScript check that compares the file size against the server's reported limit. On the Backups page, it defines the `Ai1wm.FreeExtensionRestore` class the free plugin checks for, so Restore runs the plugin's own import from the file on disk instead of showing the paid extension notice. It uses standard WordPress hooks:

- `upload_size_limit` filter
- `wp_add_inline_script()`
- `wp_raise_memory_limit()`

No migration plugin files are touched. Everything is scoped to migration admin screens only.

### The Import page still has your server's limits

All-in-One WP Migration 7.113 sends the whole backup in one request from the Import page. This plugin can't change your server's `post_max_size` or `upload_max_filesize`, so a backup bigger than either one still fails on the Import page, even though it says Unlimited.

For a large backup, go to **All-in-One WP Migration > Backups**, click **Upload backup**, and choose the `.wpress` file. When the page reloads with the backup in the list, click **Restore**. The server needs to accept uploads of at least 1 MB. You can also copy the file into `wp-content/ai1wm-backups` with SFTP, or raise both PHP limits above the backup size so the Import page works.

Tested on October 30, 2026 with WordPress 7.1.4, PHP 8.3, All-in-One WP Migration 7.113, an 8 MB server limit, and a 36 MB backup. The Import page failed, and the Upload backup button plus Restore brought the whole site across. The full walkthrough is in [Increase the All-in-One WP Migration upload limit for free](https://shameemreza.com/increase-upload-limit-for-all-in-one-wp-migration-plugin/).

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/) (free version) installed and active

## Installation

### As a Plugin

1. Download the [latest release](https://github.com/shameemreza/upload-unlocker-for-aiam/releases).
2. Upload the `upload-unlocker-for-aiam` folder to `/wp-content/plugins/`.
3. Activate **Upload Unlocker for All in All Migration** from the Plugins screen.
4. For a large backup, go to **All-in-One WP Migration > Backups**, click **Upload backup**, then click **Restore** next to it. A backup under your server's limit imports from **All-in-One WP Migration > Import** as usual.

### As a Code Snippet

If you prefer not to install a plugin, add this to your theme's `functions.php` or use a code-snippets plugin like [SnipDrop](https://github.com/shameemreza/snipdrop):

<details>
<summary>Show snippet</summary>

```php
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
```

</details>

## FAQ

**Will this work if my hosting has a low `upload_max_filesize`?**

On the Backups page, yes. Upload backup sends the file in pieces that fit under your limits, and Restore reads it from disk. The server needs to accept uploads of at least 1 MB. On the Import page, no. All-in-One WP Migration 7.113 sends the whole file in one request, so a backup over `upload_max_filesize` or `post_max_size` fails there.

**Do I still need to change php.ini or .htaccess?**

Only to import through the Import page. Raise both `upload_max_filesize` and `post_max_size` above your backup size. See the [readme.txt](readme.txt) for the settings. Restoring from the Backups page needs neither.

## Support

- [Report an issue](https://github.com/shameemreza/upload-unlocker-for-aiam/issues)
- [Buy me a coffee](https://ko-fi.com/shameemreza)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
