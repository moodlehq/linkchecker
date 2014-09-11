<?php

require('../../config.php');
require('../hub/top/sites/siteslib.php');
require_once($CFG->libdir.'/tablelib.php');

define('LINKCHECKER_DIR', 'local/linkchecker');

$limitnum = optional_param('limitnum', 400, PARAM_INT); //for now a fixed 400 out of 183000 for 95% (-/+5%) confidence.

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/linkchecker/'));
$PAGE->requires->jquery();

$isadmin = ismoodlesiteadmin();
if (!$isadmin) {
    redirect('http://moodle.net/sites'); //shoo
}
$chkurl = optional_param('url', null, PARAM_URL);
$siteid = optional_param('siteid', null, PARAM_INT);

$totrecs = $DB->count_records('hub_site_directory');

$img = optional_param('img', null, PARAM_URL);
if (!is_null($siteid) && !is_null($img)) {
    $im = getcoverageimg($totrecs, $siteid, true); //get an overlay transparent png for indication.
    header('content-type: image/png');
//    header("Content-Length: " . filesize($name));
    imagepng($im);
    die();
}

if ($chkurl !== null) {
    list($hdrs, $redircnt) = reachaUrl($chkurl.'/lib/womenslib.php');
    if ($hdrs == false) {
        //return some error info.
        $err = error_get_last();
        $result = $err['message'];
    } else {
        //also can check login/forgot_password.php ..for some stuff...
        // anyway the point is humans check and feedback. Devs filter that feedback and transform into rules.. barring AI ofcourse.
        $result = htmlspecialchars($hdrs[0]); // ' Mod:'. $hdrs[3]); //http code and modified.
    }
//    $result = '200 OK'; //test.
    echo json_encode(array('result'=> 'redirections:'. $redircnt. ' final response:'. $result, 'siteid' => $siteid, 'imgsrc' => $CFG->wwwroot.'/local/linkchecker/index.php?siteid='.$siteid.'&img=1'));
    die();
}

$PAGE->navbar->add('Registered sites', new moodle_url('/local/linkchecker/'));
$PAGE->set_title(get_string('registeredmoodlesites_moodlenet', 'local_hub'));
$PAGE->set_heading(get_string('registeredmoodlesites', 'local_hub'));


echo $OUTPUT->header();

$limitfrom = optional_param('limitfrom', rand(1, $totrecs-$limitnum), PARAM_INT);

list($where, $params) = local_hub_stats_get_confirmed_sql();

$allfailedrecids = $DB->get_fieldset_sql('Select id from {hub_site_directory} r WHERE NOT('. $where. ')' , $params); //get all failed site ids.

$randomrecordids = array();

if (is_null($siteid)) {
    $id=0;
    while (count($randomrecordids)<$limitnum) {
        while(!in_array($id=rand(0, $totrecs-1), $randomrecordids) && in_array($id,$allfailedrecids)) { //get unique random list of ids.
            $randomrecordids[] = $id;
            break;
        }
    }
} else {
    $randomrecordids[] = $siteid;
}

list($in_sql, $params) = $DB->get_in_or_equal($randomrecordids, SQL_PARAMS_NAMED, 'r', true);
$im = getcoverageimg($totrecs, $randomrecordids);
$failedrecs = $DB->get_records_sql('Select id, url, countrycode, privacy, unreachable, score, fingerprint, errormsg '
        . 'from {hub_site_directory} r WHERE id '.$in_sql , $params);

// Outputs table
    $table = new html_table();
//    $table->attributes['class'] = 'collection';

    $table->head = array(
                "id",
                'url',
                "CC",
                'unreachable',
                'score',
                'privacy',
                'Previous fingerprint OR cron-linkchecker:errormsg',
                'Now checking for womens liberty..'
            );
    $table->colclasses = array();
    
    foreach ($failedrecs as $rec) {
        
        $cell = new html_table_cell('Checking..');
        $cell->attributes = array('siteid' => $rec->id, 'class' => 'manualcheck', 'url' => $rec->url);

        $trackerparams = array (
            'pid'=>'10020', 'issuetype'=>'3', //tracker.moodle.org : moodle community sites project id.
            'description' => 'see linkcheck test page @ '.$CFG->wwwroot.'/local/linkchecker/index.php?siteid='.$rec->id,
            'components' => '12633', //tracker.moodle.org : moodle community sites project's 'moodle.net' component id.
            'summary' => 'linkchecker found to have missed moodle site (id '.$rec->id.')',
            'security' => '10030' //, 'schemeId' => '10000'  //set atleast 'could be a security issue' as site privacy settings can be changing/changed on moodle.net
        );
        $scorefield = (!in_array($siteid, $allfailedrecids)) ?'(moodley)':'';
        $row = array($rec->id,
                    '<a href="'.$rec->url.'" target="_blank"> Browse it </a>|'
                        .'<a href="./?siteid='.$rec->id.'" target="_blank" > Linkcheck it </a>|'
                        .'<a href="http://tracker.moodle.org/secure/CreateIssueDetails!init.jspa?'.  http_build_query($trackerparams).'" target="_blank" > Report it </a>'
                        ,'<a href="/sites?country='.$rec->countrycode.'" target="_blank" >'.$rec->countrycode.'</a>'
                        , $rec->unreachable, $rec->score. $scorefield, $rec->privacy, (strlen($rec->errormsg)>0)?$rec->errormsg:'fingerprint:'.$rec->fingerprint, $cell);
        $table->data[] = $row;
    }
    $htmltable = html_writer::table($table);

    list($sql, $params) = local_hub_stats_get_confirmed_sql();
    $sql = "SELECT count(*) as onlinesitescount FROM {hub_site_directory} r WHERE ".$sql;
    $totsitesonline = $DB->get_record_sql($sql, $params);
    
    echo '<span class="totrec">Total sites: '. $totrecs. '</span> | <span class="online">Total online &amp; moodley: '.$totsitesonline->onlinesitescount .'</span> | <span class="limitnum">Offline sites loaded in table: '. $limitnum. ' </span> | ';
    echo '<span class="checked">Checked: <span class="chkcnt">0</span></span> | <span class="notfail">not failed: <span class="notfailcnt">0</span></span>  | <span class="fails">Desired Fails: <span class="failcnt">0</span></span> | <span class="percentage">linkchecking fraction (desiredfails/checked): <span class="perc" style="color:#C00;"></span></span>';

    echo '<br/><br/>Test coverage of unmoodley sites:<div style="width:100%"><img class="samplingfailedrecsdistribution" style="border: 1px solid grey;width:800px; height:20px;" src="data:image/png;base64,';
    ob_start();
    imagepng($im);
    $im = ob_get_contents();
    ob_end_clean();
    echo base64_encode($im);
    echo '"/><br/>Flags for unmoodley sites detected online:<div class="samplingresult" style="width:100%;" ></div></div>';

    echo '<br/>';

    echo '<br/><span class="confidence">Default sample size 400 is for a 95% (+/-5%) confidence test.  Adjustable in url by appending "?limitnum=xxx". Please remember to adjust your linkchecking percentage after human checking and raise moodley sites from here to developers.</span>';

    echo $htmltable ;
//    $nonmoodlecnt = $totrecs-$totsitesonline->onlinesitescount;

$jsscr = <<<js
<script>
    function linkchecker() {
            var chkcnt;
            var failcnt;
            var notfailcnt;
            var percentage;
            var confidence;
            var Z = 1.96; //for 95% confidence in a std distribution (about Random 400 samples for over 183k sites will be good)
            $(".manualcheck").each(function(index, element){
                element.innerHTML = 'Checking, sent to server, awaiting response..';
                $.get('index.php',
                    { url: this.getAttribute('url'),
                      siteid: this.getAttribute('siteid')
                    },
                    function(responseText) {
                        responseText = jQuery.parseJSON(responseText);
                        console.log(responseText);
                        element.innerHTML = responseText.result;
                        chkcnt = $(".chkcnt").html();
                        chkcnt++;
                        $(".chkcnt").html(chkcnt);
                        if (responseText.result.indexOf("200 OK") >= 0 || responseText.result.indexOf("303 See Other") >= 0) {
                            element.innerHTML = '<span style="color:#ff0000">' + responseText.result + ' (Human verification preferred.)</span>';
                            notfailcnt = $(".notfailcnt").html();
                            notfailcnt++;
                            $(".notfailcnt").html(notfailcnt);
                            var samplingrs = $(".samplingresult");
                            $(".samplingresult").append('<img style="border: 1px solid grey;width:800px; height:20px;float: left; position: absolute;" src="'+ responseText.imgsrc + '" />');
                        } else {
                            failcnt = $(".failcnt").html();
                            failcnt++;
                            $(".failcnt").html(failcnt);
                            console.log("desired fail count:"+failcnt);
                            percentage = failcnt / chkcnt;
                            console.log("linkchecker percentage(%):"+percentage);
                            $(".perc").html(percentage);
                        }
                    }
                );
            });
    }
    linkchecker();
</script>
js;

echo $jsscr; // bloody yui

echo $OUTPUT->footer();

function getcoverageimg($totrecs, $randomrecordids, $highlightrecs=false) {
    core_php_time_limit::raise(300);
    $width = 18000; $height = 20; $padding = 5;
    $column_width = $width / $totrecs ;
    $im        = imagecreate($width,$height);
    imagesavealpha($im, true);
    //setting completely transparent color
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    $gray      = imagecolorallocate ($im,0x4c,0x4c,0x4c);
    $gray_lite = imagecolorallocate ($im,0x7e,0x7e,0x7e);
    $gray_dark = imagecolorallocate ($im,0x05,0x05,0x05);
    $white     = imagecolorallocate ($im,0xff,0xff,0xff);
    $red     = imagecolorallocate ($im,0xee,0x10,0x10);

    $maxv = 1;
    if (is_array($randomrecordids)) {
        imagefilledrectangle($im,0,0,$width,$height,$white);
        //left in some column ht code (c&p)
        for($i=0;$i<$totrecs;$i++)
        {
            $column_height = ($height * ((int) in_array($i, $randomrecordids)));
            $x1 = $i*$column_width;
            $y1 = $height-$column_height;
            $x2 = (($i+1)*$column_width)-$padding;
            $y2 = $height;
            imagefilledrectangle($im,$x1,$y1,$x2,$y2,$highlightrecs?$red:$gray);
        }
    } else if (is_int ($randomrecordids)) { //highlight - transparent fill
        imagefilledrectangle($im,0,0,$width,$height,$transparent);
        $column_height = $height;
        $x1 = $randomrecordids*$column_width;
        $y1 = $height-$column_height;
        $x2 = (($randomrecordids+1)*$column_width)-$padding;
        $y2 = $height;
        imagefilledrectangle($im,$x1,$y1,$x2,$y2,$highlightrecs?$red:$gray);
    }
    return $im;
}

function reachaUrl ($url, $redirectcount=0) {
    stream_context_set_default(array(
        'http' => array(
            'method' => 'HEAD'
        )
    ));
    $headers = get_headers($url, 1);
    if ($headers !== false && isset($headers['Location'])) {
        reachaUrl($headers['Location'], ++$redirectcount);
    }
    return array($headers, $redirectcount);
}