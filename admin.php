<?php
include_once( __DIR__ . '/include/functions.php' );

/* 
 * Copyright (C) 2015 Elliott Eggleston <ejegg@ejegg.com>
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

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');
// Check access and exit when user status is not ok
check_status(ACCESS_ADMINISTRATOR);
// FIXME: Duplicated boilerplate - could be avoided with a hook in the else
// clause at the bottom of admin/photo.php letting you set the right include file
if (!isset($_GET['image_id']) or !isset($_GET['section']))
{
	die('Invalid data!');
}
global $template, $page, $prefixeTable;

check_input_parameter('image_id', $_GET, false, PATTERN_ID);
$id = $_GET['image_id'];

$query = '
		SELECT *
		FROM '.$prefixeTable.'images i
		LEFT JOIN '.$prefixeTable.'stereo s
		ON i.id = s.media_id
		WHERE i.id = ' . $id;
$picture = pwg_db_fetch_assoc(pwg_query($query));

if (isset($_POST['submit']))
{
	check_pwg_token();

	$offsetX = trim($_POST['offsetX']);
	$offsetY = trim($_POST['offsetY']);
	if (
		strlen($offsetX) === 0 ||
		strlen($offsetY) === 0 ||
		!is_numeric($offsetX) ||
		!is_numeric($offsetY)
	) {
		$page['errors'][] = 'Invalid offset value';
	}

	if (count($page['errors']) === 0 ) {
		$stereoTable = $prefixeTable.'stereo';
		if ( isset($picture['x']) ) {
			$query =
				"UPDATE $stereoTable
				SET x=$offsetX, y=$offsetY
				WHERE media_id = $id;";
		} else {
			$picture['x'] = $offsetX;
			$picture['y'] = $offsetY;
			$query =
				"INSERT INTO $stereoTable (media_id, x, y)
				VALUES ($id, $offsetX, $offsetY)";
		}
		pwg_query($query);
		array_push( $page['infos'], 'Frame offset was updated');
	}
}

// needed for the photo tabsheet
$admin_photo_base_url = get_root_url().'admin.php?page=photo-'.$id;
$self_url = get_root_url().'admin.php?page=plugin&amp;section=piwigo-stereo/admin.php&amp;image_id='.$id;

include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');
$tabsheet = new tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select('stereo');
$tabsheet->assign();

$template->assign(array(
	'PWG_TOKEN' => get_pwg_token(),
	'F_ACTION'  => $self_url,
	'TITLE'     => render_element_name($picture),
	'PICTURE'   => render_Stereo_element_content('', $picture),
	'OFFSET_X'  => empty( $picture['x'] ) ? 0 : $picture['x'],
	'OFFSET_Y'  => empty( $picture['y'] ) ? 0 : $picture['y'],
));

$template->set_filename('plugin_admin_content', STEREO_PATH . 'admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
