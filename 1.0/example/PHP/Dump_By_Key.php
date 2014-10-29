<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Example
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

	require_once('zipdb.php');

	function print_inst() {
		print "USAGE: Dump_By_Key.bat <FULLPATH_FILENAME.ZBI> <KEY>|\"DUMP_ALL_KEYS\"|\"DUMP_INDEX:#\" [COMPRESSION]\n";
		die();
	}

	// correct number of params
	if ($argc < 3) {
		print_r($argv);
		print_inst();
	}

	// process file
	$target_file = $argv[1];

	// process key
	$key = $argv[2];
	
	// process compression
	$compression = "";
	if (isset($argv[3])) 	$compression = $argv[3];
	

	// open the proper ZIPDB
	$temp = explode(".", $target_file);
	$datafile = $temp[0].".zbd";
	$indexfile = $temp[0].".zbi";
	$temp = array();
	if (!is_file($datafile)) 	$temp[] = "\t".$datafile;
//	if (!is_file($indexfile)) 	$temp[] = "\t".$indexfile;  // ==> now automatically rebuilds missing index files
	if (count($temp) > 0) {
		print "FILE(S) NOT FOUND!\n".implode("\n",$temp);
		die("\n");
	}

	$zdb = new ZipDatabase($datafile, $indexfile, "READWRITE ".$compression);

	if (strtoupper($key) == "DUMP_ALL_KEYS") {
		print implode("\n",$zdb->ListKeys());
		die("\n");
	} elseif (substr(strtoupper($key),0,11)=='DUMP_INDEX:') {
		$list = $zdb->ListKeys();
		try {
			$index = intval(substr(strtoupper($key),11));
			$key = $list[$index];
			print "\n\nKEY #$index is \"$key\"\n"; 
		} catch (Exception $e) {
			die("ERROR FINDING INDEX #: ".substr(strtoupper($key),11)."\n");
		}
	}
	
	// extract the selected record and print it
	$returnData = $zdb->RecordRead($key);
	
	print $returnData."\n\n\n";
	
?>