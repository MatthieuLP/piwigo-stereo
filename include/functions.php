<?php

/* 
 * Copyright (C) 2016 elliott
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

function Stereo_render_element_content($content, $picture)
{
	global $page, $prefixeTable, $template;

	if ( isset($page['slideshow']) and $page['slideshow'] ) {
		return $content;
	}
	if ( !preg_match ( '/.*mpo$/i', $picture['file'] ) ) {
		return $content;
	}
	$gif_relative = preg_replace( '/jpg$/i', 'gif', $picture['path'] );
	$gif_url = PWG_DERIVATIVE_DIR . preg_replace( '/^\.\//', '', $gif_relative );
	$absolute_path = realpath( PWG_DERIVATIVE_DIR ) . '/' . $gif_relative;
	if ( !file_exists( $absolute_path ) ) {
		Stereo_generate_gif( $picture, $absolute_path );
	}
	$rel_dir = 'plugins/' . basename( realpath( __DIR__ . '/..' ) );
	$query = '
		SELECT *
		FROM '.$prefixeTable.'stereo
		WHERE media_id = ' . $picture['id'];
	$offset = pwg_db_fetch_assoc(pwg_query($query));
	$jsOffset = '';
	if ( $offset ) {
		$jsOffset = ", { x: {$offset['x']}, y: {$offset['y']} }";
	}
	$template->set_filename( 'Stereo_picture', STEREO_PATH . '/picture.tpl' );
	$template->assign( array(
		'GIF_URL' => $gif_url,
		'REL_DIR' => $rel_dir,
		'WIGGLE_PARAMS' => $picture['id'] . $jsOffset,
	) );
	return $content . $template->parse( 'Stereo_picture', true );
}

function Stereo_generate_gif( $picture, $gif_path ) {
	$orig_path = realpath( $picture['path'] );

	$rjpg = tempnam( '/tmp', 'piwigo_Stereo_' ) . '.jpg';
	$ljpg = tempnam( '/tmp', 'piwigo_Stereo_' ) . '.jpg';

	// First split the MPO file into 2 JPEGs
	$marker = hex2bin( 'ffd8ffe1' ); // EXIF start-of-image + app1 header
	$in = fopen( $orig_path, 'rb' );
	$out = fopen( $rjpg, 'wb' ); // MPO stores the right image first
	$chunk_size = 1024 * 100; // Read 100k at a time
	$first = true; // Are we still reading / writing the first picture?
	$last_chunk = ''; // Save in case the marker crosses a chunk boundary
	do {
		$chunk = fread( $in, $chunk_size );
		if ( $first ) {
			$search_space = $last_chunk . $chunk;
			// Start searching 32 bytes in to skip the first marker
			$pos = strpos( $search_space, $marker, 32 );
			if ( $pos === false ) {
				fwrite( $out, $chunk );
				// Save the last 64 bytes of the chunk
				$last_chunk = substr( $chunk, -64 );
			} else {
				// Found the marker!
				// Correct position for the last chunk
				$pos = $pos - strlen( $last_chunk );
				// Write the final bit of the first JPEG
				fwrite( $out, $chunk, $pos );
				fclose( $out );
				// Now open the second file and write the rest of the chunk
				$out = fopen( $ljpg, 'wb' );
				fwrite( $out, substr( $chunk, $pos ) );
				$first = false;
			}
		} else {
			fwrite( $out, $chunk );
		}
	} while ( !feof( $in ) );
	fclose( $in );
	fclose( $out );

	// Then combine the two into a single gif
	// TODO: get rid of exec, though php-gd doesn't support animation
	// TODO: multiple sizes?
	exec( "convert -loop 0 -delay 0 $ljpg -delay 0 $rjpg -resize 1024x $gif_path" );

	// And delete the temp files
	unlink( $rjpg );
	unlink( $ljpg );
}

function Stereo_tabsheet( $tabs, $context ) {
	global $prefixeTable;
	if ( $context != 'photo' ) {
		return $tabs;
	}
	load_language('plugin.lang', STEREO_PATH);
	check_input_parameter('image_id', $_GET, false, PATTERN_ID);
	$id = $_GET['image_id'];
	$query = '
		SELECT file from '.$prefixeTable.'images
		WHERE id = ' . $id;
	$result = pwg_db_fetch_assoc(pwg_query($query));
	if ( $result && preg_match ( '/.*mpo$/i', $result['file'] ) ) {
		$tabs['stereo'] = array(
			'caption' => l10n('STEREO_ADJUSTMENT'),
			'url' => Stereo_get_admin_url( $id )
		);
	}
	return $tabs;
}

function Stereo_get_admin_url( $id ) {
	$plug_dir = basename( realpath( __DIR__ . '/..' ) );
	return get_root_url() . 'admin.php?page=plugin&amp;section=' .
		$plug_dir . '/admin.php&amp;image_id=' . $id;
}

function Stereo_loc_end_element_set_global() {
	global $template;

	load_language( 'plugin.lang', STEREO_PATH );

	$template->set_filename( 'Stereo_batch_global', STEREO_PATH . '/batch_global.tpl' );
	$template->append( 'element_set_global_plugins_actions',
		array(
			'ID' => 'stereo',
			'NAME'=>l10n('STEREO_ADJUSTMENT'),
			'CONTENT' => $template->parse( 'Stereo_batch_global', true )
		)
	);
}

function Stereo_element_set_global_action( $action, $collection ) {
	if ( $action !== 'stereo' ) {
		return;
	}

	global $page, $prefixeTable;
	load_language( 'plugin.lang', STEREO_PATH );

	$x = trim( $_POST['offsetX'] );
	$y = trim( $_POST['offsetY'] );

	$set = array();
	if ( $x !== '' && is_numeric( $x ) ) {
		$set[] = "x = $x";
	}
	if ( $y !== '' && is_numeric( $y ) ) {
		$set[] = "y = $y";
	}

	if ( empty( $set ) ) {
		$page['errors'][] = l10n( 'STEREO_BATCH_NO_INPUT' );
	} else {
		$update_query = 'UPDATE ' . $prefixeTable . 'stereo SET ' .
			implode( ',', $set ) .
			' WHERE media_id IN (' . implode( ',', $collection ) . ')';
		pwg_query($update_query);
		$page['infos'][] = l10n( 'STEREO_EDIT_SUCCESS' );
	}
}


function Stereo_get_batch_manager_prefilters( $prefilters ) {
	load_language( 'plugin.lang', STEREO_PATH );

	$prefilters[] = array(
		'ID' => 'stereo0',
		'NAME' => l10n( '3D_FILTER' )
	);
	return $prefilters;
}

function Stereo_perform_batch_manager_prefilters( $filter_sets, $prefilter ) {
	if ( $prefilter === 'stereo0' ) {
		$query = "SELECT id FROM " . IMAGES_TABLE .
			" WHERE UPPER( RIGHT( file, 3 ) ) = 'MPO'";
		$filter_sets[] = query2array( $query, null, 'id' );
	}

	return $filter_sets;
}
