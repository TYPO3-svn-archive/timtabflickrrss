<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2006 Ingo Renner <typo3@ingo-renner.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
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
 * Plugin 'TIMTAB flickr RSS' for the 'timtab_flickrrss' extension.
 *
 * @author	Dave Kellam
 * @link 	http://eightface.com
 * @author	Ingo Renner <typo3@ingo-renner.com>
 */

$PATH_timtab_flickrrss = t3lib_extMgm::extPath('timtab_flickrrss');
require_once($PATH_timtab_flickrrss.'class.tx_timtabflickrrss_utils.php');
require_once(PATH_tslib.'class.tslib_pibase.php');

class tx_timtabflickrrss_pi1 extends tslib_pibase {
	var $prefixId		= 'tx_timtabflickrrss_pi1';					// Same as class name
	var $scriptRelPath	= 'pi1/class.tx_timtabflickrrss_pi1.php';	// Path to this script relative to the extension dir.
	var $extKey			= 'timtab_flickrrss';						// The extension key.
	var $pi_checkCHash	= true;
	
	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	function main($content, $conf)	{
		$content = '';
		$this->init($conf);
		
		// get the feeds
		switch($this->conf['displayType']) {
			case 'public':
				$rssUrl = 'http://api.flickr.com/services/feeds/photos_public.gne?tags='
					.$this->conf['tags'].'&format=rss_200';
				break;
			case 'user':
				$rssUrl = 'http://api.flickr.com/services/feeds/photos_public.gne?id='
					.$this->conf['userId'].'&tags='.$this->conf['tags'].'&format=rss_200';
				break;
			case 'group':
				$rssUrl = 'http://api.flickr.com/services/feeds/groups_pool.gne?id='
					.$this->conf['userId'].'&format=rss_200';
				break;
		}
	
		# get rss file
		$utils = t3lib_div::makeInstance('tx_timtabflickrrss_utils');
		$rss   = $utils->fetch_rss($rssUrl);
		
		if($rss) {
			$imgUrl = '';
			// specifies number of pictures
			$items = array_slice($rss->items, 0, $this->conf['numImages']);
			
			// build html from array
			foreach($items as $item) {
				$imgUrlMatches = array();
				if(preg_match('<img src="([^"]*)" [^/]*/>', $item['description'], $imgUrlMatches)) {
					$imgUrl = $imgUrlMatches[1];
					
					//change image size
					switch($this->conf['imgSize']) {
						case 'square':
							$imgUrl = str_replace('m.jpg', 's.jpg', $imgUrl);
							break;
						case 'thumbnail':
							$imgUrl = str_replace('m.jpg', 't.jpg', $imgUrl);
							break;
						case 'medium':
							$imgUrl = str_replace('_m.jpg', '.jpg', $imgUrl);
							break;
					}
					
					$title = htmlspecialchars(stripslashes($item['title']));
           			$url   = $item['link'];
					
					$flickrSlugMatches = array();
					preg_match('<http://static.flickr\.com/\d\d?\/([^.]*)\.jpg>', $imgUrl, $flickrSlugMatches);
	       			$flickrSlug = $flickrSlugMatches[1];
	       			
	       			if($this->conf['renderList']) {
	       				$content .= '<li>';	
	       			}
	       			
	       			// get image direct from flickr
                	$content .= '<a href="'.$url.'" title="'.$title.'">'
								.'<img src="'.$imgUrl.'" alt="'.$title.'" />'
								.'</a>'.chr(10);  
				}
			}
		}		
		
		if($this->conf['renderList']) {
			$content = '<ul>'.chr(10).$content.chr(10).'</ul>';	
		}
	
		return $this->pi_wrapInBaseClass($content);
	}
	
	function init($conf) {
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();		
		$this->pi_initPIflexForm();
		
		$this->conf['userId']      = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], 
			'flickr_nsid'
		);
		$this->conf['displayType'] = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], 
			'display_type'
		);
		$this->conf['imgSize']     = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], 
			'display_imgsize'
		);
		$this->conf['tags']        = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], 
			'tags'
		);
		$this->conf['renderList']  = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], 
			'render_list'
		);
		$this->conf['numImages']   = t3lib_div::intInRange(
			$this->pi_getFFvalue(
				$this->cObj->data['pi_flexform'], 
				'display_numitems'
			),
			1,
			20,
			1
		);
	}
}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/pi1/class.tx_timtabflickrrss_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/pi1/class.tx_timtabflickrrss_pi1.php']);
}

?>