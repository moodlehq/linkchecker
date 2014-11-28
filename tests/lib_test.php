<?php
/**
 * Unit tests for the link checker plug
 *
 * @package    local_linkchecker
 * @copyright  2014 Andrew Davis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class local_linkchecker_lib_testcase extends advanced_testcase
{
    public function test_check_for_manual_redirect() {
        // Trailing slash.
        $this->assertEquals('http://example1.com/', check_for_manual_redirect('<meta http-equiv="refresh" content="5; url=http://example1.com/">'));
        // No trailing slash.
        $this->assertEquals('http://example2.com', check_for_manual_redirect('<meta http-equiv="refresh" content="0; url=http://example2.com">'));
        // uppercase URL
        $this->assertEquals('http://example3.com', check_for_manual_redirect('<meta http-equiv="refresh" content="0; URL=http://example3.com">'));
        // Http-equiv has to be before content.
        $this->assertEquals(false, check_for_manual_redirect('<meta content="0; URL=http://example4.com" http-equiv="refresh">'));
        // No space after redirection time.
        $this->assertEquals('http://example5.com', check_for_manual_redirect('<meta http-equiv="refresh" content="0;URL=http://example5.com">'));
    }

    public function test_create_handle() {

        $this->assertEquals(false, create_handle(''));
        $this->assertEquals(false, create_handle(null));

        $handle = create_handle('http://createhandle.com');
        $this->assertEquals('http://createhandle.com', curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));

        // Trailing slash.
        $handle = create_handle('http://createhandle.com/');
        $this->assertEquals('http://createhandle.com/', curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));

        // Subdomain.
        $handle = create_handle('http://sub.createhandle.com');
        $this->assertEquals('http://sub.createhandle.com', curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));

        // A subdirectory.
        $handle = create_handle('http://createhandle.com/dir');
        $this->assertEquals('http://createhandle.com/dir', curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));

        // No "." == invalid.
        $handle = create_handle('http://createhandlecom');
        $this->assertEquals($handle, false);
    }

    public function test_get_head_fingerprint() {
        // A set cookie meta tag from a non-Moodle site.
        $head = '<meta name="Set-Cookie" content="PREF=8634GPYE4S; expires=Wed, 20-Jul-2011 11:00:07 GMT; path=/; domain=.mydomain.com">';
        $this->assertEquals('000', get_head_fingerprint($head));

        // None of these seem to exist in a vanilla 2.9 site.
        /*Set\-Cookie: MoodleSessionTest=",
        "Set\-Cookie: MoodleSession=",
        "Set\-Cookie: MOODLEID\_="*/
    }

    public function test_get_html_fingerprint() {
        $html = '';
        $this->assertEquals('0000000000000000000000000000', get_html_fingerprint($html));

        // The following strings were taken from a vanilla 2.9dev Moodle install (Build: 20141128).
        // The commented out tests are those where I could not find the relevant string on the site's home or login page.
        // This is presumably since the HTML has changed since the test was originally written.
        $html = '<script type="text/javascript" src="http://localhost/moodle/int/master/lib/javascript.php/-1/lib/javascript-static.js"></script>' .
        '<meta name="keywords" content="moodle, int master test" />' .
                    //"var moodle_cfg = \{", // moodle 2 only
                    //"function openpopup\(url,", // moodle 1.x only
                    //"function inserttext\(text\)", // moodle 1.x only
        '<div class="logininfo">You are not logged in. (<a href="http://localhost/moodle/int/master/login/index.php">Log in</a>)</div>' .
                    //"type=\"hidden\" name=\"testcookies\"",
                    //"type=\"hidden\" name=\"sesskey\" value=\"",
                    //"method=\"get\" name=\"changepassword\"",
                    //"lib\/cookies\.js\"><\/script>",
                    //"class=\"headermain\">",
                    //"function getElementsByClassName\(oElm,",
                    //"<div id=\"noscriptchooselang\"",
                    //"src=\"pix\/moodlelogo\.gif\"",
        '<span id="maincontent">' .
                    //"lib\/overlib.js", // moodle 1.x
                    //"src=\"pix\/madewithmoodle", // moodle 1.x
                    //"function popUpProperties\(inobj\)", // BELOW BEGINS NEW RULES
                    //"var moodleConfigFn =",
        '<body  id="page-site-index" class="format-site course path-site safari dir-ltr lang-en yui-skin-sam yui3-skin-sam localhost--moodle-int-master pagelayout-frontpage course-1 context-2 notloggedin has-region-side-pre used-region-side-pre has-region-side-post used-region-side-post layout-option-nonavbar">' .
        '\"comboBase\":\"http:\/\/localhost\/moodle\/int\/master\/theme\/yui_combo.php?\"' .
                    //"M.core_dock.init_genericblock",
        '<script type="text/javascript" src="http://localhost/moodle/int/master/theme/javascript.php?theme=clean&amp;rev=-1&amp;type=footer"></script>' .//"\/theme\/javascript.php",
        '<a href="#skipavailablecourses" class="skip-block">Skip available courses</a>' .
                    //"<span id=\"maincontent\"><\/span><div class=\"box generalbox sitetopic\"><div class=\"no-overflow\">",
        '<body  id="page-site-index" class="format-site course path-site safari dir-ltr lang-en yui-skin-sam yui3-skin-sam localhost--moodle-int-master pagelayout-frontpage course-1 context-2 notloggedin has-region-side-pre used-region-side-pre has-region-side-post used-region-side-post layout-option-nonavbar">' .//"<body id=\"page-site-index\"",
                    //"<div id=\"region-main-wrap\">\n\s+<div id=\"region-main\">\n\s+<div class=\"region-content\">",
        '<body  id="page-login-index" class="format-site  path-login safari dir-ltr lang-en yui-skin-sam yui3-skin-sam localhost--moodle-int-master pagelayout-login course-1 context-1 notloggedin layout-option-langmenu">' .//"<body id=\"page-login-index\"",
         '<div class="rememberpass">';
         $this->assertEquals('11000100000000100001101101011', get_html_fingerprint($html));
    }
}
