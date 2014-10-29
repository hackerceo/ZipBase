<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Engine
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

// ======================================================================================
	abstract class aCompressionEngine {
		//--------------------------------------------------------------------------------------------------------
		abstract protected function compress($inputstr, $density = FALSE);
		//--------------------------------------------------------------------------------------------------------
		abstract protected function uncompress($compressedstr);
		//--------------------------------------------------------------------------------------------------------
		public function getInfo() {
			$isSupported = true;
			foreach ($this->function_list as $neededFunct) {
				if (!function_exists($neededFunct)) {
					$isSupported = false;
					break;
				}
			}
			return array(
				'format' => $this->compress_code,
				'isSupported' => (int)(boolean)$isSupported
			);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class BZEngine extends aCompressionEngine {
		protected $compress_code = "BZ";
		protected $function_list = array('bzcompress','bzdecompress');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			if ($density!=FALSE) {
				$blocksize = int(10 * $density);
				if ($blocksize>9) $density=9;
				if ($blocksize<0) $density=0;
				return bzcompress($inputstr, $blocksize);
			} else {
				return bzcompress($inputstr);
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return bzdecompress($compressedstr);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class LZFEngine extends aCompressionEngine {
		protected $compress_code = "LZF";
		protected $function_list = array('lzf_compress','lzf_decompress');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			return lzf_compress($inputstr);
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return lzf_decompress($compressedstr);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class DeflateEngine extends aCompressionEngine {
		protected $compress_code = "DEFLATE";
		protected $function_list = array('gzdeflate','gzinflate');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			return gzdeflate($inputstr);
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return gzinflate($compressedstr);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class GZIPEngine extends aCompressionEngine {
		protected $compress_code = "GZIP-FILE";
		protected $function_list = array('gzencode','gzdecode');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			return gzencode($inputstr);
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return gzdecode($compressedstr);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class ZLIBEngine extends aCompressionEngine {
		// use this if you want to fee the compressed data to a browser without decompression it!
		protected $compress_code = "GZIP-BROWSER";
		protected $function_list = array('gzcompress','gzuncompress');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			return gzcompress($inputstr, 9); // MAX COMPRESSION
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return @gzuncompress($compressedstr);
		}
		//--------------------------------------------------------------------------------------------------------
	}

// ======================================================================================
	class NoCompressEngine extends aCompressionEngine {
		protected $compress_code = "NONE";
		protected $function_list = array('strpos','strpos');
		//--------------------------------------------------------------------------------------------------------
		public function compress($inputstr, $density = FALSE) {
			return $inputstr;
		}
		//--------------------------------------------------------------------------------------------------------
		public function uncompress($compressedstr) {
			return $compressedstr;
		}
		//--------------------------------------------------------------------------------------------------------
	}


// ======================================================================================
	class CompressionRouter {
		private $engines = array();
		private $scheme = FALSE;
		//--------------------------------------------------------------------------------------------------------
		function __destruct() { unset($this->engines); }
		//--------------------------------------------------------------------------------------------------------
		function __construct($mode=FALSE) {
			$classes = get_declared_classes();
			foreach ($classes as $classname) {
				if (is_subclass_of($classname, 'aCompressionEngine')) {
					$engine = new $classname;
					$engineInfo = $engine->getInfo();
					$this->engines[$engineInfo['format']] = array_merge($engineInfo, array("engine" => $engine));
				}
			}
			// extract our mode settings
			$pos = strpos($mode,"COMPRESS:");
			if ($pos!==FALSE) {
			$scheme = substr($mode, $pos+9);
			// stop at first space
			$pos = strpos($scheme, " ");
			if ($pos!==FALSE) $scheme = substr($scheme, 0, $pos);

			// save default compression engine
			if ($scheme!==FALSE) $this->setCompressionType(trim($scheme));
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function getCompressionType() { return $this->scheme; }
		//--------------------------------------------------------------------------------------------------------
		public function setCompressionType($scheme) {
			if (array_key_exists($scheme, $this->engines)) {
				$this->scheme = $scheme;
				return $scheme;
			} else {
				return FALSE;
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function Compress($data, $density=FALSE) {
			if ($this->scheme===FALSE) die("Attempting to use CompressionRouter::Compress() without a valid compression scheme being set!\n");
			return $this->CompressWith($this->scheme, $data, $density);
		}
		//--------------------------------------------------------------------------------------------------------
		public function Decompress($data) {
			if ($this->scheme===FALSE) die("Attempting to use CompressionRouter::Decompress() without a valid compression scheme being set!\n");
			return $this->DecompressWith($this->scheme, $data);
		}
		//--------------------------------------------------------------------------------------------------------
		public function CompressWith($format, $data, $density=FALSE) {
			if (!$this->engines[$format]['isSupported']) die("ERROR: $format format is not supported by this environment!\n");
			try {
				if ($density == FALSE) {
					return $this->engines[$format]['engine']->compress($data);
				} else {
					return $this->engines[$format]['engine']->compress($data, $density);
				}
			} catch (Exception $e) {
				print_r($e);
				die("Caught exception in CompressionRouter::CompressWith('$format',...)");
			}
		}
		//--------------------------------------------------------------------------------------------------------
		public function DecompressWith($format, $data) {
			if (!$this->engines[$format]['isSupported']) die("ERROR: $format format is not supported by this environment!\n");
			try {
				return $this->engines[$format]['engine']->uncompress($data);
			} catch (Exception $e) {
				print_r($e);
				die("Caught exception in DecompressionRouter::DecompressWith('$format',...)");
			}
		}
		//--------------------------------------------------------------------------------------------------------
	}

?>