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
 * File:        class.tx_timtabflickrrss_magpierss.php - parse an RSS or Atom 
 * 				feed, return as a simple object
 * Author:      Kellan Elliott-McCrea <kellan@protest.net>
 * Version:		0.6a
 * License:		GPL
 * 
 */


class tx_timtabflickrrss_magpierss {
	var $parser;
	
	var $current_item	= array();	// item currently being parsed
    var $items			= array();	// collection of parsed items
	var $channel		= array();	// hash of channel fields
	var $textinput		= array();
	var $image			= array();
	var $feed_type;
	var $feed_version;

	// parser variables
	var $stack				= array(); // parser stack
	var $inchannel			= false;
	var $initem 			= false;
	var $incontent			= false; // if in Atom <content mode="xml"> field 
	var $intextinput		= false;
	var $inimage 			= false;
	var $current_field		= '';
	var $current_namespace	= false;
	
	var $ERROR = '';
	
	var $_CONTENT_CONSTRUCTS = array('content', 'summary', 'info', 'title', 'tagline', 'copyright');

	/**
	 * Constructor, sets up XML parser,parses source, and populates object..
	 * 
	 * @param	string		RSS to be parsed
	 */
	function tx_timtabflickrrss_magpierss($source) {
		
		# if PHP xml isn't compiled in, die
		#
		if (!function_exists('xml_parser_create')) {
			$this->error( 'Failed to load PHP\'s XML Extension. ' . 
						  'http://www.php.net/manual/en/ref.xml.php',
						   E_USER_ERROR );
		}
		
		$parser = @xml_parser_create();
		
		if (!is_resource($parser))
		{
			$this->error( 'Failed to create an instance of PHP\'s XML parser. ' .
						  'http://www.php.net/manual/en/ref.xml.php',
						  E_USER_ERROR );
		}

		
		$this->parser = $parser;
		
		# pass in parser, and a reference to this object
		# setup handlers
		#
		xml_set_object( $this->parser, $this );
		xml_set_element_handler($this->parser, 
				'feed_start_element', 'feed_end_element' );
						
		xml_set_character_data_handler( $this->parser, 'feed_cdata' ); 
	
		$status = xml_parse( $this->parser, $source );
		
		if (! $status ) {
			$errorcode = xml_get_error_code( $this->parser );
			if ( $errorcode != XML_ERROR_NONE ) {
				$xml_error = xml_error_string( $errorcode );
				$error_line = xml_get_current_line_number($this->parser);
				$error_col = xml_get_current_column_number($this->parser);
				$errormsg = $xml_error.' at line '.$error_line.', column '.$error_col;

				$this->error( $errormsg );
			}
		}
		
		xml_parser_free( $this->parser );

		$this->normalize();
	}
	
	
	function feed_start_element($p, $element, &$attrs) {
		$el = $element = strtolower($element);
		$attrs = array_change_key_case($attrs, CASE_LOWER);
		
		// check for a namespace, and split if found
		$ns	= false;
		if ( strpos( $element, ':' ) ) {
			list($ns, $el) = split( ':', $element, 2); 
		}
		if ( $ns and $ns != 'rdf' ) {
			$this->current_namespace = $ns;
		}
			
		# if feed type isn't set, then this is first element of feed
		# identify feed from root element
		#
		if (!isset($this->feed_type) ) {
			if ( $el == 'rdf' ) {
				$this->feed_type = RSS;
				$this->feed_version = '1.0';
			}
			elseif ( $el == 'rss' ) {
				$this->feed_type = RSS;
				$this->feed_version = $attrs['version'];
			}
			elseif ( $el == 'feed' ) {
				$this->feed_type = ATOM;
				$this->feed_version = $attrs['version'];
				$this->inchannel = true;
			}
			return;
		}
	
		if ( $el == 'channel' ) 
		{
			$this->inchannel = true;
		}
		elseif ($el == 'item' or $el == 'entry' ) 
		{
			$this->initem = true;
			if ( isset($attrs['rdf:about']) ) {
				$this->current_item['about'] = $attrs['rdf:about'];	
			}
		}
		
		// if we're in the default namespace of an RSS feed,
		//  record textinput or image fields
		elseif ( 
			$this->feed_type == RSS and 
			$this->current_namespace == '' and 
			$el == 'textinput' ) 
		{
			$this->intextinput = true;
		}
		
		elseif (
			$this->feed_type == RSS and 
			$this->current_namespace == '' and 
			$el == 'image' ) 
		{
			$this->inimage = true;
		}
		
		# handle atom content constructs
		elseif ( $this->feed_type == ATOM and in_array($el, $this->_CONTENT_CONSTRUCTS) )
		{
			// avoid clashing w/ RSS mod_content
			if ($el == 'content' ) {
				$el = 'atom_content';
			}
			
			$this->incontent = $el;
			
			
		}
		
		// if inside an Atom content construct (e.g. content or summary) field treat tags as text
		elseif ($this->feed_type == ATOM and $this->incontent ) 
		{
			// if tags are inlined, then flatten
			$attrs_str = join(' ', 
					array_map('map_attrs', 
					array_keys($attrs), 
					array_values($attrs) ) );
			
			$this->append_content( '<'.$element.' '.$attrs_str.'>'  );
					
			array_unshift( $this->stack, $el );
		}
		
		// Atom support many links per containging element.
		// Magpie treats link elements of type rel='alternate'
		// as being equivalent to RSS's simple link element.
		//
		elseif ($this->feed_type == ATOM and $el == 'link' ) 
		{
			if ( isset($attrs['rel']) and $attrs['rel'] == 'alternate' ) 
			{
				$link_el = 'link';
			}
			else {
				$link_el = 'link_' . $attrs['rel'];
			}
			
			$this->append($link_el, $attrs['href']);
		}
		// set stack[0] to current element
		else {
			array_unshift($this->stack, $el);
		}
	}
	
	function feed_cdata ($p, $text) {
		
		if ($this->feed_type == ATOM and $this->incontent) 
		{
			$this->append_content( $text );
		}
		else {
			$current_el = join('_', array_reverse($this->stack));
			$this->append($current_el, $text);
		}
	}
	
	function feed_end_element ($p, $el) {
		$el = strtolower($el);
		
		if ( $el == 'item' or $el == 'entry' ) 
		{
			$this->items[] = $this->current_item;
			$this->current_item = array();
			$this->initem = false;
		}
		elseif ($this->feed_type == RSS and $this->current_namespace == '' and $el == 'textinput' ) 
		{
			$this->intextinput = false;
		}
		elseif ($this->feed_type == RSS and $this->current_namespace == '' and $el == 'image' ) 
		{
			$this->inimage = false;
		}
		elseif ($this->feed_type == ATOM and in_array($el, $this->_CONTENT_CONSTRUCTS) )
		{	
			$this->incontent = false;
		}
		elseif ($el == 'channel' or $el == 'feed' ) 
		{
			$this->inchannel = false;
		}
		elseif ($this->feed_type == ATOM and $this->incontent  ) {
			// balance tags properly
			// note:  i don't think this is actually neccessary
			if ( $this->stack[0] == $el ) 
			{
				$this->append_content('</'.$el.'>');
			}
			else {
				$this->append_content('<'.$el.' />');
			}

			array_shift( $this->stack );
		}
		else {
			array_shift( $this->stack );
		}
		
		$this->current_namespace = false;
	}
	
	function concat (&$str1, $str2='') {
		if (!isset($str1) ) {
			$str1='';
		}
		$str1 .= $str2;
	}
	
	function append_content($text) {
		if ( $this->initem ) {
			$this->concat( $this->current_item[ $this->incontent ], $text );
		}
		elseif ( $this->inchannel ) {
			$this->concat( $this->channel[ $this->incontent ], $text );
		}
	}
	
	/**
	 * smart append - field and namespace aware
	 */
	function append($el, $text) {
		if (!$el) {
			return;
		}
		if ( $this->current_namespace ) 
		{
			if ( $this->initem ) {
				$this->concat(
					$this->current_item[ $this->current_namespace ][ $el ], $text);
			}
			elseif ($this->inchannel) {
				$this->concat(
					$this->channel[ $this->current_namespace][ $el ], $text );
			}
			elseif ($this->intextinput) {
				$this->concat(
					$this->textinput[ $this->current_namespace][ $el ], $text );
			}
			elseif ($this->inimage) {
				$this->concat(
					$this->image[ $this->current_namespace ][ $el ], $text );
			}
		}
		else {
			if ( $this->initem ) {
				$this->concat(
					$this->current_item[ $el ], $text);
			}
			elseif ($this->intextinput) {
				$this->concat(
					$this->textinput[ $el ], $text );
			}
			elseif ($this->inimage) {
				$this->concat(
					$this->image[ $el ], $text );
			}
			elseif ($this->inchannel) {
				$this->concat(
					$this->channel[ $el ], $text );
			}
			
		}
	}
	
	function normalize () {
		// if atom populate rss fields
		if ( $this->is_atom() ) {
			$this->channel['descripton'] = $this->channel['tagline'];
			for ( $i = 0; $i < count($this->items); $i++) {
				$item = $this->items[$i];
				if ( isset($item['summary']) )
					$item['description'] = $item['summary'];
				if ( isset($item['atom_content']))
					$item['content']['encoded'] = $item['atom_content'];
				
				$this->items[$i] = $item;
			}		
		}
		elseif ( $this->is_rss() ) {
			$this->channel['tagline'] = $this->channel['description'];
			for ( $i = 0; $i < count($this->items); $i++) {
				$item = $this->items[$i];
				if ( isset($item['description']))
					$item['summary'] = $item['description'];
				if ( isset($item['content']['encoded'] ) )
					$item['atom_content'] = $item['content']['encoded'];
			
				$this->items[$i] = $item;
			}
		}
	}
	
	function is_rss () {
		if ( $this->feed_type == RSS ) {
			return $this->feed_version;	
		}
		else {
			return false;
		}
	}
	
	function is_atom() {
		if ( $this->feed_type == ATOM ) {
			return $this->feed_version;
		}
		else {
			return false;
		}
	}

	function map_attrs($k, $v) {
		return $k.'="'.$v.'"';
	}

	function error ($errormsg, $lvl=E_USER_WARNING) {
		// append PHP's error message if track_errors enabled
		if ( $php_errormsg ) { 
			$errormsg .= ' ('.$php_errormsg.')';
		}
		$this->ERROR = $errormsg;
		if ( MAGPIE_DEBUG ) {
			trigger_error( $errormsg, $lvl);		
		}
		else {
			error_log( $errormsg, 0);
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_magpierss.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/timtab_flickrrss/class.tx_timtabflickrrss_magpierss.php']);
}

?>