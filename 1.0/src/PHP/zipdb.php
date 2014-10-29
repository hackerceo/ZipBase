<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Engine
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//


/*
    USAGE:
            $ZDB = new ZipDatabase($datafile, $indexfile, "READWRITE|READONLY [COMPRESS:scheme] [INDEX:REBUILD]");
            $ZDB->RecordSaveNew($key, $data [, $mode]);
            $ZDB->RecordSave($key, $data [, $mode]);
            $ZDB->RecordRead($key);
            $ZDB->RecordDelete($key);
            $ZDB->AdminCompact();
            $ZDB->AdminRebuildIndex($indexfile="");
            $ZDB->ListKeys();
            $ZDB->MergeDB($datafile);
*/


	// DEFINE CONSTANTS
	define("ZDB_INDEX_DATAOFFSET",  0);
	define("ZDB_INDEX_INDEXOFFSET", 1);

	require_once('zipdb-compression.php');
	require_once('zipdb-indexmgr.php');
	require_once('zipdb-datamgr.php');


// ======================================================================================
	class ZipDatabase {
		private $indexMgr;
		private $dataMgr;
		private $compressor;
		//--------------------------------------------------------------------------------------------------------
		function __construct($datafile, $indexfile, $mode="W") {
			$mode = strtoupper($mode);
			$this->indexMgr = new ZipDBIndexMgr($indexfile, $mode);
			$this->dataMgr = new ZipDBDataMgr($datafile, $mode);
			$this->compressor = new CompressionRouter($mode);
			// see if compression options were set
			if ($this->compressor->getCompressionType()===FALSE) unset($this->compressor);
			// see if the index file requires rebuilding
			if ($this->indexMgr->requiresRebuild()) $this->AdminRebuildIndex();
		}
		//--------------------------------------------------------------------------------------------------------
		function __destruct() {
			unset($this->indexMgr);
			unset($this->dataMgr);
			unset($this->compressor);
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordSaveNew($key, $data, $mode="D") {
			// see if the record is already present
			$lookup = $this->indexMgr->RecordGet($key);
			if ($lookup!==FALSE) return FALSE;
			// record does not exist, save it
			return $this->RecordSave($key, $data, $mode);
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordSave($key, $data, $mode="D") {
			// delete if it already exists
			if ($this->indexMgr->RecordGet($key)!==FALSE) $this->RecordDelete($key);

			// pack the data
			if ($this->compressor) {
				$packedData = $this->compressor->Compress($data);
			} else {
				$packedData = $data;
			}
			// save to the data file
			$info = $this->dataMgr->AppendData($key,$packedData);
			// add to index file
			return $this->indexMgr->RecordNew($key,$info['dataOffset']);
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordRead($key) {
			// find it in the memory index
			$recloc = $this->indexMgr->RecordGet($key);
			$recdata = $this->dataMgr->ReadData($recloc[ZDB_INDEX_DATAOFFSET]);
			// bad or deleted data?
			if (!$recdata || ($key != $recdata['key'])) return FALSE;
			// uncompress data if needed
			if (isset($this->compressor)) {
				return $this->compressor->Decompress($recdata['data']);
			} else {
				return $recdata['data'];
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function ListKeys() { return $this->indexMgr->ReadAllKeys(); }
		//--------------------------------------------------------------------------------------------------------
		public function RecordExists($key) {
			if ($this->indexMgr->RecordGet($key)===FALSE) {
				return FALSE;
			} else {
				return TRUE;
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordDelete($key) {
			// find the offset via index
			$recloc = $this->indexMgr->RecordGet($key);
			if ($recloc!==FALSE) {
				$offset = $recloc[ZDB_INDEX_DATAOFFSET];
				// delete from index
				$this->indexMgr->RecordErase($key);
				// delete from datafile
				$this->dataMgr->DeleteData($key,$offset);
				return TRUE;
			} else {
				return FALSE;
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function AdminCompact($new_compression=FALSE) {
			$deletes_cleaned = 0;
			$deletes_size = 0;
			$existing_count = 0;
			$offset = 0;
			// see if we are cross-compressing the DB
			$cross_compress = FALSE;
			if (isset($this->compressor) && $new_compression!==FALSE) {
				$cross_compress = new CompressionRouter($new_compression); 
				if (!$cross_compress) die("Invalid compression specified for new format!");
			}
			$new_index_info = array();
			// open a second DBZ datafile
			$DBZd_file_old = $this->dataMgr->getFilename();
			$DBZd_file = $DBZd_file_old."-TEMP";
			// delete the old temp file if it already exists
			if (file_exists($DBZd_file)) { unlink($DBZd_file); }
			$rebuildZDBd = new ZipDBDataMgr($DBZd_file, "READWRITE");
				
			// extract an index of all the records in the file keeping only the last wrote record for each key
			$offset = 0;
			$record = $this->dataMgr->ReadData(0, TRUE);
			while ($record!==FALSE) {
				unset($record['data']);
				if ($record['deleted']==0 && !isset($new_index_info[$record['key']])) {
					// save to the non-duplicated record data to memory index
					$new_index_info[$record['key']] = array('o' => $offset, 's' =>$record['size_total']);
					$existing_count++;
				} else {
					$deletes_cleaned++;
					$deletes_size += $new_index_info[$record['key']]['s'];
					// replace the previous record with the newer record (found later in the append-only data file)
					$new_index_info[$record['key']] = array('o' => $offset, 's' =>$record['size_total']);
				}
				// update cumulative offset
				$offset += $record['size_total'];
				// get next record
				$record = $this->dataMgr->ReadData(-1, TRUE);
			}
				
			// use our memory index to read the latest copy of each record from the existing datafile
			foreach ($new_index_info as $key => $loc) {
				$record = $this->dataMgr->ReadData($loc['o'], TRUE);
				// handle cross-compression functionality
				$trueData = $record['data'];
				if ($cross_compress!==FALSE) {
					// decompress from old format
					if ($this->compressor) { 	$trueData = $this->compressor->Decompress($trueData); 	}
					// recompress (or use NONE compressorClass)
					$trueData = $cross_compress->Compress($trueData);
				}
				// save to the new data file
				$rec_info = $rebuildZDBd->AppendData($key, $trueData);
				$new_index_info[$key] = $rec_info['dataOffset'];
			}

			if ($deletes_cleaned==0 && $cross_compress===FALSE) {
				// we didn't change anything! No need to continue, clean up what we have already done;
				unset($rebuildZDBd);
				unlink($DBZd_file);
			} else {
				// create the new index file
				$DBZi_file_old = $this->indexMgr->getFilename();
				$DBZi_file = $DBZi_file_old."-TEMP";
				if (file_exists($DBZi_file)) { unlink($DBZi_file); }
				$rebuildZDBi = new ZipDBIndexMgr($DBZi_file, "READWRITE");
				// sort the index keys before saving
				ksort($new_index_info);
				// save to the new index file
				foreach($new_index_info as $key=>$offset) {
					$rebuildZDBi->RecordNew($key,$offset);
				}
				unset($new_index_info);

				// close all ZDB data/index files
				unset($rebuildZDBi);
				unset($rebuildZDBd);
				unset($this->indexMgr);
				unset($this->dataMgr);

				// rename the old files to "-OLD"
				rename($DBZi_file_old, $DBZi_file_old."-OLD");
				rename($DBZd_file_old, $DBZd_file_old."-OLD");
				// rename the new files to replace the old ones
				rename($DBZi_file, $DBZi_file_old);
				rename($DBZd_file, $DBZd_file_old);

				// reload the files into the main object classes
				$this->indexMgr = new ZipDBIndexMgr($DBZi_file_old, "READWRITE");
				$this->dataMgr = new ZipDBDataMgr($DBZd_file_old, "READWRITE");
			}
			return array(
				'delete_count' => $deletes_cleaned,
				'delete_bytes' => $deletes_size,
				'existing_count' => $existing_count
			);
		}
		//--------------------------------------------------------------------------------------------------------
		public function AdminRebuildIndex($indexfile="") {
			print "\nREBUILDING INDEX FILE...\n\n";
			// DELETE THE OLD INDEX FILE
			$temp = $this->indexMgr->getFilename();
			unset($this->indexMgr);
			@unlink($temp);
				
			// create empty index file
			if ($indexfile=="") $indexfile = $temp;
			$this->indexMgr = new ZipDBIndexMgr($indexfile, "READWRITE");
			// start building the index
			$recordInfo = $this->dataMgr->ReadData(0, TRUE);
			$offset = 0;
			while ($recordInfo!==FALSE) {
				if ($recordInfo['deleted']==0) {
					// save the information
					$this->indexMgr->RecordNew($recordInfo['key'], $offset);
					print "+";
				} else {
					print "X";
				}
				// print stuff
				print "\t".$recordInfo['key']."\n";
				// update current offset
				$offset = $offset + $recordInfo['size_total'];
				// READ NEXT RECORD
				$recordInfo = $this->dataMgr->ReadData(-1, TRUE);
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function MergeDB($dbfile, $overwrite=FALSE) {
die("\nNeed to debug MergeDB function\n");
			// OPEN THE DB DATA FILE
			if (!is_file($dbfile)) die("Ingest Data File does not exist!");
			$ingestDB = fopen($datafile, 'rb');
			// Shared Lock
//			flock($ingestDB);
			// READ IN THE DATA AND INSERT IT INTO OUR CURRENT DB FILE
			$done = FALSE;
			while (!feof($ingestDB) || !$done) {
				// read the entry legend
				$temp = @fread($ingestDB, 5);
					if (feof($ingestDB)) {
					$done = TRUE;
				} else {
					// read the datafile entry legend
					$temp2 = unpack("C", substr($temp, 0,1));
					$deleted = $temp2[1] >> 7;
					$temp2 = unpack("C", substr($temp, 1,1));
					$keysize = $temp2[1];
					$temp2 = unpack("N", chr(0).substr($temp, 2,3));
					$datasize = $temp2[1];
					// get the key and the data from the datafile
					$temp = fread($this->fileptr, ($keysize + $datasize));
					$key = substr($temp, 0, $keysize);
					$data = substr($temp, $keysize);
					// Save the entry into our current database
					if (!$deleted) $this->RecordSave($key, $data);
				}
			}
			// CLOSE INGEST FILE AND CLEANUP
//			flock($ingestDB, LOCK_UN);
			fclose($ingestDB);
		}
		//--------------------------------------------------------------------------------------------------------
	}

?>