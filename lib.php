<?php

define('LINKCHECKER_SITEBUFFERLIMIT', 30);
define('LINKCHECKER_MAXIMUMUNREACHABLE', 3);
define('LINKCHECKER_TABLENAME', 'hub_site_directory');
define('LINKCHECKER_MAXCURLTIMEOUT', 140);
define('LINKCHECKER_MAXCONNECTIONTIMEOUT', 140);
define('LINKCHECKER_MAXREDIRECTS', 5);

function define_globals() {
    global $CFG, $DB;

    $GLOBALS['sitebuffer'] = Array();
    $GLOBALS['sitesrunning'] = Array();
    $GLOBALS['timelinkchecked'] = time(); // for tests, use eg: time()-(1*60*60) to test fewer, otherwise just check all;
    $GLOBALS['siteselectorsql'] = "SELECT id, unreachable, name, url, privacy, timeunreachable, score, errormsg, moodlerelease, serverstring, override "
            . "FROM ".$CFG->prefix.LINKCHECKER_TABLENAME." WHERE unreachable <= %d AND timelinkchecked <= %d AND id < %d AND url <> 'https://moodle.org' ORDER BY id DESC LIMIT %d";
    //sort sites randomly for more evenly distributed use of curl multi handle buffer - too many sequential wait times hog up the buffer.
    $GLOBALS['sitessofar'] = null;
    $GLOBALS['totalsites'] = $DB->count_records_select(LINKCHECKER_TABLENAME, "unreachable <= " . LINKCHECKER_MAXIMUMUNREACHABLE . " AND timelinkchecked <= {$GLOBALS['timelinkchecked']}");
}

/**
 * Does the cURL response look like a redirection?
 * @return string The URL to redirect to
 */
function check_for_manual_redirect($sitecontent) {
    if (preg_match('#<meta\s+http\-equiv=(\'|")refresh\1\scontent=(\'|")[^\2]+?url=(.*?)\2#si', $sitecontent, $matches)) {
        return $matches[3];
    } else {
        return false;
    }
}

/**
 * Is the supplied URL a bogon IP? Does it resolve to a bogon IP?
 * https://en.wikipedia.org/wiki/Bogon_filtering
 * @return True if the supplied url is a bogon.
 */
function is_bogon($url) {
    $host = parse_url($url, PHP_URL_HOST);
    $records = dns_get_record($host, DNS_A + DNS_AAAA);
    $a_records=[];
    $aaaa_records=[];
    $failure=0;
    if (empty($records)) return 1;
    foreach ($records as $k => $r) {
        switch ($r['type']) {
            case "A":
                $addr = $r['ip'];
                $addr = explode('.', $addr);
                $addr = array_reverse ($addr, true);
                $revaddr = '';
                foreach ($addr as $kk => $v) $revaddr.=$v.'.';
                $a_records[] = $revaddr;
                break;
            case "AAAA":
                $addr = $r['ipv6'];
                $addr = str_replace(':', '', $addr);
                $addr = str_split($addr);
                $addr = array_reverse ($addr, true);
                $revaddr = '';
                foreach ($addr as $kk => $v) $revaddr.=$v.'.';
                $aaaa_records[] = $revaddr;
                break;
            default:
                break;
        }
    }
    foreach ($a_records as $k => $record) {
        $response = dns_get_record($record.'v4.fullbogons.cymru.com', DNS_A);
        if (empty($response)) continue;
        if ($response[0]['ip'] == '127.0.0.2') $failure++;
    }
    foreach ($aaaa_records as $k => $record) {
        $response = dns_get_record($record.'v6.fullbogons.cymru.com', DNS_A);
        if (empty($response)) continue;
        if ($response[0]['ip'] == '127.0.0.2') $failure++;
    }
    return $failure > 0;
}

/**
 * Initializes a cURL session
 * @return returns a cURL handle or false if an error occured
 **/
function create_handle($url) {
    if (trim($url)=='') {
        return false;
    }
    $urlbits = parse_url($url);
    $handle = curl_init();
    curl_setopt ($handle, CURLOPT_URL, $url);
    if (is_array($urlbits) && array_key_exists('host', $urlbits) && array_key_exists('scheme', $urlbits)) {
        if (strpos($urlbits['host'],'.')===false) {
            return false;
        }
        curl_setopt ($handle, CURLOPT_HTTPHEADER, array('Cache-Control: no-cache', 'Accept: text/plain, text/html', 'Host: '.$urlbits['host'], 'Connection: close'));
    } else {
        return false;
    }
    curl_setopt ($handle, CURLOPT_USERAGENT, 'Moodle.org Link Checker (http://moodle.org/sites/)');
    curl_setopt ($handle, CURLOPT_MAXCONNECTS, 1024);
    curl_setopt ($handle, CURLOPT_FRESH_CONNECT, TRUE);
    curl_setopt ($handle, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt ($handle, CURLOPT_FAILONERROR, true);
    curl_setopt ($handle, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt ($handle, CURLOPT_MAXREDIRS, LINKCHECKER_MAXREDIRECTS);
    curl_setopt ($handle, CURLOPT_COOKIEFILE, '/dev/null');
    curl_setopt ($handle, CURLOPT_AUTOREFERER, true);
    curl_setopt ($handle, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
    curl_setopt ($handle, CURLOPT_HEADER, true);
    curl_setopt ($handle, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt ($handle,CURLOPT_ENCODING , "gzip"); // for some curl OPTIONAL speed and perhaps more accomodating
    //1 to check the existence of a common name in the SSL peer certificate.
    //2 to check the existence of a common name and also verify that it matches the hostname provided.
    //In production environments the value of this option should be kept at 2 (default value).
    //Support for value 1 removed in cURL 7.28.1
    curl_setopt ($handle, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt ($handle, CURLOPT_TIMEOUT, LINKCHECKER_MAXCURLTIMEOUT);
    curl_setopt ($handle, CURLOPT_CONNECTTIMEOUT, LINKCHECKER_MAXCONNECTIONTIMEOUT);
    // curl_setopt ($handle, CURLOPT_INTERFACE, "184.172.24.2");
    return $handle;
}

function get_head_fingerprint($head) {
    $fingerprint = '';
    $rules = array("Set\-Cookie: MoodleSessionTest=",
                   "Set\-Cookie: MoodleSession=",
                   "Set\-Cookie: MOODLEID\_=");
    foreach ($rules as &$header_regex) {
        if (preg_match("/".$header_regex."/", $head)) {
            $fingerprint .= '1';
        } else {
            $fingerprint .= '0';
        }
    }
    return $fingerprint;
}

function get_html_fingerprint($html) {
    $fingerprint = '';
    $rules = array("lib\/javascript\-static\.js\"><\/script>",
                    "content=\"moodle,",
                    "var moodle_cfg = \{", // moodle 2 only
                    "function openpopup\(url,", // moodle 1.x only
                    "function inserttext\(text\)", // moodle 1.x only
                    "<div class=\"logininfo\">",
                    "type=\"hidden\" name=\"testcookies\"",
                    "type=\"hidden\" name=\"sesskey\" value=\"",
                    "method=\"get\" name=\"changepassword\"",
                    "lib\/cookies\.js\"><\/script>",
                    "class=\"headermain\">",
                    "function getElementsByClassName\(oElm,",
                    "<div id=\"noscriptchooselang\"",
                    "src=\"pix\/moodlelogo\.gif\"",
                    "<span id=\"maincontent\">",
                    "lib\/overlib.js", // moodle 1.x
                    "src=\"pix\/madewithmoodle", // moodle 1.x
                    "function popUpProperties\(inobj\)", // BELOW BEGINS NEW RULES
                    "var moodleConfigFn =",
                    "<body id=\"page-site-index\" class=",
                    "theme\/yui_combo.php",
                    "M.core_dock.init_genericblock",
                    "\/theme\/javascript.php",
                    "class=\"skip-block\"",
                    "<span id=\"maincontent\"><\/span><div class=\"box generalbox sitetopic\"><div class=\"no-overflow\">",
                    "<body id=\"page-site-index\"",
                    "<div id=\"region-main-wrap\">\n\s+<div id=\"region-main\">\n\s+<div class=\"region-content\">",
                    // some moodles force login and we end up at login page. these are rules specific to moodle login page scoring.
                    "<body id=\"page-login-index\"",
                    "<div class=\"rememberpass\">");

    foreach ($rules as &$body_regex) {
        if (preg_match("/".$body_regex."/", $html)) {
            $fingerprint .= '1';
        } else {
            $fingerprint .= '0';
        }
    }
    return $fingerprint;
}
