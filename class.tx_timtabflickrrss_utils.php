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
 * File:        class.tx_timtabflickrrss_utils.php - utility methods for 
 * 				working with RSS
 * Author:      Kellan Elliott-McCrea <kellan@protest.net
 * Version:		0.51
 * License:		GPL
 *
 * The lastest version of MagpieRSS can be obtained from:
 * http://magpierss.sourceforge.net
 *
 * For questions, help, comments, discussion, etc., please join the
 * Magpie mailing list:
 * magpierss-general@lists.sourceforge.net
 * 
 */
 
define('RSS', 'RSS');
define('ATOM', 'Atom');
define('MAGPIE_VERSION', '0.61');

$version = explode('.',($GLOBALS['TYPO3_VERSION']?$GLOBALS['TYPO3_VERSION']:$GLOBALS['TYPO_VERSION']));
unset($version[2]);
define('MAGPIE_USER_AGENT', 'TYPO3/' . implode($version,'.'));

$PATH_timtabflickrrss = t3lib_extMgm::extPath('timtab_flickrrss');
require_once($PATH_timtabflickrrss.'class.tx_timtabflickrrss_magpierss.php');
require_once($PATH_timtabflickrrss.'class.tx_timtabflickrrss_rsscache.php');
require_once($PATH_timtabflickrrss.'class.tx_timtabflickrrss_snoopy.php');

class tx_timtabflickrrss_utils {
	/*======================================================================*\
	    Function: parse_w3cdtf
	    Purpose:  parse a W3CDTF date into unix epoch
	
		NOTE: http://www.w3.org/TR/NOTE-datetime
	\*======================================================================*/
	
	function parse_w3cdtf ( $date_str ) {
		
		# regex to match wc3dtf
		$pat = "/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(:(\d{2}))?(?:([-+])(\d{2}):?(\d{2})|(Z))?/";
		
		if ( preg_match( $pat, $date_str, $match ) ) {
			list( $year, $month, $day, $hours, $minutes, $seconds) = 
				array( $match[1], $match[2], $match[3], $match[4], $match[5], $match[6]);
			
			# calc epoch for current date assuming GMT
			$epoch = gmmktime( $hours, $minutes, $seconds, $month, $day, $year);
			
			$offset = 0;
			if ( $match[10] == 'Z' ) {
				# zulu time, aka GMT
			}
			else {
				list( $tz_mod, $tz_hour, $tz_min ) =
					array( $match[8], $match[9], $match[10]);
				
				# zero out the variables
				if ( ! $tz_hour ) { $tz_hour = 0; }
				if ( ! $tz_min ) { $tz_min = 0; }
			
				$offset_secs = (($tz_hour*60)+$tz_min)*60;
				
				# is timezone ahead of GMT?  then subtract offset
				#
				if ( $tz_mod == '+' ) {
					$offset_secs = $offset_secs * -1;
				}
				
				$offset = $offset_secs;	
			}
			$epoch = $epoch + $offset;
			return $epoch;
		}
		else {
			return -1;
		}
	}
	
	function fetch_rss ($url) {
		// initialize constants
		$this->init();
		
		if ( !isset($url) ) {
			$this->error("fetch_rss called without a url");
			return false;
		}
		
		// if cache is disabled
		if ( !MAGPIE_CACHE_ON ) {
			// fetch file, and parse it
			$resp = $this->_fetch_remote_file( $url );
			if ( $this->is_success( $resp->status ) ) {
				return $this->_response_to_rss( $resp );
			}
			else {
				$this->error("Failed to fetch $url and cache is off");
				return false;
			}
		} 
		// else cache is ON
		else {
			// Flow
			// 1. check cache
			// 2. if there is a hit, make sure its fresh
			// 3. if cached obj fails freshness check, fetch remote
			// 4. if remote fails, return stale object, or error
			
			$className = t3lib_div::makeInstanceClassName('tx_timtabflickrrss_rsscache');
    		$cache     = new $className(MAGPIE_CACHE_DIR, MAGPIE_CACHE_AGE);
			
			
			if (MAGPIE_DEBUG and $cache->ERROR) {
				$this->debug($cache->ERROR, E_USER_WARNING);
			}
			
			
			$cache_status 	 = 0;		// response of check_cache
			$request_headers = array(); // HTTP headers to send with fetch
			$rss 			 = 0;		// parsed RSS object
			$errormsg		 = 0;		// errors, if any
			
			if (!$cache->ERROR) {
				// return cache HIT, MISS, or STALE
				$cache_status = $cache->check_cache( $url );
			}
			
			// if object cached, and cache is fresh, return cached obj
			if ( $cache_status == 'HIT' ) {
				$rss = $cache->get( $url );
				if ( isset($rss) and $rss ) {
					$rss->from_cache = 1;
					if ( MAGPIE_DEBUG > 1) {
					$this->debug("MagpieRSS: Cache HIT", E_USER_NOTICE);
				}
					return $rss;
				}
			}
			
			// else attempt a conditional get
			
			// setup headers
			if ( $cache_status == 'STALE' ) {
				$rss = $cache->get( $url );
				if ( $rss->etag and $rss->last_modified ) {
					$request_headers['If-None-Match'] = $rss->etag;
					$request_headers['If-Last-Modified'] = $rss->last_modified;
				}
			}
			
			$resp = $this->_fetch_remote_file( $url, $request_headers );
			
			if (isset($resp) and $resp) {
				if ($resp->status == '304' ) {
					// we have the most current copy
					if ( MAGPIE_DEBUG > 1) {
						$this->debug("Got 304 for $url");
					}
					// reset cache on 304 (at minutillo insistent prodding)
					$cache->set($url, $rss);
					return $rss;
				}
				elseif ( $this->is_success( $resp->status ) ) {
					$rss = $this->_response_to_rss( $resp );
					if ( $rss ) {
						if (MAGPIE_DEBUG > 1) {
							$this->debug("Fetch successful");
						}
						// add object to cache
						$cache->set( $url, $rss );
						return $rss;
					}
				}
				else {
					$errormsg = "Failed to fetch $url. ";
					if ( $resp->error ) {
						# compensate for Snoopy's annoying habbit to tacking
						# on '\n'
						$http_error = substr($resp->error, 0, -2); 
						$errormsg .= "(HTTP Error: $http_error)";
					}
					else {
						$errormsg .=  "(HTTP Response: " . $resp->response_code .')';
					}
				}
			}
			else {
				$errormsg = "Unable to retrieve RSS file for unknown reasons.";
			}
			
			// else fetch failed
			
			// attempt to return cached object
			if ($rss) {
				if ( MAGPIE_DEBUG ) {
					$this->debug("Returning STALE object for $url");
				}
				return $rss;
			}
			
			// else we totally failed
			$this->error( $errormsg );	
			
			return false;
			
		} // end if ( !MAGPIE_CACHE_ON ) {
	} // end fetch_rss()
	
	/*=======================================================================*\
		Function:	error
		Purpose:	set MAGPIE_ERROR, and trigger error
	\*=======================================================================*/
	
	function error ($errormsg, $lvl=E_USER_WARNING) {
			global $MAGPIE_ERROR;
			
			// append PHP's error message if track_errors enabled
			if ( isset($php_errormsg) ) { 
				$errormsg .= " ($php_errormsg)";
			}
			if ( $errormsg ) {
				$errormsg = "MagpieRSS: $errormsg";
				$MAGPIE_ERROR = $errormsg;
				trigger_error( $errormsg, $lvl);				
			}
	}
	
	function debug ($debugmsg, $lvl=E_USER_NOTICE) {
		trigger_error("MagpieRSS [debug] $debugmsg", $lvl);
	}
				
	/*=======================================================================*\
		Function:	magpie_error
		Purpose:	accessor for the magpie error variable
	\*=======================================================================*/
	function magpie_error ($errormsg="") {
		global $MAGPIE_ERROR;
		
		if ( isset($errormsg) and $errormsg ) { 
			$MAGPIE_ERROR = $errormsg;
		}
		
		return $MAGPIE_ERROR;	
	}
	
	/*=======================================================================*\
		Function:	_fetch_remote_file
		Purpose:	retrieve an arbitrary remote file
		Input:		url of the remote file
					headers to send along with the request (optional)
		Output:		an HTTP response object (see Snoopy.class.inc)	
	\*=======================================================================*/
	function _fetch_remote_file ($url, $headers = "" ) {
		// Snoopy is an HTTP client in PHP
		$client = t3lib_div::makeInstance('tx_timtabflickrrss_snoopy');
		$client->agent = MAGPIE_USER_AGENT;
		$client->read_timeout = MAGPIE_FETCH_TIME_OUT;
		$client->use_gzip = MAGPIE_USE_GZIP;
		if (is_array($headers) ) {
			$client->rawheaders = $headers;
		}
		
		@$client->fetch($url);
		return $client;
	
	}
	
	/*=======================================================================*\
		Function:	_response_to_rss
		Purpose:	parse an HTTP response object into an RSS object
		Input:		an HTTP response object (see Snoopy)
		Output:		parsed RSS object (see rss_parse)
	\*=======================================================================*/
	function _response_to_rss ($resp) {
		$className = t3lib_div::makeInstanceClassName('tx_timtabflickrrss_magpierss');
    	$rss       = new $className($resp->results);
		
		// if RSS parsed successfully		
		if ( $rss and !$rss->ERROR) {
			
			// find Etag, and Last-Modified
			foreach($resp->headers as $h) {
				// 2003-03-02 - Nicola Asuni (www.tecnick.com) - fixed bug "Undefined offset: 1"
				if (strpos($h, ": ")) {
					list($field, $val) = explode(": ", $h, 2);
				}
				else {
					$field = $h;
					$val = "";
				}
				
				if ( $field == 'ETag' ) {
					$rss->etag = $val;
				}
				
				if ( $field == 'Last-Modified' ) {
					$rss->last_modified = $val;
				}
			}
			
			return $rss;	
		} // else construct error message
		else {
			$errormsg = "Failed to parse RSS file.";
			
			if ($rss) {
				$errormsg .= " (" . $rss->ERROR . ")";
			}
			$this->error($errormsg);
			
			return false;
		} // end if ($rss and !$rss->error)
	}
	
	/*=======================================================================*\
		Function:	init
		Purpose:	setup constants with default values
					check for user overrides
	\*=======================================================================*/
	function init () {
		if ( defined('MAGPIE_INITALIZED') ) {
			return;
		}
		else {
			define('MAGPIE_INITALIZED', 1);
		}
		
		if ( !defined('MAGPIE_CACHE_ON') ) {
			define('MAGPIE_CACHE_ON', 1);
		}
	
		if ( !defined('MAGPIE_CACHE_DIR') ) {
			define('MAGPIE_CACHE_DIR', './cache');
		}
	
		if ( !defined('MAGPIE_CACHE_AGE') ) {
			define('MAGPIE_CACHE_AGE', 60*60); // one hour
		}
	
		if ( !defined('MAGPIE_CACHE_FRESH_ONLY') ) {
			define('MAGPIE_CACHE_FRESH_ONLY', 0);
		}
	
		if ( !defined('MAGPIE_DEBUG') ) {
			define('MAGPIE_DEBUG', 0);
		}
		
		if ( !defined('MAGPIE_USER_AGENT') ) {
			$ua = 'MagpieRSS/'. MAGPIE_VERSION . ' (+http://magpierss.sf.net';
			
			if ( MAGPIE_CACHE_ON ) {
				$ua = $ua . ')';
			}
			else {
				$ua = $ua . '; No cache)';
			}
			
			define('MAGPIE_USER_AGENT', $ua);
		}
		
		if ( !defined('MAGPIE_FETCH_TIME_OUT') ) {
			define('MAGPIE_FETCH_TIME_OUT', 5);	// 5 second timeout
		}
		
		// use gzip encoding to fetch rss files if supported?
		if ( !defined('MAGPIE_USE_GZIP') ) {
			define('MAGPIE_USE_GZIP', true);	
		}
	}
	
	// NOTE: the following code should really be in Snoopy, or at least
	// somewhere other then rss_fetch!
	
	/*=======================================================================*\
		HTTP STATUS CODE PREDICATES
		These functions attempt to classify an HTTP status code
		based on RFC 2616 and RFC 2518.
		
		All of them take an HTTP status code as input, and return true or false
	
		All this code is adapted from LWP's HTTP::Status.
	\*=======================================================================*/
	
	
	/*=======================================================================*\
		Function:	is_info
		Purpose:	return true if Informational status code
	\*=======================================================================*/
	function is_info ($sc) { 
		return $sc >= 100 && $sc < 200; 
	}
	
	/*=======================================================================*\
		Function:	is_success
		Purpose:	return true if Successful status code
	\*=======================================================================*/
	function is_success ($sc) { 
		return $sc >= 200 && $sc < 300; 
	}
	
	/*=======================================================================*\
		Function:	is_redirect
		Purpose:	return true if Redirection status code
	\*=======================================================================*/
	function is_redirect ($sc) { 
		return $sc >= 300 && $sc < 400; 
	}
	
	/*=======================================================================*\
		Function:	is_error
		Purpose:	return true if Error status code
	\*=======================================================================*/
	function is_error ($sc) { 
		return $sc >= 400 && $sc < 600; 
	}
	
	/*=======================================================================*\
		Function:	is_client_error
		Purpose:	return true if Error status code, and its a client error
	\*=======================================================================*/
	function is_client_error ($sc) { 
		return $sc >= 400 && $sc < 500; 
	}
	
	/*=======================================================================*\
		Function:	is_client_error
		Purpose:	return true if Error status code, and its a server error
	\*=======================================================================*/
	function is_server_error ($sc) { 
		return $sc >= 500 && $sc < 600; 
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_utils.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_utils.php']);
}

?>