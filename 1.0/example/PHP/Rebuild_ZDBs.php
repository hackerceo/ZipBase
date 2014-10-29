<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Example
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

	require_once('zipdb.php');

	function print_inst() {
		print "USAGE: Rebuild_ZDBs.bat <DIRECTORY> <CURRENT_COMPRESSION> [NEW_COMPRESSION]  \n";
		die();
	}

	// correct number of params
	if ($argc < 3) {
		print_r($argv);
		print_inst();
	}


	// deal with compression
	if (isset($argv[2])) {
		$compress_existing = $argv[2];
	} else {
		$compress_existing = "";
	}
	if (isset($argv[3])) {
		$compress_new = $argv[3];
	} else {
		$compress_new = FALSE;
	}
	
	
	// process param 1
	$target_dir = $argv[1];
	if (!is_dir($target_dir)) {
		print "INVALID DIRECTORY!\n";
		print_inst();
	}
	$target_dir = realpath($target_dir);

	// open the directory and find all zbd files
	$dirObj = dir($target_dir);
	while (false !== ($entry = $dirObj->read())) {
		if (strtoupper(substr($entry, -4)) == ".ZBD") {
			// found a data file
			$zdbd_filename = $target_dir.DIRECTORY_SEPARATOR.$entry;
			$zdbi_filename = $target_dir.DIRECTORY_SEPARATOR.str_replace(".zbd",".zbi",$entry);
			if (is_file($zdbi_filename)) {
				// found matching index file!
				echo "FOUND ".basename($zdbi_filename)." \t\t ";
				$zdb = new ZipDatabase($zdbd_filename, $zdbi_filename, "READWRITE ".$compress_existing);
				$stats = $zdb->AdminCompact($compress_new);
				echo $stats['delete_count']." DELETES [".$stats['delete_bytes']." bytes]\t".$stats['existing_count']." records\n";
				unset($zdb);
			} else {
				echo "ERROR: Could not find index file for $entry\n";
				$zdb = new ZipDatabase($zdbd_filename, $zdbi_filename, "READWRITE ".$compress_existing);
				print_r($zdb->AdminRebuildIndex());
			}
		}
	}
	$dirObj->close();

?>