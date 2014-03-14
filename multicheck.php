#!/usr/bin/php
<?php

define('CLI_SCRIPT', true);

if (isset($_SERVER['REQUEST_METHOD'])) { echo "This script cannot be called from a browser<BR>\n"; exit; }
$GLOBALS['lockfile'] = "./.multicheck.lock";
declare(ticks = 1);

// setup signal handlers
pcntl_signal(SIGTERM, "cleanup");
pcntl_signal(SIGINT, "cleanup");

// signal handler function
function cleanup($signal) {
  global $lockfile,$multihandle;
  echo "\nCaught signal ".$signal.", cleaning up\n";
  unlink($lockfile);
  curl_multi_close($multihandle);
  exit;
}

if (file_exists($lockfile)) { 
  $self = basename(__FILE__);
  $pids = `ps axw |grep $self |grep -v grep |awk '{print \$1}'`;
  $pids = trim($pids);
  if (!empty($pids)) {
    $mypid = posix_getpid();
    $pidarr = explode("\n", $pids);
    foreach ($pidarr as $pid) {
      if ($pid == $mypid) { continue; }
      print "Another instance is already running, killing pid $pid\n";
      exec("kill -9 $pid");
    }
    touch($lockfile);
  }
}else { 
  touch($lockfile) or die("Unable to create $lockfile\n");
} 

$overallstarttime = microtime(true);
require_once("../../config.php");

error_reporting(E_ALL);
ini_set('display_errors','On');

echo "\n\nStarting...\n";

$GLOBALS['sitebuffer'] = Array();
$GLOBALS['sitesrunning'] = Array();
$GLOBALS['sitebufferlimit'] = 30;
$GLOBALS['maximumunreachable'] = 20;
$GLOBALS['timelinkchecked'] = time();//-86400;
//$GLOBALS['tablename'] = "registry";
//$GLOBALS['siteselectorsql'] = "SELECT `id`,`unreachable`,`sitename`,`url`,`public`, `timeunreachable`, `score`, `errormsg`, `moodlerelease`, `serverstring`, `override` FROM `".$CFG->prefix.$tablename."` WHERE `unreachable`<=%d AND `override`=0 AND `timelinkchecked`<=%d AND `id`<%d ORDER BY `id` DESC LIMIT %d";
$GLOBALS['tablename'] = "hub_site_directory";

$GLOBALS['siteselectorsql'] = "SELECT `id`,`unreachable`,`name`,`url`,`privacy`, `timeunreachable`, `score`, `errormsg`, `moodlerelease`, `serverstring`, `override` FROM `".$CFG->prefix.$tablename."` WHERE `unreachable`<=%d AND `override`=0 AND `timelinkchecked`<=%d AND `id`<%d ORDER BY `id` DESC LIMIT %d";
$GLOBALS['sitessofar'] = null;
$GLOBALS['totalsites'] = $DB->count_records_select($tablename, "`unreachable`<=$maximumunreachable AND `override`=0 AND `timelinkchecked`<=$timelinkchecked"); 
$GLOBALS['maxcurltimeout'] = 40;
$GLOBALS['maxconnectiontimeout'] = 40;

$maxsitecurls = 20;
$maxredirects = 5;
$sitecurlsrunning = 0;
$GLOBALS['multihandle'] = curl_multi_init();
$curledsofar = 0;

echo "$totalsites To Process\n";

$outcome = fill_site_buffer();
if ($outcome===false) die('WHOOAAA no sites');

$sitespassed = 0;
$sitesfailed = 0;
$siteserrored = 0;

for(;;) {

    while ($sitecurlsrunning<$maxsitecurls && count($sitebuffer)>0) {
        $site = array_shift($sitebuffer);
        $handle = create_handle($site->url);
        if ($handle===false) {
            update_site($site, -1, ((int)$site->unreachable+1), addslashes("Malformed URL - Didnt attempt curl"));
            writeline($site->id, $site->url, 'F', '-','0','0', "Malformed URL - Didnt attempt curl");
            $siteserrored++;
            continue;
        }
        curl_multi_add_handle($multihandle, $handle);
        $sitesrunning[(string)$handle] = $site;
        $sitecurlsrunning++;
        $curledsofar++;
    }

    if (count($sitebuffer)===0) {
        $filledbuffer = fill_site_buffer();
    } else {
        $filledbuffer = true;
    }

    if ($sitecurlsrunning == 0 && $filledbuffer===false) {
        break;
    }

    curl_multi_select($multihandle);
    while(($mcRes = curl_multi_exec($multihandle, $mcActive)) == CURLM_CALL_MULTI_PERFORM);
    if($mcRes != CURLM_OK) break;
    while($done = curl_multi_info_read($multihandle)) {
        $handle = $done['handle'];
        
        $sitecontent = curl_multi_getcontent($handle);

        $site = $sitesrunning[(string)$handle];
        $site->originalurl = $site->url;
        $site->url = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $info = curl_getinfo($handle);
        $curl_error = new stdClass;
        $curl_error = curl_error($handle);
        if (!empty($curl_error)) {
            update_site($site, -1, ((int)$site->unreachable+1), addslashes(curl_error($handle)));
            writeline($site->id, $site->url, 'F', '-',curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),curl_errno($handle), curl_error($handle));
            $siteserrored++;
        } else {
            $manualredirect = check_for_manual_redirect($sitecontent);
            if ($manualredirect!==false) {
                $oldurl = $site->url;
                $outcome = reinsert_site_into_buffer($site, $manualredirect);
                if ($outcome===false) {
                    update_site($site, '', ((int)$site->unreachable+1), 'Max manual redirects exceeded');
                    writeline($site->id, $oldurl,'', '', $site->manualredirect, '', 'Maximum manual redirects exceeded: '.$site->manualredirect);
                } else {
                    writeline($site->id, $oldurl,'', '', $site->manualredirect, '', 'Manual redirect '.$site->url);
                }
            } else {
                $outcome = link_checker_test_result($site, $handle, $sitecontent);
                if ($outcome) {
                    $sitespassed++;
                } else {
                    $sitesfailed++;
                }
            }
        }
        curl_multi_remove_handle($multihandle, $handle);
        curl_close($handle);
        $sitecurlsrunning--;
        unset($sitesrunning[(string)$handle]);
    }
}
curl_multi_close($multihandle);
unlink($lockfile);
echo "\n\nProcess Complete\nPassed: $sitespassed\tFailed: $sitesfailed\tErrored: $siteserrored";
echo "\nTotal time: ". (microtime(true)-$overallstarttime)."\n\n";
flush();

function update_site(&$site, $score='', $unreachable=0, $errormessage='', $moodlerelease=null, $serverstring=null) {
    global $tablename, $DB;
    $updatedsite = new stdClass;
    $updatedsite->id = $site->id;
    $updatedsite->timelinkchecked = time();
    if (strpos($errormessage, "Connection time-out after") !== false) {
      $unreachable = 0;
      $score = 0;
      $updatedsite->override = 2;
    }
    $updatedsite->unreachable = $unreachable;
    $updatedsite->score = $score;
    $updatedsite->errormsg = $errormessage;

    if (isset($site->redirectto)) {
      $updatedsite->redirectto = $site->redirectto;
    }
    if ($moodlerelease!=null) {
        $updatedsite->moodlerelease = $moodlerelease;
    }
    if ($serverstring!=null) {
        $updatedsite->serverstring = $serverstring;
    }
    if ($unreachable!=0 && $site->timeunreachable==0) {
        $updatedsite->timeunreachable = time();
    } else if ($unreachable==0) {
        $updatedsite->timeunreachable = 0;
    }
    $DB->update_record($tablename, $updatedsite);
    return true;
}

function reinsert_site_into_buffer($site, $newurl) {
    global $maxredirects, $sitebuffer;
    $urlbits = @parse_url($site->url);
    if ($urlbits===false || !is_array($urlbits) || !array_key_exists('host',$urlbits) || !array_key_exists('scheme',$urlbits)) return false;
    if (empty($urlbits['port'])) $urlbits['port'] = "80";
    if (empty($urlbits['path'])) $urlbits['path'] = "/";
    $oldurl = $site->url;
    if (!preg_match('#^http[s]?://#', $newurl)) {
        if (strpos($newurl, '/')!==0) {
            $newurl = str_replace('./', '', $newurl);
            $path = $urlbits['path'];
            $uribits = explode($path, '/');
            array_pop($uribits);
            $path = '/'.join('/', $uribits).'/';
            $newurl = $path.$newurl;
        }
        $newurl = $urlbits['scheme'].'://'.$urlbits['host'].":".$urlbits['port'].$newurl;
    }
    $site->url = $newurl;
    $site->manualredirect++;
    if ($site->manualredirect<$maxredirects) {
        $sitebuffer[] = $site;
        return true;
    } else {
        return false;
    }
}

function check_for_manual_redirect($sitecontent) {
    if (preg_match('#<meta\s+http\-equiv=(\'|")refresh\1\scontent=(\'|")[^\2]+?url=(.*?)\2#si', $sitecontent, $matches)) {
        return $matches[3];
    } else {
        return false;
    }
}

function fill_site_buffer() {
    global $sitebuffer, $sitebufferlimit, $CFG, $DB, $siteselectorsql, $sitessofar, $totalsites, $maximumunreachable, $timelinkchecked;

    static $lastsiteid;
    static $runhasfailed;

    if ($runhasfailed===true) return false;

    if ($lastsiteid==null) $lastsiteid= 1000000000;
    if ($runhasfailed==null) $runhasfailed = false;
    if ($sitessofar==null) $sitessofar = 0;

    #echo "\nFilling Buffer with $sitebufferlimit sites starting from id ".$lastsiteid;

    $sql = sprintf($siteselectorsql, $maximumunreachable, $timelinkchecked, $lastsiteid, $sitebufferlimit);
    $sites = $DB->get_records_sql($sql);    

    if (!is_array($sites) || count($sites)==0) {
        $runhasfailed = true;
        return false;
    }
    $sitessofar += count($sites);
    
    foreach ($sites as $site) {
        $site->manualredirect = 0;
        $sitebuffer[] = $site;
    }

    if ($lastsiteid===$site->id) {
      return false;
    }

    $lastsiteid = $site->id;
    return true;
}

function link_checker_test_result(&$site, $handle, $html) {

    $head = substr($html, 0, curl_getinfo($handle, CURLINFO_HEADER_SIZE));
    $html = substr($html, curl_getinfo($handle, CURLINFO_HEADER_SIZE));

    $head = trim($head);
    $html = trim($html);

    $serverstring = null;
    if (preg_match("/Server: (.*)(\n|\r)/", $head, $smatches)) {
      $serverstring = trim($smatches[1]);
    }

    $headscore = 0;
    if (strlen($head)>10) {
        $rules = array("Set\-Cookie: MoodleSessionTest=",
                    "Set\-Cookie: MoodleSession=",
                    "Set\-Cookie: MOODLEID\_=");
        foreach ($rules as &$header_regex) {
          if (preg_match("/".$header_regex."/", $head)) {
            $headscore += 2;
          }
        }
    }

    $htmlscore = 0;
    $moodlerelease = null;
    if (strlen($html)>40) {
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
                "<div id=\"region-main-wrap\">\n\s+<div id=\"region-main\">\n\s+<div class=\"region-content\">"
                    );
                    
        foreach ($rules as &$body_regex) {
          if (preg_match("/".$body_regex."/", $html)) {
            $htmlscore += 1;
          }
        }
        if (preg_match("/(?:title=\"Moodle )(.{0,20})(?: \((Build: |))(\d+)(?:\)\")/i", $html, $matches)) {
          $moodlerelease = $matches[1]." (".$matches[3].")";
        }
     }
    echo 'Headscore (cookie): '. $headscore;
    echo '\nBodyhtmlscore (regexed on body html): '. $htmlscore;
    $score = $htmlscore+$headscore;

    if ($score >= 5) {   // Success!
      if (curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) != $site->originalurl) {
        $site->redirectto = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
      }
      update_site($site, $score, 0, '', $moodlerelease, $serverstring);
      if ($moodlerelease==null) {
        $moodlerelease = "Unknown";
      }
      writeline($site->id, $site->url, 'P', (string)$htmlscore.'/'.(string)$headscore,curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),'-', '', $moodlerelease);
      return true;
    } else {   // Failure
      update_site($site, $score, ((int)$site->unreachable+1), '', $moodlerelease, $serverstring);
      if ($moodlerelease==null) {
        $moodlerelease = "Unknown";
      }
      writeline($site->id, $site->url, 'F', (string)$htmlscore.'/'.(string)$headscore,curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),'0', 'Failed Check with score '.(string)$score, $moodlerelease);
      return false;
    }
}

function writeline($id, $url, $outcome='F', $score='0', $redirects='0', $errorno='', $errormsg='', $moodlerelease='') {
    static $header;
    static $count;
    if ($header==null) {
        echo "\nC   |ID        | URL                                               | P/F | Score (Head/Body) |Redir |ErNum| Version                 | Error Msg";
        echo "\n-----------------------------------------------------------------------------------------------------------------------------------------------------------------";
        $header = true;
    }
    if ($count==null) {
        $count = 1;
    } else {
        $count++;
    }
    $countstr = (strlen($count)<4)?str_pad($count,4):substr($count,0,4);
    if (trim($outcome=='')) {
        $countstr = '    ';
        $count--;
    }
    $id = (strlen($id)<10)?str_pad($id,10):substr($id,0,10);
    $url = (strlen($url)<50)?str_pad($url,50):substr($url,0,50);
    $outcome = (strlen($outcome)<4)?str_pad($outcome,4):substr($outcome,0,4);
    $score = (strlen($score)<18)?str_pad($score,18):substr($score,0,18);
    $redirects = (strlen($redirects)<4)?str_pad($redirects,5):substr($redirects,0,5);
    $errorno = (strlen($errorno)<4)?str_pad($errorno,4):substr($errorno,0,4);
    $moodlerelease = (strlen($moodlerelease)<24)?str_pad($moodlerelease,24):substr($moodlerelease,0,24);
    $errormsg = (strlen($errormsg)<70)?str_pad($errormsg,70):substr($errormsg,0,70);
    echo "\n$countstr|$id| $url| $outcome| $score| $redirects| $errorno| $moodlerelease| $errormsg";
    flush();
}

function create_handle($url) {
    global $maxredirects, $maxcurltimeout, $maxconnectiontimeout;
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
    curl_setopt ($handle, CURLOPT_MAXREDIRS, $maxredirects);
    curl_setopt ($handle, CURLOPT_COOKIEFILE, '/dev/null');
    curl_setopt ($handle, CURLOPT_AUTOREFERER, true);
    curl_setopt ($handle, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
    curl_setopt ($handle, CURLOPT_HEADER, true);
    curl_setopt ($handle, CURLOPT_SSL_VERIFYPEER, false);
    //1 to check the existence of a common name in the SSL peer certificate. 
    //2 to check the existence of a common name and also verify that it matches the hostname provided.
    //In production environments the value of this option should be kept at 2 (default value).
    //Support for value 1 removed in cURL 7.28.1
    curl_setopt ($handle, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt ($handle, CURLOPT_TIMEOUT, $maxcurltimeout);
    curl_setopt ($handle, CURLOPT_CONNECTTIMEOUT, $maxconnectiontimeout);
//    curl_setopt ($handle, CURLOPT_INTERFACE, "184.172.24.2");
    return $handle;
}

?>
