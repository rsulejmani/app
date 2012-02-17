<?php
/**
 * @usage: sudo -u www-data SERVER_ID=177 php videoPostmigrate.php --conf /usr/wikia/conf/current/wiki.factory/LocalSettings.php --aconf /usr/wikia/conf/current/AdminSettings.php
 * @usage: sudo -u www-data SERVER_ID=177 php videoPostmigrate.php --conf /usr/wikia/conf-current/wiki.factory/LocalSettings.php --aconf /usr/wikia/conf-current/AdminSettings.php
 *  */
ini_set( 'display_errors', 'stdout' );
$options = array('help');
@require_once( '../../commandLine.inc' );
global $IP, $wgCityId, $wgExternalDatawareDB;
#$IP = '/home/pbablok/video/VideoRefactor/'; // HACK TO RUN ON SANDBOX
#echo( "$IP\n" );
echo( "Postmigration script running for $wgCityId\n" );

if( isset( $options['help'] ) && $options['help'] ) {
	echo( "Usage: php videoPostmigrate.php\n" );
	exit( 0 );
}

//include( "$IP/extensions/wikia/VideoHandlers/VideoHandlers.setup.php" );

$dbw = wfGetDB( DB_MASTER );
$dbw_dataware = wfGetDB( DB_MASTER, array(), $wgExternalDatawareDB );

$tables = array(
	'archive' => array('ns'=>'ar_namespace','id'=>'ar_page_id'),
	'cu_changes' => array('ns'=>'cuc_namespace','id'=>'cuc_id'),
	'hidden' => array('ns'=>'hidden_namespace','id'=>'hidden_page'),
	'logging' => array('ns'=>'log_namespace','id'=>'log_id'),
	'page' => array('ns'=>'page_namespace','id'=>'page_id'),
	'pagelinks' => array('ns'=>'pl_namespace','id'=>'pl_from'),
	'protected_titles' => array('ns'=>'pt_namespace','id'=>'pt_title'),
	'recentchanges' => array('ns'=>'rc_namespace','id'=>'rc_id'),
);



foreach( $tables as $tableName => $tableData ) {
	echo( "Processing $tableName\n" );
	
	if( !$dbw->tableExists($tableName) ) {
		echo "Table does not exist in this database\n";
		continue;
	}
	
	# get all affected rows (those that are in namespace 400)

	$rows = $dbw->select($tableName, 
			array( $tableData['id'] ),
			array( $tableData['ns'] => 400 ),
			__METHOD__
	);
	
	$rows_preloaded = array();
	while( $row = $dbw->fetchObject($rows) ) {
		$rows_preloaded[] = $row;
	}

	$dbw->freeResult($rows);
	
		
	foreach( $rows_preloaded as $row ) {
		$idField = $tableData['id'];
		$id = $row->$idField;
		echo "  row $idField => $id\n";
		
		try {
			# update namespace for specific row
		
			$dbw->update($tableName,
				array( $tableData['ns'] => 6 ),
				array( $tableData['id'] => $id ),
				__METHOD__
			);
			
			# log it so that undo operation is possible in the future
	
			$dbw_dataware->insert(
				'video_postmigrate_undo',
				array(
					'wiki_id'		=> $wgCityId,
					'entry_id'		=> $id,
					'entry_id_field'=> $tableData['id'],
					'entry_ns_field'=> $tableData['ns'],
					'entry_table'	=> $tableName,
				)
			);
				
		} catch(DBError $e) {
			# this is most likely due to duplication errors
			# as long as this happens in pagelinks it should be safe to remove them instead
			# TODO - remove pagelinks if they exist in NS_FILE namespace
		
			echo "  failed\n";
		}		
	}

}

echo "Done updating tables\n";
echo "Flipping the switch\n";

WikiFactory::setVarByName('wgVideoHandlersVideosMigrated', $wgCityId, true);

echo "Done\n";


?>
