<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
// 
//   This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
// 
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//
 
// Subtree ReStore Script
// file  bin/php/ezrestoresubtree.php
 
// This script restores all items under a given subtree node ID in the trash to their original location
// If original top node does not exist anymore (e.g., deleted as additional location), 
//   create a new node manually and provide the nodeID as replace-id
 
//  Code based on kernel/content/restore.php

// UPDATE 2013-03-08 by David Sayre
// using ezcConsoleInput()
// only restore children if top exists/restored
 
// script initializing
require 'autoload.php';

if ( file_exists( "config.php" ) )
{
    require "config.php";
}

$params = new ezcConsoleInput();

$node_option = new ezcConsoleOption( 'n', 'node-id', ezcConsoleInput::TYPE_STRING );
$node_option->mandatory = true;
$node_option->shorthelp = "Subtree node ID (required)";
$params->registerOption( $node_option );

$replace_option = new ezcConsoleOption( 'r', 'replace-id', ezcConsoleInput::TYPE_STRING );
$replace_option->mandatory = false;
$replace_option->shorthelp = "Replaced node ID, this existing node ID will be changed to node-id";
$params->registerOption( $replace_option );

$siteaccess_option = new ezcConsoleOption( 's', 'siteaccess', ezcConsoleInput::TYPE_STRING );
$siteaccess_option->mandatory = false;
$siteaccess_option->shorthelp = "The siteaccess name.";
$params->registerOption( $siteaccess_option );
// Process console parameters
try
{
    $params->process();
} catch ( ezcConsoleOptionException $e ) {
	echo $e->getMessage(). "\n";
	echo "\n";
	echo $params->getHelpText( 'How to run this script' ) . "\n";
    echo "\n";
    exit();
}

// Init an eZ Publish script - needed for some API function calls
// and a siteaccess switcher

$ezp_script_env = eZScript::instance( array( 'debug-message' => '',
                                              'use-session' => false,
                                              'use-modules' => true,
                                              'use-extensions' => true ) );

$ezp_script_env->startup();
							
if( $siteaccess_option->value )
{
	$ezp_script_env->setUseSiteAccess( $siteaccess_option->value );
}
$ezp_script_env->initialize();

####################
# Script process
####################

$cli = eZCLI::instance();
$cli->setUseStyles( true );


$source_handler_id  = $source_handler_option->value;
$srcNodeID = $node_option->value;
$replNodeID = $replace_option->value;
							
$db = eZDB::instance();
 
// Check if top node is in trash
$cli->output( 'Search for nodeID ' .$srcNodeID ); //debug
$query = 'SELECT path_string FROM ezcontentobject_trash WHERE node_id= "'.$srcNodeID.'"';
//debug $cli->output( $query );
$rows = $db->arrayQuery( $query );

$topExists = false;

if( count( $rows ) > 0 ) 
{
    $pathString = $rows[0]['path_string'];
    $cli->output( 'Restoring top node from trash' );
	$topExists = true;
}
else
{
    // Must be an existing node, or replace an existing node
    if ( $replNodeID )
    {
        changeNodeID( $replNodeID, $srcNodeID );
		$topExists = true;
    }
    $topNode = eZContentObjectTreeNode::fetch( $srcNodeID );
    if( is_object( $topNode ))
    {
        $pathString = $topNode->PathString;		
		$topExists = true;
    }
    else
    {
        $cli->error( 'Top node could not be found or restored' );
        //shutdown
		$topExists = false;
		
    }
}
 
if($topExists) {
	// Get items to restore
	// Ordered by depth, so parent nodes will be restored before their children
	$query = sprintf( 'SELECT * FROM ezcontentobject_trash WHERE path_string LIKE "%s%%" ORDER BY depth', $pathString );
	//debug $cli->output( $query );
	$trashList = $db->arrayQuery( $query );	
	$cli->output( sprintf( "Found %d nodes to restore", count( $trashList )));
	$restoreAttributes = array( 'is_hidden', 'is_invisible', 'priority', 'sort_field', 'sort_order' );
	$checkedParents = array();
	 
	foreach( $trashList as $trashItem ) 
	{
		$objectID     = $trashItem['contentobject_id'];
		$parentNodeID = $trashItem['parent_node_id'];
		$orgNodeID    = $trashItem['node_id'];
	 
		// Check if object exists
		$object = eZContentObject::fetch( $objectID );
		if ( !is_object( $object ) ) 
		{
			$cli->error( sprintf( 'Object %d does not exist', $objectID ));
			continue;
		}
		$cli->output( sprintf( 'Restoring object %d, "%s"', $objectID, $object->Name ));
	 
		// Check whether object is archived indeed
		if ( $object->attribute( 'status' ) != eZContentObject::STATUS_ARCHIVED )
		{
			$cli->error( sprintf( 'Object %d is not archived', $objectID ));
			continue;
		}
	 
		// Check if parent node exists
		if( !array_key_exists( $parentNodeID, $checkedParents ))
		{
			$parentNode = eZContentObjectTreeNode::fetch( $parentNodeID );
			$checkedParents[$parentNodeID] = is_object( $parentNode );
		}
		if( !$checkedParents[$parentNodeID] ) 
		{
			$cli->error( sprintf( 'Parent node for object %d does not exist', $objectID ));
			continue;
		}
		
		$version = $object->attribute( 'current' );
		$location = eZNodeAssignment::fetch( $object->ID, $version->Version, $parentNodeID );
		
		$opCode = $location->attribute( 'op_code' );
		$opCode &= ~1;
		// We only include assignments which create or nops.
		if ( !$opCode == eZNodeAssignment::OP_CODE_CREATE_NOP && !$opCode == eZNodeAssignment::OP_CODE_NOP ) {
			$cli->error( sprintf( 'Object %d can not be restored', $object->ID ));
			continue;
		}
	 
		$selectedNodeID = $location->attribute( 'parent_node' );
	 
		$db->begin();
	 
		// Remove all existing assignments, only our new ones should be present.
		foreach ( $version->attribute( 'node_assignments' ) as $assignment )
		{
			$assignment->purge();
		}
	 
		$version->assignToNode( $parentNodeID, true );
	 
		$object->setAttribute( 'status', eZContentObject::STATUS_DRAFT );
		$object->store();
		$version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
		$version->store();
	 
		$user = eZUser::fetch( $version->CreatorID );
		$operationResult = eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $objectID,
																					 'version' => $version->attribute( 'version' ) ) );
		$objectID = $object->attribute( 'id' );
		$object = eZContentObject::fetch( $objectID );
		$mainNodeID = $object->attribute( 'main_node_id' );
		
		// Restore original node number
		changeNodeID( $mainNodeID, $orgNodeID );
		
		// Restore other attributes
		$node = eZContentObjectTreeNode::fetch( $orgNodeID );
		foreach( $restoreAttributes as $attr )
		{
			$node->setAttribute( $attr, $trashItem[ $attr ] );
		}
		$node->store();
		
		eZContentObjectTrashNode::purgeForObject( $objectID  );
		
		if ( $object->attribute( 'contentclass_id' ) == $userClassID )
		{
			eZUser::purgeUserCacheByUserId( $object->attribute( 'id' ) );
		}
		eZContentObject::fixReverseRelations( $objectID, 'restore' );
		$db->commit();
	 
		$cli->output( sprintf( 'Restored at node %d', $orgNodeID ));
	}
}

$cli->output( "Done." );

// Avoid fatal error at the end
$ezp_script_env->shutdown();
 
function changeNodeID( $fromID, $toID )
{
    global $db;
    
    // Restore original node ID
    $query = sprintf( 'UPDATE `ezcontentobject_tree` 
                        SET `node_id`=%d, 
                            `path_string`= REPLACE( `path_string`, "/%d/", "/%d/" )
                        WHERE `node_id`=%d',
                    $toID, $fromID, $toID, $fromID );
    $db->query( $query );
    
    // Update main node IDs
    $query = sprintf( 'UPDATE `ezcontentobject_tree` SET `main_node_id`=%d WHERE `main_node_id`=%d',
                    $toID, $fromID );
    $db->query( $query );
}
 
?>