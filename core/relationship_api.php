<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Relationship API
 *
 * RELATIONSHIP DEFINITIONS
 * * Child/parent relationship:
 *    the child bug is generated by the parent bug or is directly linked with the parent with the following meaning
 *    the child bug has to be resolved before resolving the parent bug (the child bug "blocks" the parent bug)
 *    example: bug A is child bug of bug B. It means: A blocks B and B is blocked by A
 * * General relationship:
 *    two bugs related each other without any hierarchy dependence
 *    bugs A and B are related
 * * Duplicates:
 *    it's used to mark a bug as duplicate of an other bug already stored in the database
 *    bug A is marked as duplicate of B. It means: A duplicates B, B has duplicates
 *
 * Relations are always visible in the email body
 * --------------------------------------------------------------------
 * ADD NEW RELATIONSHIP
 * - Permission: user can update the source bug and at least view the destination bug
 * - Action recorded in the history of both the bugs
 * - Email notification sent to the users of both the bugs based based on the 'updated' bug notify type.
 * --------------------------------------------------------
 * DELETE RELATIONSHIP
 * - Permission: user can update the source bug and at least view the destination bug
 * - Action recorded in the history of both the bugs
 * - Email notification sent to the users of both the bugs based based on the 'updated' bug notify type.
 * --------------------------------------------------------
 * RESOLVE/CLOSE BUGS WITH BLOCKING CHILD BUGS STILL OPEN
 * Just a warning is print out on the form when a user attempts to resolve or close a bug with
 * related bugs in relation BUG_DEPENDANT still not resolved.
 * Anyway the user can force the resolving/closing action.
 * --------------------------------------------------------
 * EMAIL NOTIFICATION TO PARENT BUGS WHEN CHILDREN BUGS ARE RESOLVED/CLOSED
 * Every time a child bug is resolved or closed, an email notification is sent directly to all the handlers
 * of the parent bugs. The notification is sent to bugs not already marked as resolved or closed.
 * --------------------------------------------------------
 * ADD CHILD
 * This function gives the opportunity to generate a child bug. In details the function:
 * - create a new bug with the same basic information of the parent bug (plus the custom fields)
 * - copy all the attachment of the parent bug to the child
 * - not copy history, bugnotes, monitoring users
 * - set a relationship between parent and child
 *
 * @package CoreAPI
 * @subpackage RelationshipAPI
 * @author Marcello Scata' <marcelloscata at users.sourceforge.net> ITALY
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses access_api.php
 * @uses bug_api.php
 * @uses collapse_api.php
 * @uses config_api.php
 * @uses constant_api.php
 * @uses current_user_api.php
 * @uses database_api.php
 * @uses form_api.php
 * @uses helper_api.php
 * @uses lang_api.php
 * @uses prepare_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses string_api.php
 * @uses utility_api.php
 */

require_api( 'access_api.php' );
require_api( 'bug_api.php' );
require_api( 'collapse_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'database_api.php' );
require_api( 'form_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'prepare_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'utility_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * RelationshipData Structure Definition
 */
class BugRelationshipData {
	/**
	 * Relationship id
	 */
	public $id;

	/**
	 * Source Bug id
	 */
	public $src_bug_id;

	/**
	 * Source project id
	 */
	public $src_project_id;

	/**
	 * Destination Bug id
	 */
	public $dest_bug_id;

	/**
	 * Destination project id
	 */
	public $dest_project_id;

	/**
	 * Type
	 */
	public $type;
}

$g_relationships = array();
$g_relationships[BUG_DEPENDANT] = array(
	'#forward' => true,
	'#complementary' => BUG_BLOCKS,
	'#name' => 'parent-of',
	'#description' => 'dependant_on',
	'#notify_added' => 'email_notification_title_for_action_dependant_on_relationship_added',
	'#notify_deleted' => 'email_notification_title_for_action_dependant_on_relationship_deleted',
	'#edge_style' => array(
		'color' => '#C00000',
		'dir' => 'back',
	),
);
$g_relationships[BUG_BLOCKS] = array(
	'#forward' => false,
	'#complementary' => BUG_DEPENDANT,
	'#name' => 'child-of',
	'#description' => 'blocks',
	'#notify_added' => 'email_notification_title_for_action_blocks_relationship_added',
	'#notify_deleted' => 'email_notification_title_for_action_blocks_relationship_deleted',
	'#edge_style' => array(
		'color' => '#C00000',
		'dir' => 'forward',
	),
);
$g_relationships[BUG_DUPLICATE] = array(
	'#forward' => true,
	'#complementary' => BUG_HAS_DUPLICATE,
	'#name' => 'duplicate-of',
	'#description' => 'duplicate_of',
	'#notify_added' => 'email_notification_title_for_action_duplicate_of_relationship_added',
	'#notify_deleted' => 'email_notification_title_for_action_duplicate_of_relationship_deleted',
	'#edge_style' => array(
		'style' => 'dashed',
		'color' => '#808080',
	),
);
$g_relationships[BUG_HAS_DUPLICATE] = array(
	'#forward' => false,
	'#complementary' => BUG_DUPLICATE,
	'#name' => 'has-duplicate',
	'#description' => 'has_duplicate',
	'#notify_added' => 'email_notification_title_for_action_has_duplicate_relationship_added',
	'#notify_deleted' => 'email_notification_title_for_action_has_duplicate_relationship_deleted',
);
$g_relationships[BUG_RELATED] = array(
	'#forward' => true,
	'#name' => 'related-to',
	'#complementary' => BUG_RELATED,
	'#description' => 'related_to',
	'#notify_added' => 'email_notification_title_for_action_related_to_relationship_added',
	'#notify_deleted' => 'email_notification_title_for_action_related_to_relationship_deleted',
);

if( file_exists( config_get_global( 'config_path' ) . 'custom_relationships_inc.php' ) ) {
	include_once( config_get_global( 'config_path' ) . 'custom_relationships_inc.php' );
}

/**
 * Ensure that specified relationship type is valid.
 *
 * @param integer $p_relationship_type The relationship type id.
 * @return void
 */
function relationship_type_ensure_valid( $p_relationship_type ) {
	global $g_relationships;

	if( !is_numeric( $p_relationship_type ) || !isset( $g_relationships[$p_relationship_type] ) ) {
		throw new ClientException(
			sprintf( "Relation type '%s' not found.", $p_relationship_type ),
			ERROR_RELATIONSHIP_NOT_FOUND
		);
	}
}

/**
 * Return the complementary type of the provided relationship
 * @param integer $p_relationship_type A Relationship type.
 * @return integer Complementary type
 */
function relationship_get_complementary_type( $p_relationship_type ) {
	global $g_relationships;
	if( !isset( $g_relationships[$p_relationship_type] ) ) {
		trigger_error( ERROR_GENERIC, ERROR );
	}
	return $g_relationships[$p_relationship_type]['#complementary'];
}

/**
 * Add a new relationship
 * @param integer $p_src_bug_id        Source Bug Id.
 * @param integer $p_dest_bug_id       Destination Bug Id.
 * @param integer $p_relationship_type Relationship type.
 * @param bool $p_email_for_source     Should an email be triggered for source issue?
 * @return integer The new bug relationship id.
 */
function relationship_add( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source = true ) {
	global $g_relationships;
	if( $g_relationships[$p_relationship_type]['#forward'] === false ) {
		$c_src_bug_id = (int)$p_dest_bug_id;
		$c_dest_bug_id = (int)$p_src_bug_id;
		$c_relationship_type = (int)relationship_get_complementary_type( $p_relationship_type );
	} else {
		$c_src_bug_id = (int)$p_src_bug_id;
		$c_dest_bug_id = (int)$p_dest_bug_id;
		$c_relationship_type = (int)$p_relationship_type;
	}

	db_param_push();
	$t_query = 'INSERT INTO {bug_relationship}
				( source_bug_id, destination_bug_id, relationship_type )
				VALUES
				( ' . db_param() . ',' . db_param() . ',' . db_param() . ')';
	db_query( $t_query, array( $c_src_bug_id, $c_dest_bug_id, $c_relationship_type ) );

	$t_relationship_id = db_insert_id( db_get_table( 'bug_relationship' ) );

	history_log_event_special( $p_src_bug_id, BUG_ADD_RELATIONSHIP, $p_relationship_type, $p_dest_bug_id );
	history_log_event_special( $p_dest_bug_id, BUG_ADD_RELATIONSHIP, relationship_get_complementary_type( $p_relationship_type ), $p_src_bug_id );

	bug_update_date( $p_src_bug_id );
	bug_update_date( $p_dest_bug_id );

	email_relationship_added( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source );

	return $t_relationship_id;
}

/**
 * Update a relationship
 * @param integer $p_relationship_id   Relationship Id to update.
 * @param integer $p_src_bug_id        Source Bug Id.
 * @param integer $p_dest_bug_id       Destination Bug Id.
 * @param integer $p_relationship_type Relationship type.
 * @param bool $p_email_for_source     Should an email be triggered for source issue?
 * @return void
 */
function relationship_update( $p_relationship_id, $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source = true ) {
	global $g_relationships;
	if( $g_relationships[$p_relationship_type]['#forward'] === false ) {
		$c_src_bug_id = (int)$p_dest_bug_id;
		$c_dest_bug_id = (int)$p_src_bug_id;
		$c_relationship_type = (int)relationship_get_complementary_type( $p_relationship_type );
	} else {
		$c_src_bug_id = (int)$p_src_bug_id;
		$c_dest_bug_id = (int)$p_dest_bug_id;
		$c_relationship_type = (int)$p_relationship_type;
	}

	db_param_push();
	$t_query = 'UPDATE {bug_relationship}
				SET source_bug_id=' . db_param() . ',
					destination_bug_id=' . db_param() . ',
					relationship_type=' . db_param() . '
				WHERE id=' . db_param();
	db_query( $t_query, array( $c_src_bug_id, $c_dest_bug_id, $c_relationship_type, (int)$p_relationship_id ) );

	history_log_event_special( $p_src_bug_id, BUG_REPLACE_RELATIONSHIP, $p_relationship_type, $p_dest_bug_id );
	history_log_event_special( $p_dest_bug_id, BUG_REPLACE_RELATIONSHIP, relationship_get_complementary_type( $p_relationship_type ), $p_src_bug_id );

	bug_update_date( $p_src_bug_id );
	bug_update_date( $p_dest_bug_id );

	email_relationship_added( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source );
}

/**
 * Add/Update relationship based on whether the relationship already exists or not.
 *
 * @param integer $p_src_bug_id        Source Bug Id.
 * @param integer $p_dest_bug_id       Destination Bug Id.
 * @param integer $p_relationship_type Relationship type.
 * @param bool $p_email_for_source     Should an email be triggered for source issue?
 * @return integer The new bug relationship id.
 */
function relationship_upsert( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source = true ) {
	# Check if there is other relationship between the bugs.
	$t_id_relationship = relationship_same_type_exists( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type );

	if( $t_id_relationship > 0 ) {
		relationship_update( $t_id_relationship, $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source );
		$t_relationship_id = $t_id_relationship;
	} else if( $t_id_relationship != -1 ) {
		$t_relationship_id = relationship_add( $p_src_bug_id, $p_dest_bug_id, $p_relationship_type, $p_email_for_source );
	} else {
		# else relationship is -1 - same type exists
		$t_relationship_id = relationship_exists( $p_src_bug_id, $p_dest_bug_id );
	}

	return $t_relationship_id;
}

/**
 * Delete a relationship
 * @param integer $p_relationship_id Relationship Id to update.
 * @param bool $p_send_email Send email?
 * @return void
 */
function relationship_delete( $p_relationship_id, $p_send_email = true ) {
	$t_relationship = relationship_get( $p_relationship_id );

	db_param_push();
	$t_query = 'DELETE FROM {bug_relationship} WHERE id=' . db_param();
	db_query( $t_query, array( (int)$p_relationship_id ) );

	$t_src_bug_id = $t_relationship->src_bug_id;
	$t_dest_bug_id = $t_relationship->dest_bug_id;
	$t_rel_type = $t_relationship->type;

	bug_update_date( $t_src_bug_id );
	bug_update_date( $t_dest_bug_id );

	history_log_event_special( $t_src_bug_id, BUG_DEL_RELATIONSHIP, $t_rel_type, $t_dest_bug_id );

	if( bug_exists( $t_dest_bug_id ) ) {
		history_log_event_special(
			$t_dest_bug_id,
			BUG_DEL_RELATIONSHIP,
			relationship_get_complementary_type( $t_rel_type ),
			$t_src_bug_id );
	}

	if( $p_send_email ) {
		email_relationship_deleted( $t_src_bug_id, $t_dest_bug_id, $t_rel_type );
	}
}

/**
 * Deletes all the relationships related to a specific bug (both source and destination)
 * @param integer $p_bug_id A bug Identifier.
 * @return void
 */
function relationship_delete_all( $p_bug_id ) {
	$t_is_different_projects = false;
	$t_relationships = relationship_get_all( $p_bug_id, $t_is_different_projects );
	foreach( $t_relationships as $t_relationship ) {
		relationship_delete( $t_relationship->id, /* send_email */ false );
	}
}

/**
 * copy all the relationships related to a specific bug to a new bug
 * @param integer $p_bug_id     Source bug identifier.
 * @param integer $p_new_bug_id Destination bug identifier.
 * @return void
 */
function relationship_copy_all( $p_bug_id, $p_new_bug_id ) {
	$t_relationships = relationship_get_all_src( $p_bug_id );
	foreach( $t_relationships as $t_relationship ) {
		relationship_add(
			$p_new_bug_id,
			$t_relationship->dest_bug_id,
			$t_relationship->type,
			/* email_for_source */ false );
	}

	$t_relationships = relationship_get_all_dest( $p_bug_id );
	foreach( $t_relationships as $t_relationship ) {
		relationship_add(
			$p_new_bug_id,
			$t_relationship->src_bug_id,
			relationship_get_complementary_type( $t_relationship->type ),
			/* email_for_source */ false );
	}
}

/**
 * get a relationship from id
 * @param integer $p_relationship_id Relationship Identifier.
 * @return null|BugRelationshipData BugRelationshipData object
 */
function relationship_get( $p_relationship_id ) {
	db_param_push();
	$t_query = 'SELECT * FROM {bug_relationship} WHERE id=' . db_param();
	$t_result = db_query( $t_query, array( (int)$p_relationship_id ) );

	$t_relationship = db_fetch_array( $t_result );

	if( $t_relationship ) {
		$t_bug_relationship_data = new BugRelationshipData;
		$t_bug_relationship_data->id = $t_relationship['id'];
		$t_bug_relationship_data->src_bug_id = $t_relationship['source_bug_id'];
		$t_bug_relationship_data->dest_bug_id = $t_relationship['destination_bug_id'];
		$t_bug_relationship_data->type = $t_relationship['relationship_type'];
	} else {
		$t_bug_relationship_data = null;
	}

	return $t_bug_relationship_data;
}

/**
 * get all relationships with the given bug as source
 * @param integer $p_src_bug_id Source Bug identifier.
 * @return array Array of BugRelationshipData objects
 */
function relationship_get_all_src( $p_src_bug_id ) {
	db_param_push();
	$t_query = 'SELECT {bug_relationship}.id, {bug_relationship}.relationship_type,
				{bug_relationship}.source_bug_id, {bug_relationship}.destination_bug_id,
				{bug}.project_id
				FROM {bug_relationship}
				INNER JOIN {bug} ON {bug_relationship}.destination_bug_id = {bug}.id
				WHERE source_bug_id=' . db_param() . '
				ORDER BY relationship_type, {bug_relationship}.id';
	$t_result = db_query( $t_query, array( $p_src_bug_id ) );

	$t_src_project_id = bug_get_field( $p_src_bug_id, 'project_id' );

	$t_bug_relationship_data = array();
	$t_bug_array = array();
	$i = 0;

	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_bug_relationship_data[$i] = new BugRelationshipData;
		$t_bug_relationship_data[$i]->id = $t_row['id'];
		$t_bug_relationship_data[$i]->src_bug_id = $t_row['source_bug_id'];
		$t_bug_relationship_data[$i]->src_project_id = $t_src_project_id;
		$t_bug_relationship_data[$i]->dest_bug_id = $t_row['destination_bug_id'];
		$t_bug_relationship_data[$i]->dest_project_id = $t_row['project_id'];
		$t_bug_relationship_data[$i]->type = $t_row['relationship_type'];
		$t_bug_array[] = $t_row['destination_bug_id'];
		$i++;
	}

	if( !empty( $t_bug_array ) ) {
		bug_cache_array_rows( $t_bug_array );
	}

	return $t_bug_relationship_data;
}

/**
 * get all relationships with the given bug as destination
 * @param integer $p_dest_bug_id Destination bug identifier.
 * @return array Array of BugRelationshipData objects
 */
function relationship_get_all_dest( $p_dest_bug_id ) {
	db_param_push();
	$t_query = 'SELECT {bug_relationship}.id, {bug_relationship}.relationship_type,
				{bug_relationship}.source_bug_id, {bug_relationship}.destination_bug_id,
				{bug}.project_id
				FROM {bug_relationship}
				INNER JOIN {bug} ON {bug_relationship}.source_bug_id = {bug}.id
				WHERE destination_bug_id=' . db_param() . '
				ORDER BY relationship_type, {bug_relationship}.id';
	$t_result = db_query( $t_query, array( (int)$p_dest_bug_id ) );

	$t_dest_project_id = bug_get_field( $p_dest_bug_id, 'project_id' );

	$t_bug_relationship_data = array();
	$t_bug_array = array();
	$i = 0;

	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_bug_relationship_data[$i] = new BugRelationshipData;
		$t_bug_relationship_data[$i]->id = $t_row['id'];
		$t_bug_relationship_data[$i]->src_bug_id = $t_row['source_bug_id'];
		$t_bug_relationship_data[$i]->src_project_id = $t_row['project_id'];
		$t_bug_relationship_data[$i]->dest_bug_id = $t_row['destination_bug_id'];
		$t_bug_relationship_data[$i]->dest_project_id = $t_dest_project_id;
		$t_bug_relationship_data[$i]->type = $t_row['relationship_type'];
		$t_bug_array[] = $t_row['source_bug_id'];
		$i++;
	}

	if( !empty( $t_bug_array ) ) {
		bug_cache_array_rows( $t_bug_array );
	}
	return $t_bug_relationship_data;
}

/**
 * get all relationships associated with the given bug
 * @param integer $p_bug_id                 A bug identifier.
 * @param boolean &$p_is_different_projects Returned Boolean value indicating if some relationships cross project boundaries.
 * @return array Array of BugRelationshipData objects
 */
function relationship_get_all( $p_bug_id, &$p_is_different_projects ) {
	$t_src = relationship_get_all_src( $p_bug_id );
	$t_dest = relationship_get_all_dest( $p_bug_id );
	$t_all = array_merge( $t_src, $t_dest );

	$p_is_different_projects = false;
	$t_count = count( $t_all );
	for( $i = 0;$i < $t_count;$i++ ) {
		$p_is_different_projects |= ( $t_all[$i]->src_project_id != $t_all[$i]->dest_project_id );
	}
	return $t_all;
}

/**
 * check if there is a relationship between two bugs
 * return id if found 0 otherwise
 * @param integer $p_src_bug_id  Source bug identifier.
 * @param integer $p_dest_bug_id Destination bug identifier.
 * @return integer Relationship ID
 */
function relationship_exists( $p_src_bug_id, $p_dest_bug_id ) {
	$c_src_bug_id = (int)$p_src_bug_id;
	$c_dest_bug_id = (int)$p_dest_bug_id;

	db_param_push();
	$t_query = 'SELECT * FROM {bug_relationship}
				WHERE (source_bug_id=' . db_param() . ' AND destination_bug_id=' . db_param() . ')
				OR
				(source_bug_id=' . db_param() . '
				AND destination_bug_id=' . db_param() . ')';
	$t_result = db_query( $t_query, array( $c_src_bug_id, $c_dest_bug_id, $c_dest_bug_id, $c_src_bug_id ), 1 );

	if( $t_row = db_fetch_array( $t_result ) ) {
		# return the first id
		return $t_row['id'];
	} else {
		# no relationship found
		return 0;
	}
}

/**
 * check if there is a relationship between two bugs
 * return:
 *  0 if the relationship is not found
 *  -1 if the relationship is found and it's of the same type $p_rel_type
 *  id if the relationship is found and it's of a different time (this means it can be replaced with the new type $p_rel_type
 * @param integer $p_src_bug_id  Source Bug Id.
 * @param integer $p_dest_bug_id Destination Bug Id.
 * @param integer $p_rel_type    Relationship Type.
 * @return integer 0, -1 or id
 */
function relationship_same_type_exists( $p_src_bug_id, $p_dest_bug_id, $p_rel_type ) {
	# Check if there is already a relationship set between them
	$t_id_relationship = relationship_exists( $p_src_bug_id, $p_dest_bug_id );

	if( $t_id_relationship > 0 ) {
		# if there is...
		# get all the relationship info
		$t_relationship = relationship_get( $t_id_relationship );

		if( $t_relationship->src_bug_id == $p_src_bug_id && $t_relationship->dest_bug_id == $p_dest_bug_id ) {
			if( $t_relationship->type == $p_rel_type ) {
				$t_id_relationship = -1;
			}
		} else {
			if( $t_relationship->type == relationship_get_complementary_type( $p_rel_type ) ) {
				$t_id_relationship = -1;
			}
		}
	}
	return $t_id_relationship;
}

/**
 * retrieve the linked bug id of the relationship: provide source -> return destination; provide destination -> return source
 * @param integer $p_relationship_id Relationship id.
 * @param integer $p_bug_id          A bug identifier.
 * @return int Complementary bug id
 */
function relationship_get_linked_bug_id( $p_relationship_id, $p_bug_id ) {
	$t_bug_relationship_data = relationship_get( $p_relationship_id );

	if( $t_bug_relationship_data->src_bug_id == $p_bug_id ) {
		return $t_bug_relationship_data->dest_bug_id;
	}

	if( $t_bug_relationship_data->dest_bug_id == $p_bug_id ) {
		return $t_bug_relationship_data->src_bug_id;
	}

	trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
}

/**
 * get class description of a relationship (source side)
 * @param integer $p_relationship_type Relationship type.
 * @return string Relationship description
 */
function relationship_get_description_src_side( $p_relationship_type ) {
	global $g_relationships;
	relationship_type_ensure_valid( $p_relationship_type );
	return lang_get( $g_relationships[$p_relationship_type]['#description'] );
}

/**
 * get class description of a relationship (destination side)
 * @param integer $p_relationship_type Relationship type.
 * @return string Relationship description
 */
function relationship_get_description_dest_side( $p_relationship_type ) {
	global $g_relationships;

	relationship_type_ensure_valid( $p_relationship_type );
	relationship_type_ensure_valid( $g_relationships[$p_relationship_type]['#complementary'] );

	return lang_get( $g_relationships[$g_relationships[$p_relationship_type]['#complementary']]['#description'] );
}

/**
 * get class description of a relationship as it's stored in the history
 * @param integer $p_relationship_code Relationship Type.
 * @return string Relationship description
 */
function relationship_get_description_for_history( $p_relationship_code ) {
	return relationship_get_description_src_side( $p_relationship_code );
}

/**
 * Get class API name of a relationship as it's stored in the history.
 * @param integer $p_relationship_type Relationship Type.
 * @return string Relationship API name
 */
function relationship_get_name_for_api( $p_relationship_type ) {
	global $g_relationships;

	if( !isset( $g_relationships[$p_relationship_type] ) ) {
		switch( $p_relationship_type ) {
			case BUG_REL_NONE:
				return 'none';
			case BUG_REL_ANY:
				return 'any';
			default:
				# This will trigger the invalid relationship type exception.
				relationship_type_ensure_valid( $p_relationship_type );
		}
	}

	return $g_relationships[$p_relationship_type]['#name'];
}

/**
 * Get relationship type id given its API name.
 *
 * @param string $p_relationship_type_name relationship type name.
 * @return integer relationship type id
 * @throws ClientException unknown relationship type name.
 */
function relationship_get_id_from_api_name( $p_relationship_type_name ) {
	global $g_relationships;

	$t_relationship_type_name = strtolower( $p_relationship_type_name );
	foreach( $g_relationships as $t_id => $t_relationship ) {
		if( $t_relationship['#name'] == $t_relationship_type_name ) {
			return $t_id;
		}
	}

	throw new ClientException(
		sprintf( "Unknown relationship type '%s'", $p_relationship_type_name ),
		ERROR_INVALID_FIELD_VALUE,
		array( 'relationship_type' )
	);
}

/**
 * return false if there are child bugs not resolved/closed
 * N.B. we don't check if the parent bug is read-only. This is because the answer of this function is independent from
 * the state of the parent bug itself.
 * @param integer $p_bug_id A bug identifier.
 * @return boolean
 */
function relationship_can_resolve_bug( $p_bug_id ) {
	# retrieve all the relationships in which the bug is the source bug
	$t_relationships = relationship_get_all_src( $p_bug_id );

	foreach( $t_relationships as $t_relationship ) {
		# verify if each bug in relation BUG_DEPENDANT is already marked as resolved
		if( $t_relationship->type == BUG_DEPENDANT ) {
			$t_status = bug_get_field( $t_relationship->dest_bug_id, 'status' );

			if( $t_status < config_get( 'bug_resolved_status_threshold', null, null, $t_relationship->dest_project_id ) ) {
				# the bug is NOT marked as resolved/closed
				return false;
			}
		}
	}

	return true;
}
