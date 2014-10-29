<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Engine
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//


// ======================================================================================
	class ZipDBIndexMgr {
		private $isReady = FALSE;
		private $current_index_page;
		private $index_page_dictionary = array();
		private $fileptr = FALSE;
		private $isSorted = TRUE;
		private $isDirty = FALSE;
		private $readwritemode = FALSE;
		private $indexfilepathname = FALSE;
		private $deleted_entries = 0;
		private $rebuild_required = FALSE;

		//--------------------------------------------------------------------------------------------------------
		function getFilename() { return $this->indexfilepathname; }
		//--------------------------------------------------------------------------------------------------------
		function requiresRebuild() { return $this->rebuild_required; }
		//--------------------------------------------------------------------------------------------------------
		function __construct($indexfile, $mode) {
			// extract our mode settings
			if (strpos($mode,"READWRITE")!==FALSE) $this->readwritemode = "W";
			if (strpos($mode,"READONLY")!==FALSE) $this->readwritemode = "R";
			if ($this->readwritemode===FALSE) {
				print 'A valid read/write mode was not found assumming "READONLY" [alternate "READWRITE"]'."\n";
				$this->readwritemode = "R";
			}
			if ($this->readwritemode=="W") {
				if (!is_file($indexfile)) {
					// INDEX FILE IS MISSING, See if we need to rebuild it
					if (strpos($mode, "INDEX:REBUILD")!==FALSE) {
						$this->rebuild_required = TRUE;
					}
				}
				if (is_file($indexfile) && !is_writable($indexfile)) die("Index File is not writable!");
				$this->fileptr = @fopen($indexfile, 'r+b');
				if (!$this->fileptr) { $this->fileptr = @fopen($indexfile, 'a+b'); }
//				$this->fileptr = fopen($indexfile, 'a+b');
				rewind($this->fileptr);
				if ($this->fileptr===FALSE) die("Index File cannot be opened for writing! (maybe another process is using it)");
				// NEW LOCKING SYSTEM
				
				// ingest the file into memory if rebuild is not required
				if (!$this->rebuild_required) $this->ScanIndex();
			} else {
				if (!is_file($indexfile)) die("Index file does not exist!");
				$this->fileptr = fopen($indexfile, 'rb');
				// NEW LOCKING SYSTEM
			}
			$this->indexfilepathname = $indexfile;
			$this->isDirty = FALSE;
		}
		//--------------------------------------------------------------------------------------------------------
		function __destruct() {
			if ($this->fileptr!=FALSE) {
				flock($this->fileptr, LOCK_UN);
				fclose($this->fileptr);
			}
			$this->isReady = FALSE;
			$this->current_index_page = FALSE;
			$this->index_page_dictionary = array();
			$this->fileptr = FALSE;
			$this->isSorted = TRUE;
			$this->isDirty = FALSE;
			$this->indexfilepathname = FALSE;
		}
		//--------------------------------------------------------------------------------------------------------
		private function ScanIndex($pagesize=1000000) {
			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_SH);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_SH);
			}
				
			$entry = FALSE;
			$entry_offset = FALSE;
			$db_offset = FALSE;
			$deletes = 0;
			// reset everything
			rewind($this->fileptr);
			$this->current_index_page = array();
			$this->index_page_dictionary = array();
			// read in the entire index
			$index_entry = $this->ReadEntry();
			while($index_entry !== FALSE) {
				if ($index_entry['deleted']) {
					$deletes++;
				} else {
					$key = $index_entry['key'];
					unset($index_entry['key']);
					unset($index_entry['deleted']);
					$this->current_index_page[$key] = array(
						ZDB_INDEX_DATAOFFSET => $index_entry['dataOffset'],
						ZDB_INDEX_INDEXOFFSET => $index_entry['indexOffset']
					);
				}
				// get next entry
				$index_entry = $this->ReadEntry();
			}
			$this->deleted_entries = $deletes;
			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);
		}
		//--------------------------------------------------------------------------------------------------------
		private function ReadEntry($offset=FALSE) {
			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_SH);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_SH);
			}
			
			// assume we are at the start char of an index entry
			if (!$offset===FALSE) fseek($this->fileptr, $offset);
			if (feof($this->fileptr)) return FALSE;
			// remember our index file offset
			$indexoffset = ftell($this->fileptr);
			// read and process the 2 char entry legend
			$temp = fread($this->fileptr, 2);
			if (feof($this->fileptr)) return FALSE;
			$keyprefix = unpack("n", $temp);
			$keyprefix = $keyprefix[1];
			$deleted = $keyprefix >> 15;
			$offsetsize = ($keyprefix >> 8) & 15;  // how many bytes is the offset (only 4 bytes supported for now)
			$keysize = ($keyprefix & 255);
			// pull the record's key
			$key = @fread($this->fileptr, $keysize);
			// pull+calculate the record's offset in the data file;
			$temp = fread($this->fileptr, $offsetsize);
			$offset = unpack("N", $temp);
			
			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);
			
			// return the entry
			return array(
				"deleted" => $deleted,
				"key" => $key,
				"dataOffset" => $offset[1],
				"indexOffset" => $indexoffset
			);
		}
		//--------------------------------------------------------------------------------------------------------
		private function WriteEntry($key, $offset) {
			// INDEX LEGEND____________________________________
			//               delete = 1000 0000 0000 0000
			// offset size in bytes = 0000 1111 0000 0000
			//  key length in bytes = 0000 0000 1111 1111
			// [legend] [key] [datafile Offset (4 bytes)]

			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_EX);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_EX);
			}

			$indexoffset = ftell($this->fileptr);
			// calculate the entry legend (for now always DeleteFlag=0, offsetBytes=4)
			$keylen = strlen($key);
			$legend = pack("CC", 4, $keylen);
			$binoffset = pack("N", $offset);
			// write the entry into the index file
			fwrite($this->fileptr,$legend.$key.$binoffset, 6+$keylen);
			// flush the buffer
			fflush($this->fileptr);

			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);

			// add to memory store
			$record = array(ZDB_INDEX_INDEXOFFSET => $indexoffset, ZDB_INDEX_DATAOFFSET => $offset);
			$this->current_index_page[$key] = $record;
			return $record;
		}
		//--------------------------------------------------------------------------------------------------------
		private function AppendEntry($key, $offset) {
			fseek($this->fileptr, 0, SEEK_END);
			return $this->WriteEntry($key, $offset);
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordGet($key) {
			$indexrecord = @$this->current_index_page[$key];
			if (!$indexrecord) {
				return FALSE;
			} else {
				return $indexrecord;
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordErase($key) {
//  TODO: this needs to be fixed for concurrent access
			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_EX);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_EX);
			}

			// find the index entry in our memory store
			$indexrecord = $this->current_index_page[$key];
			if (!$indexrecord) return false;
			// delete the entry from the index file
			fseek($this->fileptr, $indexrecord[ZDB_INDEX_INDEXOFFSET]);
			fwrite($this->fileptr, pack('C', 132), 1);
			
			// delete the entry from memory
			unset($this->current_index_page[$key]);
			$this->isDirty = TRUE;

			// flush the buffer
			fflush($this->fileptr);

			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);			
			
			return TRUE;
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordUpdate($key, $offset) {
			// check to see if the entry already exists
			$indexrecord = $this->current_index_page[$key];
			if ($indexrecord) {
				// update the old entry
				die("todo");
			} else {
				// append the new entry into the end of the index file
				return $this->AppendEntry($key, $offset);
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function RecordNew($key, $offset) {
			// fail if the record is not new
			$indexrecord = @$this->current_index_page[$key];
			if ($indexrecord) {
				return FALSE;
			} else {
				// append the new entry into the end of the index file
				return $this->AppendEntry($key, $offset);
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function ReadAllKeys() {
			// just return an array of all the key values
			return array_keys($this->current_index_page);
		}
		//--------------------------------------------------------------------------------------------------------
	}

?>