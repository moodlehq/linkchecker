#!/usr/bin/php
<?php

define('CLI_SCRIPT', true); //requiring moodle config needs this.

require_once('lib.php');

if (isset($_SERVER['REQUEST_METHOD'])) { echo "This script cannot be called from a browser<BR>\n"; exit; }
//allow script to run on different set while earlier scripts wait for timeouts etc in buffer (timelinkchecked is updated upon buffer filling)
$GLOBALS['lockfile'] = "./.multicheck.lock.".rand(1,3);
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

define_globals();

$maxsitecurls = 20;
$sitecurlsrunning = 0;
$GLOBALS['multihandle'] = curl_multi_init();
$curledsofar = 0;

echo "$totalsites To Process\n";

$outcome = fill_site_buffer();
if ($outcome===false) die('WHOOAAA no sites');

$sitespassed = 0;
$sitesfailed = 0;
$siteserrored = 0;

$moodleypage = 'login/index.php'; // This should be accessible and constant over time.

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
                    // frontpages are heavily modified.. in addition, check one $timelongmoodleypgs page
                    if (!preg_match('#'.$moodleypage.'#', $site->url)) {
                        $oldurl = $site->url;

                        $joiner = '/';
                        if (substr(trim($site->url), -1) == '/') {
                            $joiner = '';
                        }
                        $newurl = $site->url . $joiner . $moodleypage;

                        $outcome = reinsert_site_into_buffer($site, $newurl);
                        if ($outcome===false) {
                            update_site($site, '', ((int)$site->unreachable+1), 'Max manual redirects exceeded (moodleypage)');
                            writeline($site->id, $oldurl,'', '', $site->manualredirect, '', 'Maximum manual redirects exceeded : '.$newurl);
                        } else {
                            writeline($site->id, $oldurl,'', '', $site->manualredirect, '', 'Extra page check redirect for '.$site->url);
                        }
                    } else {
                        $sitesfailed++;
                    }
                }
            }
        }
        curl_multi_remove_handle($multihandle, $handle);
        curl_close($handle);
        $sitecurlsrunning--;
        unset($sitesrunning[(string)$handle]);
        echo " [ stat: $curledsofar/$totalsites curls:$sitecurlsrunning/$maxsitecurls buf: ".count($sitebuffer).' ]';
    }
}
curl_multi_close($multihandle);
unlink($lockfile);
echo "\n\nProcess Complete\nPassed: $sitespassed\tFailed: $sitesfailed\tErrored: $siteserrored";
echo "\nTotal time: ". (microtime(true)-$overallstarttime)."\n\n";
flush();

/**
 * Update the site's record in hub_site_directory
 */
function update_site(&$site, $score='', $unreachable=0, $errormessage='', $moodlerelease=null, $serverstring=null, $fingerprint=null) {
    global $DB;
    $updatedsite = new stdClass;
    $updatedsite->id = $site->id;
    $updatedsite->timelinkchecked = time();
    //reset for further checking if error message indicates time out. (not the exact string as this may change due to curl changes in php...)
    // @todo for some reason error number is not used here....
    if (strpos($errormessage, "Connection") !== false || strpos($errormessage, "timed out") !== false  || strpos($errormessage, "milliseconds") !== false ) {
      $unreachable = 0;
      $score = 0;
      $updatedsite->override = 2;
    }
    $updatedsite->unreachable = $unreachable;
    $updatedsite->score = $score;
    $updatedsite->errormsg = $errormessage;
    $updatedsite->fingerprint = $fingerprint;

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

    $DB->update_record(LINKCHECKER_TABLENAME, $updatedsite);
    return true;
}

/**
 * Requeue the site for examination
 * The site may have responded with a redirect or we need to check a page other than the front page
 */
function reinsert_site_into_buffer($site, $newurl) {
    global $sitebuffer;
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
    if ($site->manualredirect < LINKCHECKER_MAXREDIRECTS) {
        $sitebuffer[] = $site;
        return true;
    } else {
        return false;
    }
}

/**
 * Load a subset of sites from hub_site_directory to examine via cURL
 **/
function fill_site_buffer() {
    global $sitebuffer, $CFG, $DB, $siteselectorsql, $sitessofar, $totalsites, $timelinkchecked;

    static $lastsiteid;
    static $runhasfailed;

    if ($runhasfailed===true) return false;

    if ($lastsiteid==null) $lastsiteid= 1000000000;
    if ($runhasfailed==null) $runhasfailed = false;
    if ($sitessofar==null) $sitessofar = 0;

    echo "\nFilling Buffer with " . LINKCHECKER_SITEBUFFERLIMIT . " sites starting from id ".$lastsiteid."\r\n";

    $sql = sprintf($siteselectorsql, LINKCHECKER_MAXIMUMUNREACHABLE, $timelinkchecked, $lastsiteid, LINKCHECKER_SITEBUFFERLIMIT);
    $sites = $DB->get_records_sql($sql);

    if (!is_array($sites) || count($sites)==0) {
        $runhasfailed = true;
        return false;
    }
    $sitessofar += count($sites);

    foreach ($sites as $site) {
        $site->manualredirect = 0;
        $sitebuffer[] = $site;

        // Update timelinkchecked early.
        // This is useful when running some multiple linkchecker processes to go faster when testing fingerprinting.
        $site->timelinkchecked = time();
        $DB->update_record(LINKCHECKER_TABLENAME, $site);
    }

    if ($lastsiteid===$site->id) {
      return false;
    }

    $lastsiteid = $site->id;
    return true;
}

/**
 * Examines the header and html of cURL response to determine whether the response came from a Moodle site
 */
function link_checker_test_result(&$site, $handle, $html) {

    $head = substr($html, 0, curl_getinfo($handle, CURLINFO_HEADER_SIZE));
    $html = substr($html, curl_getinfo($handle, CURLINFO_HEADER_SIZE));

    $head = trim($head);
    $html = trim($html);

    $serverstring = null;
    if (preg_match("/Server: (.*)(\n|\r)/", $head, $smatches)) {
      $serverstring = trim($smatches[1]);
    }
    $fingerprint = ''; //reflects $rules set matching.
    $headscore = 0;
    if (strlen($head)>10) {
        $headfingerprint = get_head_fingerprint($head);
        $headscore = 2 * substr_count($headfingerprint, '1'); // 2 points for each match.
        $fingerprint .= $headfingerprint;
    }
    $fingerprint .= '/';
    $htmlscore = 0;
    $moodlerelease = null;
    if (strlen($html)>40) {
        $htmlfingerprint = get_html_fingerprint($html);
        $htmlscore = substr_count($htmlfingerprint, '1');
        $fingerprint .= $htmlfingerprint;

        if (preg_match("/(?:title=\"Moodle )(.{0,20})(?: \((Build: |))(\d+)(?:\)\")/i", $html, $matches)) {
          $moodlerelease = $matches[1]." (".$matches[3].")";
        }
     }

    $score = $htmlscore+$headscore;

    if ($score >= 5) {   // Success!
      if (curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) != $site->originalurl) {
        $site->redirectto = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
      }
      update_site($site, $score, 0, '', $moodlerelease, $serverstring, $fingerprint);
      if ($moodlerelease==null) {
        $moodlerelease = "Unknown";
      }
      writeline($site->id, $site->url, 'P', (string)$htmlscore.'/'.(string)$headscore, (string)$fingerprint, curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),'-', '', $moodlerelease);
      return true;
    } else {   // Failure, but we did reach the site!
      update_site($site, $score, 0, '', $moodlerelease, $serverstring, $fingerprint);
      if ($moodlerelease==null) {
        $moodlerelease = "Unknown";
      }
      writeline($site->id, $site->url, 'F', (string)$htmlscore.'/'.(string)$headscore, (string)$fingerprint, curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),'0', 'Failed Check with score '.(string)$score, $moodlerelease);
      return false;
    }
}

/**
 * Formats the supplied paramters into a pipe separated string and echoes it out.
 **/
function writeline($id, $url, $outcome='F', $score='0', $fingerprint='', $redirects='0', $errorno='', $errormsg='', $moodlerelease='') {
    static $header;
    static $count;
    if ($header==null) {
        $hdstr = "\nC   |ID        | URL                                               | P/F | Score (Body/Head) |          Fingerprint          | Redir |ErNum                                          | Version                 | Error Msg";
        echo $hdstr;
        echo "\n".str_repeat('-', strlen($hdstr));
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
    //don't pad this.
    $moodlerelease = (strlen($moodlerelease)<24)?str_pad($moodlerelease,24):substr($moodlerelease,0,24);
//    $errormsg = (strlen($errormsg)<70)?str_pad($errormsg,70):substr($errormsg,0,70);
    echo "\n$countstr|$id| $url| $outcome| $score| $fingerprint| $redirects| $errorno| $moodlerelease| $errormsg";
    flush();
}

?>
