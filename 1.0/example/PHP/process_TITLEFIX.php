<?php

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~// 
//	ZipBase Example
//	(C)opyright 2009-2014, Nick Benik
//	http://research.hackerceo.org/ZipBase
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//


	class process_TITLEFIX implements zipbaseIProcessor {
		private $stats;
		private $lookups;
    
		public function __construct($db) {
			print "\nLoaded TITLE FIX Processor\n";
			mysql_select_db('pmid_lookup');
			$this->clearStatistics();
		}
    
		public function processXML($xml_string) {
			// load the xml
			$xmlDOM = simplexml_load_string($xml_string);
			// get the pmid
			$temp = $xmlDOM->xpath('/PubmedArticleSet/PubmedArticle/MedlineCitation/PMID/text()');
			$pmid = trim((string)$temp[0]);

			// lookup the pub_id
			$result = mysql_query("SELECT pub_id FROM crawl_pmid_list WHERE pmid=".(int)$pmid);
			if ($row=mysql_fetch_assoc($result)) {
				$pub_id = (int)$row["pub_id"];
				// process the title
				$titles = $xmlDOM->xpath('/PubmedArticleSet/PubmedArticle/MedlineCitation/Article/ArticleTitle');
				$title = trim((string)$titles[0]);
				mysql_query('UPDATE pub_list SET title = "'.mysql_real_escape_string($title).'" WHERE pid='.$pub_id);
				$this->stats["processed"]++;
			} else {
				print "**** ERROR for PMID ".$pmid."\n";
				$this->stats["errors"]++;
			}
		}
        
		public function getStatistics() {
			return $this-stats;
		}
		
		public function clearStatistics() {
			$this->stats = array("processed" => 0, "errors" => 0);
			return $this->stats;
		}
    
    }

?>