<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Example
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//


	define('REPORT_INTERVAL', 1000);

	require_once('zipdb.php');

	// =================================================================
	interface zipbaseIProcessor {
		public function processXML($xml_string, $key);
		public function SetZDB($refZDB);
		public function getStatistics();
		public function clearStatistics();
	}
	// =================================================================
	function print_inst() {
		print "USAGE: Process_All_ZDB directory ZDB_OPTIONS processor_class.php [PROCESSOR_OPTIONS]\n";
		die();
	}
	// =================================================================



	// correct number of params
	if ($argc < 4) {
		print_r($argv);
		print_inst();
	}

	// process param 1 [directory to process]
	$target_dir = $argv[1];
		if (!is_dir($target_dir)) {
		print "INVALID DIRECTORY!\n";
		print_inst();
	}

	// process param 2
	$zdb_options = $argv[2];

	// process param 3 [processor's path+filename]
	$processor_class_file = $argv[3];
		if (!file_exists($processor_class_file)) {
		print "INVALID PROCESSOR CLASS FILE!\n";
		print_inst();
	}
	require_once($processor_class_file);
	$class_name = pathinfo($processor_class_file, PATHINFO_FILENAME);
	// make sure the processor class exists
	if(!class_exists($class_name)) {
		print "PROCESSOR CLASS FILE DOES NOT CONTAIN A CLASS NAMED: $class_name\n";
		print_inst();
	}

	// process param 4 [PROCESSOR OPTIONS]
	$extract_record = TRUE;
	$post_compaction = FALSE;
	if (isset($argv[4])) {
		$processor_options = $argv[4];
		if (strpos(strtoupper($processor_options), "ONLY_KEYS") !== FALSE) {
			print "PROCESSOR OPTION: ONLY_KEYS will be extracted and passed to the processor!\n";
			$extract_record = FALSE;
		}
		if (strpos(strtoupper($processor_options), "COMPACT") !== FALSE) {
			print "PROCESSOR OPTION: COMPACT - the ZDB files will be compacted after processing!\n";
			$post_compaction = TRUE;
		}
	} else {
		$processor_options = FALSE;
	}

	// set proper time zone
	date_default_timezone_set('America/New_York');

	// instanciate the processor class
	$processor = new $class_name($db, $processor_options);

	// open the directory and find all zbd files
	$dirObj = dir($target_dir);
	while (false !== ($entry = $dirObj->read())) {
		if (strtoupper(substr($entry, -4)) == ".ZBD") {
			// found a data file
			$zdbd_filename = $target_dir.DIRECTORY_SEPARATOR.$entry;
			$zdbi_filename = $target_dir.DIRECTORY_SEPARATOR.str_replace(".zbd",".zbi",$entry);
			if (is_file($zdbi_filename)) {
				// found matching index file!
				echo "FOUND: \t $zdbd_filename \t\t $zdbi_filename \n";
				$zdb = new ZipDatabase($zdbd_filename, $zdbi_filename, $zdb_options);
				$processor->SetZDB($zdb);
		
				// GET ALL KEYS AND PROCESS THEM 
				$keys = $zdb->ListKeys();
				$records = 0;
				$start_time = microtime(true);
				$xml = FALSE;
				while(count($keys)>0) {
					$key = array_pop($keys);
					if ($extract_record) { $xml = $zdb->RecordRead($key); }
					$processor->processXML($xml, $key);
					$records++;
					if (($records % REPORT_INTERVAL) == 0) {
						$end_time = microtime(true);
						print "$records processed / ".count($keys)." remaining \t avg. ".($records / ($end_time - $start_time))." records/sec \n";
					}
				}
				print "\n =============== $records processed in ".($end_time - $start_time)." seconds =============== \n";
				if ($post_compaction) $zdb->AdminCompact();
				unset($zdb);
			} else {
				echo "ERROR: Could not find index file for $entry\n";
			}
		}
	}
	$dirObj->close();

?>