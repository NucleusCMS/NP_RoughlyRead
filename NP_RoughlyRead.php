<?php
class NP_RoughlyRead extends NucleusPlugin {
	function getName()           { return 'Roughly Read'; }
	function getAuthor()         { return 'Reine'; }
	function getURL()            { return 'http://japan.nucleuscms.org/wiki/plugins:roughlyread'; }
	function getVersion()        { return '1.21.1'; }
	function getDescription()    {
		return $this->translated('It begins to pick up the clause including the search words and phrases.\n')
			. $this->translated('Example. &lt;%RoughlyRead(250,1)%&gt;');
	}
	function supportsFeature($w) { return ($w == 'SqlTablePrefix') ? 1 : 0; }
	function getEventList()      { return array(); }
	
	function install() {
		/* Paragraph terminal character */
		switch (strtolower(_CHARSET)) {
			case 'utf-8':
				$line_delim = $this->mb_chr(14909570);
				break;
			case 'euc-jp':
				$line_delim = $this->mb_chr(41379);
				break;
			default:
				$line_delim = $this->mb_chr(46);
		}
		/* Add Option */
		$this->createOption('terminal_character',
			$this->translated('The terminal character used for the paragraph division excluding the LF.'),
			'text', $line_delim);
		$this->createOption('abbreviation_sring',
			$this->translated('Character string used when sentences are abbreviated.'),
			'text', '...');
		$this->createOption('separator_sring', 
			$this->translated('Character string that ties continuing clause.'),
			'text', '');
	}
	
	/* Chr function for multi byte */
	function mb_chr($num){
		return ($num < 256) ? chr($num) : $this->mb_chr($num / 256).chr($num % 256);
	}

	function uninstall() {
		/* Del Option */
		$this->deleteOption('terminal_character');
		$this->deleteOption('abbreviation_sring');
		$this->deleteOption('separator_sring');
	}
	
	function init() {
		/* Option read */
		$this->abbreviation = $this->getOption('abbreviation_sring');
		$this->line_delim = $this->getOption('terminal_character');
		$this->line_sep = $this->getOption('separator_sring');
	}
	
	/* Make Search word array */
	function parseHighlight($query) {
		// get rid of quotes
		$query = preg_replace('/\'|"/','',$query);
		
		if (!$query) return array();
		
		$aHighlight = explode(' ', $query);
		for ($i = 0; $i < count($aHighlight); $i++) {
			$aHighlight[$i] = trim($aHighlight[$i]);
		}
		
		return $aHighlight;
	}
	
	function roughRead($str, $highlights, $maxLength){
		mb_regex_encoding(_CHARSET);
		
		/* Highlights repetition is deleted */
		if (count($highlights) > 1) {
			for ($i = count($highlights) - 1; $i > 0; $i--) {
				$j = array_search($highlights[$i], $highlights);
				if ($j !== FALSE && $j != (count($highlights) - 1)) {
					array_splice($highlights, $i, 1);
				}
			}
		}
		
		/* Get paragraph line feed */
		$lines = preg_split("/[\r\n]+/", $str, -1, PREG_SPLIT_NO_EMPTY);
		
		/* Split paragraph terminal character */
		$i = 0;
		while ($i < count($lines)) {
			if (!$lines[$i]) {
				array_splice($lines, $i, 1);
				continue;
			}
			$sPos = mb_stripos($lines[$i], $this->line_delim, 0, _CHARSET);
			if ($sPos !== FALSE) {
				$repLines = array();
				if ($sPos + 1 < mb_strlen($lines[$i])) {
					$repLines[] = mb_substr($lines[$i], 0, $sPos + 1, _CHARSET);
					$repLines[] = mb_substr($lines[$i], $sPos + 1, mb_strlen($lines[$i], _CHARSET), _CHARSET);
				}
				if (count($repLines) > 0) {
					array_splice($lines, $i, 1, $repLines);
				}
			}
			$i++;
		}
		
		/* Return value */
		$cStr = "";
		
		/* Check search words in paragraph */
		$firstPos = -1;
		$pickesPos = -1;
		for ($i = 0; $i < count($lines); $i++) {
			foreach ($highlights as &$highlight) {
				if (!empty($highlight) && mb_stripos($lines[$i], $highlight, 0, _CHARSET) !== FALSE) {
					if ($pickesPos != -1 && ($pickesPos + 1) < $i) {
						$cStr .= $this->abbreviation;
					} elseif ($cStr) {
						$cStr .= $this->line_sep;
					}
					$cStr .= $lines[$i];
					if ($pickesPos == -1) {
						$firstPos = $pickesPos = $i;
					} else {
						$pickesPos = $i;
					}
					
					if (mb_strlen($cStr, _CHARSET) > $maxLength) {
						$cStr = mb_substr($cStr, 0, $maxLength - mb_strlen($this->abbreviation, _CHARSET), _CHARSET).$this->abbreviation;
						break 2;
					}
					break;
				}
			}
		}
		unset($highlight);
		
		/* Extended back */
		if (mb_strlen($cStr, _CHARSET) < $maxLength) {
			for ($i = $pickesPos + 1; $i < count($lines); $i++) {
				$cStr .= $this->line_sep.$lines[$i];
				if (mb_strlen($cStr, _CHARSET) > $maxLength) {
					$cStr = mb_substr($cStr, 0, $maxLength - mb_strlen($this->abbreviation, _CHARSET), _CHARSET).$this->abbreviation;
					break;
				}
			}
		}
		
		/* Extended front */
		if (mb_strlen($cStr, _CHARSET) < $maxLength) {
			for ($i = $firstPos - 1; $i > 0; $i--) {
				if (mb_strlen($lines[$i].$this->line_sep.$cStr, _CHARSET) > $maxLength) {
					$cStr = $this->abbreviation.mb_substr($cStr, mb_strlen($this->abbreviation, _CHARSET), $maxLength, _CHARSET);
					break;
				}
				$cStr = $lines[$i].$this->line_sep.$cStr;
			}
		}
		
		return $cStr;
	}
	
	function doTemplateVar(&$item, $maxLength = 250, $addHighlight = 0){
		global $manager, $query;
		
		$searchclass = new SEARCH($query);
		$highlights = $this->parseHighlight($searchclass->inclusive);
		
		if ($maxLength == 'title' || $addHighlight) {
			global $currentTemplateName;
			$template =& $manager->getTemplate($currentTemplateName);
		}
		
		if ($maxLength == 'title') {
			/* title highlight */
			echo highlight($item->title, $highlights, $template['SEARCH_HIGHLIGHT']);
		} else {
			$syndicated = strip_tags($item->body);
			$syndicated .= strip_tags($item->more);
			
			$syndicated = $this->roughRead($syndicated, $highlights, $maxLength);
			
			if ($addHighlight) {
				echo highlight($syndicated, $highlights, $template['SEARCH_HIGHLIGHT']);
			} else {
				echo $syndicated;
			}
		}
	}
	
	/* Language stuff */
	var $langArray;
	function translated($english){
		if (!is_array($this->langArray)) {
			$this->langArray=array();
			$language=$this->getDirectory().preg_replace( '[\\|/]', '', getLanguageName()).'.php';
			if (file_exists($language)) include($language);
		}
		if (!($ret=$this->langArray[$english])) $ret=$english;
		return $ret;
	}
}
?>
