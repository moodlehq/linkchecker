#!/usr/bin/php
<?php
die(); //not active.
if (isset($_SERVER['REQUEST_METHOD'])) { echo "This script cannot be called from a browser<BR>\n"; exit; }

declare(ticks = 1);

// setup signal handlers
pcntl_signal(SIGTERM, "cleanup");
pcntl_signal(SIGINT, "cleanup");
pcntl_signal(SIGUSR1, "cleanup");

$overallstarttime = microtime(true);
require_once("/opt/linkcheck/moodle19/config.php");

$GLOBALS['maxredirects'] = 2;
$GLOBALS['numredirects'] = 0;
$GLOBALS['lockfile'] = "/opt/linkcheck/moodle19/sites/.singlechecklock";
$GLOBALS['errorcount'] = 0;
$GLOBALS['passcount'] = 0;
$GLOBALS['failcount'] = 0;
$GLOBALS['totalsites'] = 0;

// signal handler function
function cleanup($signal) {
  global $lockfile,$errorcount,$passcount,$failcount,$totalsites;
  if ($signal == 10) {
    echo "\n\nTotal Sites: ".$totalsites."\nTotal Passes: ".$passcount."\nTotal Failures: ".$failcount."\nTotal Errors: ".$errorcount."\n\n";
  }else {
    echo "Caught signal ".$signal.", cleaning up\n";
    unlink($lockfile);
    rs_close($rs);
    exit;
  }
}

function curlcheck($url) {
  global $maxredirects, $numredirects;
  $urlArray = parse_url($url);
  if (empty($urlArray['port'])) $urlArray['port'] = "80";
  if (empty($urlArray['path'])) $urlArray['path'] = "/";

  $ch = curl_init();

  curl_setopt ($ch, CURLOPT_URL, $url);
  curl_setopt ($ch, CURLOPT_HTTPHEADER, array('Cache-Control: no-cache', 'Accept: text/plain, text/html', 'Host: '.$urlArray['host'], 'Connection: close'));
  curl_setopt ($ch, CURLOPT_USERAGENT, 'Moodle.org Link Checker (http://moodle.org/sites/)');
  curl_setopt ($ch, CURLOPT_INTERFACE, "184.172.24.2");
  curl_setopt ($ch, CURLOPT_TIMEOUT, 60);
  curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt ($ch, CURLOPT_FAILONERROR, TRUE);
  curl_setopt ($ch, CURLOPT_FRESH_CONNECT, TRUE);
  curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 20);
  curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, TRUE);
  curl_setopt ($ch, CURLOPT_HEADER, TRUE);
  curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 1);

  $header_rules = array("Set-Cookie: MoodleSession=",
			"Set-Cookie: MoodleSessionTest=",
			"Set-Cookie: MOODLEID_=");

  $body_rules = array("lib\/javascript-static.js\"><\/script>",
		"content=\"moodle,",
		"var moodle_cfg = {", // moodle 2 only
		"function openpopup\(url,", // moodle 1.x only
		"function inserttext\(text\)", // moodle 1.x only
		"<div class=\"logininfo\">",
		"type=\"hidden\" name=\"testcookies\"",
		"type=\"hidden\" name=\"sesskey\" value=\"",
		"method=\"get\" name=\"changepassword\"",
		"lib\/cookies.js\"><\/script>",
		"class=\"headermain\">",
		"function getElementsByClassName\(oElm,",
		"<div id=\"noscriptchooselang\"",
		"src=\"pix\/moodlelogo.gif\"",
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

  $score = 0;
  echo "Checking $url ";
  if ($lines = curl_exec ($ch)) {
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($lines, 0, $header_size);
    $body = substr($lines, $header_size);
    //echo "header_size = $header_size\n";
    //echo "headers: $headers\n\n\n";
    //echo "body: $body\n\n\n";
    if (preg_match("/<meta http-equiv=\"refresh\" content=\"(\d+); url=(.*?)\"/i", $body, $refreshmatch)) {
      if ($numredirects >= $maxredirects) {
        $score = "max redirects reached: ".$maxredirects."";
      }else {
        echo "refresh to: $refreshmatch[2]\n";
        $numredirects++;
        if (!preg_match("/htt(p|ps):\/\//i", $refreshmatch[2])) {
          $score = curlcheck($urlArray['scheme']."://".$urlArray['host'].":".$urlArray['port']."".$refreshmatch[2]);
        }else {
          $score = curlcheck($refreshmatch[2]);
        }
        return $score;
      }
    }else {
      //$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      //$eff_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
      foreach ($header_rules as &$header_regex) {
        if (preg_match("/".$header_regex."/", $headers)) {
          $score += 2;
          //echo "matched header rule: ".$header_regex."\n";
        }
      }
      foreach ($body_rules as &$body_regex) {
        if (preg_match("/".$body_regex."/", $body)) {
          $score += 1;
          //echo "matched body rule: ".$body_regex."\n";
        }
      }
      if (preg_match("/(?:title=\"Moodle )(.{0,20})(?: \((Build: |))(\d+)(?:\)\")/ism", $body, $matches)) {
        echo "[".$matches[1]." (".$matches[3].")] ";
      }

    }
    echo "score: $score\n";
  }else { $score = curl_error($ch); echo "ERROR: $score\n"; }
  curl_close ($ch);
  return $score;
}

if ($argc < 2) {
  echo "usage $0 <sql | list of sites>\n";
  exit;
}
  array_shift($argv);
if ($argv[0] == "sql") {
  if (file_exists($lockfile)) {
    $self = basename(__FILE__);
    (string)$pid = `ps axw |grep $self |grep -v grep |awk '{print \$1}'`;
    $pid = trim($pid);
    if (!empty($pid) && is_numeric($pid)) {
      print "Another instance is already running, exiting\n";
      exit;
    }
  }else {
    //if (!touch($lockfile)) die('unable to create lockfile');
    touch($lockfile) or die("Unable to create $lockfile\n");
  }
  //$sqlwhere = "`errormsg` LIKE '%tion time%out%' OR `errormsg` = 'connect() timed out!' AND `override` IN (2,3)";
  $sqlwhere = "`score` <= '0'";
  if (($totalsites = count_records_select("registry", $sqlwhere)) <= 0) {
    echo "\nNo sites to process, try modifying the sql query.\nQuery: $sqlquery\n";
    exit;
  }
  echo "\n".$totalsites." To Process\n\n";
  //if ($rs = get_recordset_sql("SELECT `id`,`unreachable`,`sitename`,`url`,`public`, `timeunreachable`, `score`, `errormsg`, `moodlerelease`, `override` FROM `".$CFG->prefix."registry` WHERE ".$sqlwhere." ORDER BY `override` DESC, `id` DESC")) {
  // select `id`,url,timelinkchecked,score,unreachable,timeunreachable,timeupdated from registry order by timelinkchecked desc limit 20;
  $sqlquery = "SELECT `id`,`unreachable`,`sitename`,`url`,`public`, `timeunreachable`, `score`, `errormsg`, `moodlerelease`, `override` FROM `".$CFG->prefix."registry` WHERE ".$sqlwhere." ORDER BY `timelinkchecked` DESC LIMIT 200";
  if ($rs = get_recordset_sql($sqlquery)) {
    $passcount = 0;
    $failcount = 0;
    $errorcount = 0;
    while ($site = rs_fetch_next_record($rs)) {
//      if ($site->url && $site->unreachable < 4) {
      if ($site->url) {
        $newsite = new stdClass;
        $newsite->id = $site->id;
        $newsite->timelinkchecked = time();
        $score = curlcheck("$site->url");
        if (strlen($score) > 2) {
          $newsite->errormsg = addslashes($score);
          $score = -1;
          $errorcount++;
        }else {
          $newsite->errormsg = "";
        }
        $newsite->score = $score;
        if ($score >= 5) {   // Success!
            //echo "clearing unreachable and timeunreachable for ".$site->url."\n";
            $passcount++;
            $newsite->unreachable = 0;
            $newsite->timeunreachable = 0;
            $newsite->override = 0;
        } else {   // Failed, so increment the unreachable flag
            //echo "incrementing unreachable for ".$site->url."\n";
            if ($score >= 0) { $failcount++; }
            $newsite->unreachable = $site->unreachable + 1;
            if ($site->timeunreachable == 0) {
              //echo "updating timeunreachable for ".$site->url."\n";
              $newsite->timeunreachable = time();
            }
            if (strpos($newsite->errormsg, "Connection time-out after") !== false || strpos($newsite->errormsg, "connect() timed out!") !== false || strpos($newsite->errormsg, "Operation timed out after") !== false) {
              if ($site->override === '0') {
                $newsite->override = 2;
              }else {
                $newsite->override = $site->override + 1;
              }
            }else {
              $newsite->override = 0;
            }
        }
        update_record('registry', $newsite);
      }
    }
    echo "\n\nProcess Complete\nTotal time: ". (microtime(true)-$overallstarttime)."\n";
    echo "Passes: ".$passcount." Failures: ".$failcount." Curl Errors: ".$errorcount."\n\n";
    rs_close($rs);
    unlink($lockfile);
  }
}else {
  foreach ($argv as &$site) {
    $site = trim($site);
    curlcheck($site);
  }
}

?>
