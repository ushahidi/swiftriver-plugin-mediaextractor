<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Unit test for the Media Extractor plugin
 *
 * @package    Swiftriver
 * @category   Tests
 * @author     Ushahidi Team
 * @copyright  (c) 2008-2012 Ushahidi Inc <http://www.ushahidi.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License v3 (GPLv3)
 */
class SwiftRiver_MediaExtractor_Test extends Unittest_TestCase {
	
	/**
	 * Override default setUp
	 */
	public function setUp()
	{
		// Get the droplet template
		$this->droplet = Swiftriver_Dropletqueue::get_droplet_template();
		
		// Droplet content with some data
		$this->droplet['id'] = 20120712116;
		$this->droplet['droplet_raw'] = "http://t.co/PZsdJmgF Your grave has been dug Elmahdi. "
		   . "Be seated within it. Fucker. #SudanRevolts";
		
		// Run the metadata extraction event
		Swiftriver_Event::run('swiftriver.droplet.extract_metadata', $this->droplet);
	}
	
	/**
	 * Tests media extraction
	 *
	 * @covers MediaExtractor_Init::parse_media
	 */
	public function test_parse_media()
	{
		// Verify that the event has run
		$this->assertTrue(Swiftriver_Event::has_run('swiftriver.droplet.extract_metadata'),
			'Metadata extraction event has not run');
		
		// Verify that the media array exists
		$links_exist = array_key_exists('links', $this->droplet);
		$this->assertTrue($links_exist);
		
		// Verify that the expanded URL matches the actual one
		$this->assertEquals($this->droplet['links'][0]['url'], 
		    "http://www.sudantribune.com/Umma-leader-supports-negotiated,43239");
	}
		
	/**
	 * Override default tearDown
	 */
	public function tearDown()
	{
		unset($this->droplet);
	}
	
}