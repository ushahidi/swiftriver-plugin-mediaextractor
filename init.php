<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Init for the Media Extractor Plugin.
 * This plugin extracts links and images from a droplet
 *
 * PHP version 5
 * LICENSE: This source file is subject to GPLv3 license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/gpl.html
 * @author	   Ushahidi Team <team@ushahidi.com> 
 * @package	   Swiftriver - http://github.com/ushahidi/Swiftriver_v2
 * @category   Inits
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license	   http://www.gnu.org/copyleft/gpl.html GNU General Public License v3 (GPLv3) 
 */

class MediaExtractor_Init {
	
	/**
	 * List of known URL shortening services
	 * @var array
	 */
	private $shortener_services = array();
	
	public function __construct()
	{	
		// Load Simple_HTML_DOM
		$path = Kohana::find_file('vendor', 'simple_html_dom/simple_html_dom');
		if (FALSE === $path)
		{
			throw new Kohana_Cache_Exception('Simple_HTML_DOM vendor code not found');
		}

		require_once($path);

		// Hook into drop post processing
		Swiftriver_Event::add('swiftriver.droplet.extract_metadata', array($this, 'parse_media'));
		
		// Load the list of known URL shorteners
		$this->shortener_services = Kohana::$config->load('shorteners')->get('services');
	}

	/**
	 * Event callback for the swiftriver.droplet.extract_metadata event
	 *
	 * @return void
	 */
	public function parse_media()
	{
		try
		{
			// Get the droplet content
			$droplet = & Swiftriver_Event::$data;
			
			Kohana::$log->add(Log::DEBUG, "Media extraction started for drop with id :id", array(':id' => $droplet['id']));
			Kohana::$log->write();
			
			$links = array();
			$images = array();
						
			//Get the urls from the anchor and image tags in the drop 
			if (preg_match_all('/<\s*(a|img)\s*[^>]*(?:(?:href|src)\s*=\s*"([^"]+))"[^>]*>/i', $droplet['droplet_raw'], $tag_matches))
			{
				foreach ($tag_matches[2] as $key => $url)
				{
					if ($tag_matches[1][$key] == 'img')
					{
						$images[] = $url;
					}
					else
					{
						// Only expand URLs from known shortening services
						if ($this->_is_short_url($url))
						{
							$url = $this->full($url);
						}
						$this->parse_link($url, $images, $links);
					}
				}
			}
			
			// Get links that from the html text
			// http://daringfireball.net/2010/07/improved_regex_for_matching_urls
			$pattern = "(?i)\b((?:https?:\/\/|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]"
			    . "{2,4}\/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(("
			    . "[^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:'\".,<>?«»“”‘’]))";
			if (preg_match_all("/".$pattern."/is", strip_tags($droplet['droplet_raw']), $text_matches))
			{
				foreach ($text_matches[0] as $key => $url)
				{
					// Only expand urls from known shortening services
					if ($this->_is_short_url($url))
					{ 
						$url = $this->full($url);
					}
					
					$this->parse_link($url, $images, $links);
				}
			}
						            
			// Save the links
			if ( ! empty($links))
			{
				if ( ! isset($droplet['links']))
				{
					$droplet['links'] = $links;
				}
				else
				{
					$droplet['links'] = array_merge($droplet['links'], $links);
				}
			}
            
            
			// Remove Images we don't need
			$images = Mediaextractor_Filter::dejunk($images);
            
			// Remove dupes
			$images = array_unique($images);
			
			// Get a droplet image and remove tiny images.
			$selected_images = array();
			$droplet_image = NULL;
			$cur_max = 0;
			foreach ($images as $key => $image)
			{
				 list($width, $height) = getimagesize($image);
				
				// We only want images larger than 1000 square pixels
				$area = $width * $height;
				if ($area >= 5000)
				{
					$selected_images[] = $image;
					
					// The drop image is the largest image
					if ($area > $cur_max)
					{
						$cur_max = $area;
						$droplet_image = $image;
					}
				}
			}
            
			// Save the Images
			if ( ! empty($selected_images))
			{
				$droplet['media'] = array();
				foreach ($selected_images as $image)
				{
					$droplet['media'][] = array(
						'url' => $image,
						'type' => 'image',
						'droplet_image' => $image == $droplet_image
					);
				}
			}
			
			$droplet['media_complete'] = TRUE;
		}
		catch (Exception $e)
		{
			// Some kind of error occurred
			Kohana::$log->add(Log::ERROR, Kohana_Exception::text($e));
		}
	}
	
	/**
	*
	* Given a URL, determine if it points to an image or not.
	*
	*/
	private function parse_link($url, & $images_arr, & $links_arr)
	{
		if (preg_match('/\.(jpg|jpeg|png|gif)(?:[?#].*)?$/i', $url))
		{
			// Link to an image
			$images_arr[] = $url;
		}
		else if ($image_service_url = $this->extract_service_image($url))
		{
			$images_arr[] = $image_service_url;
		}
		else
		{
			$links_arr[] = array('url' => $url);
		}
	}

	/**
	 * Given a url, return the photo url from an image service or null
	 * if the url does not point to an image server
	 *
	 * @param   string $url 
	 * @return  mixed
	 */
	private function extract_service_image($url)
	{
		$ret = NULL;
		
		switch (parse_url($url, PHP_URL_HOST))
		{
			case 'yfrog.com':
				$ret = $this->_extract_yfrog($url);
			break;
			case 'plixi.com':
				$ret = $this->_extract_plixi($url);
			break;
			case 'instagr.am':
				$ret = $this->_extract_instagram($url);
			break;
			case 'twitpic.com':
				$ret = $this->_extract_twitpic($url);
			break;
			case 'flic.kr':
				$ret = $this->_extract_flickr($url);
			break;
		}
		
		return $ret;
	}

	private function _extract_yfrog($link)
	{
		return trim($link,'”."').':iphone';
	}

	private function _extract_twitpic($link)
	{
		$linkparts = explode('/',$link);
		return 'http://twitpic.com/show/large/'.$linkparts[3];
	}

	private function _extract_flickr($link)
	{
		$html = file_get_html($link);
		foreach ($html->find('img.photo') as $element)
		{
			return $element->src;
		}
	}

	private function _extract_instagram($link)
	{
		$html = file_get_html($link);
		foreach ($html->find('img.photo') as $element)
		{
			return $element->src;
		}
	}

	private function _extract_plixi($link)
	{
		$html = file_get_html($link);
		foreach ($html->find('img[id=photo]') as $element)
		{
			return $element->src;
		}
	}
	
	/**
	 * Expands a short URL
	 *
	 * @param   string $url Short URL
	 * @return  string $url Full/Expanded URL
	 */
	private function full($url = NULL)
	{
		try
		{
			$headers = get_headers($url,1);
		}
		catch (Exception $e)
		{
			// Some kind of error
			// Abandon and return original url
			Kohana::$log->add(Log::ERROR, Kohana_Exception::text($e));
			return $url;
		}

		if (empty($headers))
		{
			return $url;
		}

		if ( ! isset($headers['Location']))
		{
			return $url;
		}
		$url = $headers['Location'];
		
		// If an Array is returned for redirects
		// Return the last item in the array
		return is_array($url)? end($url) :  $url;
	}
	
	/**
	 * Given a URL, checks it has been shortened
	 *
	 * @param string $url URL to be checked
	 * @return bool
	 */
	private function _is_short_url($url)
	{
		$url_host = parse_url($url, PHP_URL_HOST);
		return in_array($url_host, $this->shortener_services);
	}
}

new MediaExtractor_Init;