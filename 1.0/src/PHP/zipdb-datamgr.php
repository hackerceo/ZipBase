<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Engine
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

// ======================================================================================
	class ZipDBDataMgr {
		private $fileptr = FALSE;
		private $readwritemode = FALSE;
		private $isDirty = FALSE;
		private $datafilepathname = FALSE;
		
		//--------------------------------------------------------------------------------------------------------
		function getFilename() { return $this->datafilepathname; }
		//--------------------------------------------------------------------------------------------------------
		function __construct($datafile, $mode) {
			// extract our mode settings
			if (strpos($mode,"READWRITE")!==FALSE) $this->readwritemode = "W";
			if (strpos($mode,"READONLY")!==FALSE) $this->readwritemode = "R";
			if ($this->readwritemode===FALSE) {
				print 'A valid read/write mode was not found assumming "READONLY" [alternate "READWRITE"]'."\n";
				$this->readwritemode = "R";
			}
			if ($this->readwritemode=="W") {
				if (is_file($datafile) && !is_writable($datafile)) die("Data File exists but is not writable!");
				$this->fileptr = @fopen($datafile, 'r+b');
				if (!$this->fileptr) {	$this->fileptr = fopen($datafile, 'a+b');	}
// $this->fileptr = fopen($datafile, 'a+b');
				rewind($this->fileptr);
				if ($this->fileptr===FALSE) die("Data File cannot be opened for writing! (maybe another process is using it)");
				// NEW LOCKING SYSTEM IMPLEMENTED
			} else {
				if (!is_file($datafile)) die("Data File does not exist!");
				$this->fileptr = fopen($datafile, 'rb');
				// NEW LOCKING SYSTEM IMPLEMENTED
			}
			$this->datafilepathname = $datafile;
		}        
		//--------------------------------------------------------------------------------------------------------
		function __destruct() {
			if ($this->fileptr!=FALSE) {
				flock($this->fileptr, LOCK_UN);
				fclose($this->fileptr);
			}
			$this->fileptr = FALSE;
			$this->isDirty = FALSE;
			$this->datafilepathname = false;
		}
		//--------------------------------------------------------------------------------------------------------
		public function AppendData($key, $data) {
			// create the record legend
			// ----------------------------------------------------------
			// deleted  b'10000000 00000000 00000000 00000000 00000000'
			// keysize  b'00000000 11111111 00000000 00000000 00000000'
			// datasize b'00000000 00000000 11111111 11111111 11111111'
			// ----------------------------------------------------------
			// [deleted] [keysize] [datasize] [key data] [record data]
			//   1byte     1byte     3bytes    x bytes     z bytes
			// ----------------------------------------------------------
			$keylen = strlen($key) & 255;
			$datalen = strlen($data);
			if ($datalen > 16777215) {
				print "Record data too big: key=$key";
				return FALSE;
			}
			$legend = pack("C", 0).pack("N", ($datalen & 16777215) + ($keylen << 24));
			$recordsize = 5+$keylen+$datalen;

			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_EX);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_EX);
			}

			// move to the end of the data file
			fseek($this->fileptr, 0, SEEK_END);
			$dataoffset = ftell($this->fileptr);
			// write the entry into the data file
			fwrite($this->fileptr, $legend.substr($key,0,$keylen).$data, $recordsize);
			// flush the buffer
			fflush($this->fileptr);

			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);

			// add to memory store
			return array('dataOffset' => $dataoffset, 'recordSize'=>$recordsize);
		}
		//--------------------------------------------------------------------------------------------------------
		public function ReadData($offset,$extra_info=false) {
			// NEW LOCKING SYSTEM
			$good_lock = flock($this->fileptr,LOCK_SH);
			while (!$good_lock) {
				usleep(100000); // wait for 100ms
				$good_lock = flock($this->fileptr,LOCK_SH);
			}

			// read the record at the given location
			if ($offset > -1) fseek($this->fileptr, $offset, SEEK_SET);
			// read the entry legend
			$temp = @fread($this->fileptr, 5);
			if (feof($this->fileptr)) return FALSE;
			$temp2 = unpack("C", substr($temp, 0,1));
			$deleted = $temp2[1] >> 7;
			$temp2 = unpack("C", substr($temp, 1,1));
			$keysize = $temp2[1];
			$temp2 = unpack("N", chr(0).substr($temp, 2,3));
			$datasize = $temp2[1];
			// get the key and the data
			$temp = fread($this->fileptr, ($keysize + $datasize));
			$key = substr($temp, 0, $keysize);
			$data = substr($temp, $keysize);

			// NEW LOCKING SYSTEM
			flock($this->fileptr, LOCK_UN);

			// return results
			$return = array();
			if ($extra_info) {
				$return['deleted'] = $deleted;
				$return['size_key'] = $keysize;
				$return['size_data'] = $datasize;
				$return['size_total'] = (5 + $keysize + $datasize);
				$return['key']  = $key;
			}
			if ($deleted && !$extra_info) {
				return FALSE;
			} else {
				$return['key']  = $key;
				$return['data'] = $data;
				return $return;
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function DeleteData($key, $offset) {
			$this->isDirty = TRUE;
			// read the record at the given location
			fseek($this->fileptr, $offset, SEEK_SET);
			// read the entry legend
			$temp = @fread($this->fileptr, 5);
			if (feof($this->fileptr)) return FALSE;
			$temp2 = unpack("C", substr($temp, 0,1));
			$deleted = $temp2[1] >> 7;
			$temp2 = unpack("C", substr($temp, 1,1));
			$keysize = $temp2[1];
			$temp2 = unpack("N", chr(0).substr($temp, 2,3));
			$datasize = $temp2[1];
			// get the key
			$reckey = fread($this->fileptr, $keysize);
			// IF THE KEY IS THE SAME THEN DELETE RECORD
			if ($reckey==$key) {
				// seek to the beginging of the record
				fseek($this->fileptr, $offset, SEEK_SET);
				// write the delete bit
				$temp2 = unpack("C", substr($temp, 0,1))[1];
				$temp2 = $temp2 | 128;
				fwrite($this->fileptr, pack("C",$temp2), 1);
				return TRUE;
			} else {
				return FALSE;
			}
		}
		//--------------------------------------------------------------------------------------------------------	
	}
?>