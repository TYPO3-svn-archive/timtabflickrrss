<?php
/***************************************************************
*  Copyright notice
*
*  (c) Ingo Renner (typo3@ingo-renner.com)
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * 
 * Project:     MagpieRSS: a simple RSS integration tool
 * File:        class.tx_timtabflickrrss_rsscache.php -  simple, rolling(no GC), 
 * 				cache for RSS objects, keyed on URL.
 * Author:      Kellan Elliott-McCrea <kellan@protest.net>
 * Version:		0.51
 * License:		GPL
 * 
 */


require_once(PATH_t3lib.'class.t3lib_div.php');
require_once(PATH_t3lib.'class.t3lib_befunc.php');

class tx_timtabflickrrss_rsscache {
	var $BASE_CACHE = './cache';	// where the cache files are stored
	var $MAX_AGE	= 3600;  		// when are files stale, default one hour
	var $ERROR 		= '';			// accumulate error messages
	
	function tx_timtabflickrrss_rsscache ($base='', $age=0) {
		/*
		if ( $base ) {
			$this->BASE_CACHE = $base;
		}
		*/
		$this->BASE_CACHE = t3lib_div::getIndpEnv('TYPO3_DOCUMENT_ROOT')
							.'/uploads/tx_timtabflickrrss';
							
		if ( $age ) {
			$this->MAX_AGE = $age;
		}
		
		// attempt to make the cache directory
		/*
		if ( ! file_exists( $this->BASE_CACHE ) ) {
			$status = @mkdir( $this->BASE_CACHE, 0755 );
			
			// if make failed 
			if ( ! $status ) {
				$this->error(
					"Cache couldn't make dir '" . $this->BASE_CACHE . "'."
				);
			}
		}
		*/
	}
	
/*=======================================================================*\
	Function:	set
	Purpose:	add an item to the cache, keyed on url
	Input:		url from wich the rss file was fetched
	Output:		true on sucess	
\*=======================================================================*/
	function set ($url, $rss) {
		$hash = $this->file_name($url);
		
		t3lib_befunc::storeHash(
			$hash,
			$this->serialize($rss),
			'TIMTAB filckrRSS'
		);
		
		return $hash;
	}
	
/*=======================================================================*\
	Function:	get
	Purpose:	fetch an item from the cache
	Input:		url from wich the rss file was fetched
	Output:		cached object on HIT, false on MISS	
\*=======================================================================*/	
	function get ($url) {
		$this->ERROR = '';
		
		$hash = $this->file_name($url);
		
		$cachedRss = t3lib_befunc::getHash($hash);
		if(!$cachedRss) {
			$this->debug( 
				'Cache doesn\'t contain: '.$url.' (cache_hash: '.$hash.')'
			);
			return 0;
		}
		
		return $this->unserialize($cachedRss);
	}

/*=======================================================================*\
	Function:	check_cache
	Purpose:	check a url for membership in the cache
				and whether the object is older then MAX_AGE (ie. STALE)
	Input:		url from wich the rss file was fetched
	Output:		cached object on HIT, false on MISS	
\*=======================================================================*/		
	function check_cache ( $url ) {
		$status = '';
		$hash   = $this->file_name( $url );
		
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'tstamp', 
			'cache_hash', 
			'hash='.$GLOBALS['TYPO3_DB']->fullQuoteStr($hash, 'cache_hash')
		);						
		
		if($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			// find how long ago the file was added to the cache
			// and whether that is longer then MAX_AGE
			$age = time() - $row['tstamp'];
			if ( $this->MAX_AGE > $age ) {
				// object exists and is current
				$status = 'HIT';
			}
			else {
				// object exists but is old
				$status = 'STALE';
			}
		}
		else {
			// object does not exist
			$status = 'MISS';
		}
		
		return $status;
	}

/*=======================================================================*\
	Function:	serialize
\*=======================================================================*/		
	function serialize ( $rss ) {
		return serialize( $rss );
	}

/*=======================================================================*\
	Function:	unserialize
\*=======================================================================*/		
	function unserialize ( $data ) {
		return unserialize( $data );
	}
	
/*=======================================================================*\
	Function:	file_name
	Purpose:	map url to location in cache
	Input:		url from wich the rss file was fetched
	Output:		a file name
\*=======================================================================*/		
	function file_name ($url) {
		return md5( $url );
	}

/*=======================================================================*\
	Function:	error
	Purpose:	register error
\*=======================================================================*/			
	function error ($errormsg, $lvl=E_USER_WARNING) {
		// append PHP's error message if track_errors enabled
		if ( isset($php_errormsg) ) { 
			$errormsg .= " ($php_errormsg)";
		}
		$this->ERROR = $errormsg;
		if ( MAGPIE_DEBUG ) {
			trigger_error( $errormsg, $lvl);
		}
		else {
			error_log( $errormsg, 0);
		}
	}
	
	function debug ($debugmsg, $lvl=E_USER_NOTICE) {
		if ( MAGPIE_DEBUG ) {
			$this->error("MagpieRSS [debug] $debugmsg", $lvl);
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_rsscache.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_rsscache.php']);
}

?>