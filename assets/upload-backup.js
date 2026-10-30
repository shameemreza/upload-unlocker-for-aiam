/**
 * Upload backup button on the All-in-One WP Migration Backups page.
 *
 * Sends a .wpress file in chunks small enough for the server's PHP
 * upload limits, then reloads the page so the backup shows in the list.
 * Config comes from the aiamUpload object printed before this script.
 */
( function () {
	'use strict';

	if ( typeof window.aiamUpload === 'undefined' || typeof window.fetch === 'undefined' ) {
		return;
	}

	var config  = window.aiamUpload;
	var button  = document.getElementById( 'aiam-upload-backup-button' );
	var input   = document.getElementById( 'aiam-upload-backup-file' );
	var status  = document.getElementById( 'aiam-upload-backup-status' );
	var retries = 2;
	var busy    = false;

	if ( ! button || ! input || ! status ) {
		return;
	}

	function format( text, values ) {
		return text.replace( /%(\d)\$s/g, function ( match, index ) {
			return values[ index - 1 ];
		} ).replace( '%%', '%' );
	}

	function setStatus( text ) {
		status.textContent = text;
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	// One request. Resolves with the parsed JSON, plus the HTTP status.
	function sendChunk( file, offset ) {
		var end  = Math.min( offset + config.chunkSize, file.size );
		var form = new FormData();

		form.append( 'action', 'aiam_upload_chunk' );
		form.append( '_ajax_nonce', config.nonce );
		form.append( 'name', file.name );
		form.append( 'offset', String( offset ) );
		form.append( 'total', String( file.size ) );
		form.append( 'is_last', end >= file.size ? '1' : '0' );
		form.append( 'chunk', file.slice( offset, end ), 'chunk' );

		return fetch( config.url, { method: 'POST', body: form, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: config.i18n.network } };
				} ).then( function ( json ) {
					json.status = response.status;
					return json;
				} );
			} );
	}

	// Sends one chunk with retries. Resolves with the server's new size.
	function sendWithRetry( file, offset, attempt ) {
		return sendChunk( file, offset ).then( function ( json ) {
			if ( json.success ) {
				return json.data.size;
			}

			// Out of step: the server says where to carry on from.
			if ( 409 === json.status && json.data && typeof json.data.size === 'number' && attempt < retries ) {
				return json.data.size;
			}

			var error = new Error( json.data && json.data.message ? json.data.message : config.i18n.failed );

			// 4xx answers are final: a retry would get the same answer.
			error.final = json.status >= 400 && json.status < 500;
			throw error;
		}, function () {
			throw new Error( config.i18n.network );
		} ).catch( function ( error ) {
			if ( attempt >= retries || error.final ) {
				error.final = true;
				throw error;
			}
			return wait( 1000 ).then( function () {
				return sendWithRetry( file, offset, attempt + 1 );
			} );
		} );
	}

	function upload( file ) {
		var offset = 0;

		function next() {
			setStatus( format( config.i18n.progress, [ file.name, ( offset / file.size * 100 ).toFixed( 0 ) ] ) );

			if ( offset >= file.size ) {
				return Promise.resolve();
			}

			return sendWithRetry( file, offset, 0 ).then( function ( size ) {
				offset = size;
				return next();
			} );
		}

		return next();
	}

	function onLeave( event ) {
		event.preventDefault();
		event.returnValue = config.i18n.leave;
		return config.i18n.leave;
	}

	button.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		if ( ! busy ) {
			input.click();
		}
	} );

	input.addEventListener( 'change', function () {
		var file = input.files && input.files[ 0 ];
		input.value = '';

		if ( ! file ) {
			return;
		}

		if ( ! /\.wpress$/i.test( file.name ) ) {
			setStatus( config.i18n.wrongExt );
			return;
		}

		busy = true;
		button.setAttribute( 'aria-disabled', 'true' );
		window.addEventListener( 'beforeunload', onLeave );

		upload( file ).then( function () {
			window.removeEventListener( 'beforeunload', onLeave );
			setStatus( config.i18n.done );
			window.location.reload();
		}, function ( error ) {
			window.removeEventListener( 'beforeunload', onLeave );
			busy = false;
			button.removeAttribute( 'aria-disabled' );
			setStatus( config.i18n.failed + ' ' + error.message );
		} );
	} );
}() );
