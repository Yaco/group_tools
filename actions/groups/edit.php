<?php
/**
 * Elgg groups plugin edit action.
 *
 * @package ElggGroups
 */

elgg_make_sticky_form('groups');

// Get group fields
$input = array();
foreach (elgg_get_config('group') as $shortname => $valuetype) {
	$value = get_input($shortname);

	if ($value === null) {
		// only submitted fields should be updated
		continue;
	}

	$input[$shortname] = $value;

	// @todo treat profile fields as unescaped: don't filter, encode on output
	if (is_array($input[$shortname])) {
		array_walk_recursive($input[$shortname], function (&$v) {
			$v = elgg_html_decode($v);
		});
	} else {
		$input[$shortname] = elgg_html_decode($input[$shortname]);
	}

	if ($valuetype == 'tags') {
		$input[$shortname] = string_to_tag_array($input[$shortname]);
	}
}

// only set if submitted
$name = get_input('name', null, false);
if ($name !== null) {
	$input['name'] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
}

$user = elgg_get_logged_in_user_entity();

$group_guid = (int)get_input('group_guid');
$is_new_group = $group_guid == 0;

if ($is_new_group
		&& (elgg_get_plugin_setting('limited_groups', 'groups') == 'yes')
		&& !$user->isAdmin()) {
	register_error(elgg_echo("groups:cantcreate"));
	forward(REFERER);
}

$group = $group_guid ? get_entity($group_guid) : new ElggGroup();
if (elgg_instanceof($group, "group") &&  !$group->canEdit()) {
	register_error(elgg_echo("groups:cantedit"));
	forward(REFERER);
}

// Assume we can edit or this is a new group
if (sizeof($input) > 0) {
	foreach ($input as $shortname => $value) {
		// update access collection name if group name changes
		if (!$is_new_group && $shortname == 'name' && $value != $group->name) {
			$group_name = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
			$ac_name = sanitize_string(elgg_echo('groups:group') . ": " . $group_name);
			$acl = get_access_collection($group->group_acl);
			if ($acl) {
				// @todo Elgg api does not support updating access collection name
				$db_prefix = elgg_get_config('dbprefix');
				$query = "UPDATE {$db_prefix}access_collections SET name = '$ac_name'
					WHERE id = $group->group_acl";
				update_data($query);
			}
		}

		$group->$shortname = $value;
	}
}

// Validate create
if (!$group->name) {
	register_error(elgg_echo("groups:notitle"));
	forward(REFERER);
}


// Set group tool options
$tool_options = elgg_get_config('group_tool_options');
if ($tool_options) {
	foreach ($tool_options as $group_option) {
		$option_toggle_name = $group_option->name . "_enable";
		$option_default = $group_option->default_on ? 'yes' : 'no';
		$group->$option_toggle_name = get_input($option_toggle_name, $option_default);
	}
}

// Group membership - should these be treated with same constants as access permissions?
$is_public_membership = (get_input('membership') == ACCESS_PUBLIC);
$group->membership = $is_public_membership ? ACCESS_PUBLIC : ACCESS_PRIVATE;

$content_access_mode = get_input('content_access_mode');
$group->setContentAccessMode($content_access_mode);

if ($is_new_group) {
	$group->access_id = ACCESS_PUBLIC;

	// if new group, we need to save so group acl gets set in event handler
	$group->save();
}

// Invisible group support
// @todo this requires save to be called to create the acl for the group. This
// is an odd requirement and should be removed. Either the acl creation happens
// in the action or the visibility moves to a plugin hook
if (elgg_get_plugin_setting('hidden_groups', 'groups') == 'yes') {
	$visibility = (int)get_input('vis');

	if ($visibility == ACCESS_PRIVATE) {
		// Make this group visible only to group members. We need to use
		// ACCESS_PRIVATE on the form and convert it to group_acl here
		// because new groups do not have acl until they have been saved once.
		$visibility = $group->group_acl;

		// Force all new group content to be available only to members
		$group->setContentAccessMode(ElggGroup::CONTENT_ACCESS_MODE_MEMBERS_ONLY);
	}

	$group->access_id = $visibility;
}

$group->save();

// default access
$default_access = (int) get_input('group_default_access');
if (($group->getContentAccessMode() === ElggGroup::CONTENT_ACCESS_MODE_MEMBERS_ONLY) && (($default_access === ACCESS_PUBLIC) || ($default_access === ACCESS_LOGGED_IN))) {
	system_message(elgg_echo('group_tools:action:group:edit:error:default_access'));
	$default_access = (int) $group->group_acl;
}
$group->setPrivateSetting("elgg_default_access", $default_access);


// group saved so clear sticky form
elgg_clear_sticky_form('groups');

// group creator needs to be member of new group and river entry created
if ($is_new_group) {

	// @todo this should not be necessary...
	elgg_set_page_owner_guid($group->guid);

	$group->join($user);
	elgg_create_river_item(array(
		'view' => 'river/group/create',
		'action_type' => 'create',
		'subject_guid' => $user->guid,
		'object_guid' => $group->guid,
	));
}

$has_uploaded_icon = get_resized_image_from_uploaded_file("icon", 100, 100);

if ($has_uploaded_icon) {

	$icon_sizes = elgg_get_config('icon_sizes');

	$prefix = "groups/" . $group->guid;

	$filehandler = new ElggFile();
	$filehandler->owner_guid = $group->owner_guid;
	$filehandler->setFilename($prefix . ".jpg");
	$filehandler->open("write");
	$filehandler->write(get_uploaded_file('icon'));
	$filehandler->close();
	$filename = $filehandler->getFilenameOnFilestore();

	$sizes = array('tiny', 'small', 'medium', 'large');

	$thumbs = array();
	foreach ($sizes as $size) {
		$thumbs[$size] = get_resized_image_from_existing_file(
			$filename,
			$icon_sizes[$size]['w'],
			$icon_sizes[$size]['h'],
			$icon_sizes[$size]['square']
		);
	}

	if ($thumbs['tiny']) { // just checking if resize successful
		$thumb = new ElggFile();
		$thumb->owner_guid = $group->owner_guid;
		$thumb->setMimeType('image/jpeg');

		foreach ($sizes as $size) {
			$thumb->setFilename("{$prefix}{$size}.jpg");
			$thumb->open("write");
			$thumb->write($thumbs[$size]);
			$thumb->close();
		}

		$group->icontime = time();
	}
}

// owner transfer
$old_owner_guid = $is_new_group ? 0 : $group->owner_guid;
$new_owner_guid = (int) get_input('owner_guid');

if (!$is_new_group && $new_owner_guid && ($new_owner_guid != $old_owner_guid)) {
	// who can transfer
	$admin_transfer = elgg_get_plugin_setting("admin_transfer", "group_tools");
	
	$transfer_allowed = false;
	if (($admin_transfer == "admin") && elgg_is_admin_logged_in()) {
		$transfer_allowed = true;
	} elseif (($admin_transfer == "owner") && (($group->getOwnerGUID() == $user->getGUID()) || elgg_is_admin_logged_in())) {
		$transfer_allowed = true;
	}
	
	if ($transfer_allowed) {
		// get the new owner
		$new_owner = get_user($new_owner_guid);
		
		// transfer the group to the new owner
		group_tools_transfer_group_ownership($group, $new_owner);
	}
}

system_message(elgg_echo("groups:saved"));

forward($group->getUrl());
