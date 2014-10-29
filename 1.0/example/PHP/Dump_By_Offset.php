<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Example
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

	require_once('zipdb.php');
    
	function print_inst() {
		print "USAGE: Dump_By_Offset ZDB_DATA_PATHFILENAME Offset_in_bytes [compress options]\n";
		die();
	}

	// correct number of params
	if ($argc < 3) {
		print_r($argv);
		print_inst();
	}
    
	// process param 1
	$target_file = $argv[1];
	if (!is_file($target_file)) {
		print "INVALID ZDB FILE!\n";
		print_inst();
	}
	

	// open the ZDB File and extract a single entry 
	// THIS USES DIRECT ACCESS TO ZDB's INTERNAL CLASSES!
	// --------------------------------------------------------------------------------
	$ZDB_datamgr = new ZipDBDataMgr($target_file, "READONLY");
	$record = $ZDB_datamgr->ReadData(intval($argv[2]), TRUE);	
	if (isset($argv[3])) {
		$compressor = new CompressionRouter($argv[3]);
		print $compressor->Decompress($record['data'])."\n";
	} else {
		print $record['data']."\n";
	}
	
	unset($record['data']);
	print "\n";
	print_r($record);

?>