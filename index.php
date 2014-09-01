<?php

require('../../config.php');
require('../hub/top/sites/siteslib.php');
require_once($CFG->libdir.'/tablelib.php');

define('LINKCHECKER_DIR', 'local/linkchecker');

$limitnum = optional_param('limitnum', 100, PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/linkchecker/'));
$PAGE->requires->jquery();
//$PAGE->requires->yui_module('moodle-local-linkchecker', 'M.local_linkchecker.init');
//$PAGE->requires->js(new moodle_url('/local/linkchecker/js/linkchecker.js'));

$isadmin = ismoodlesiteadmin();
if (!$isadmin) {
    redirect('http://moodle.net/sites'); //shoo
}
$chkurl = optional_param('url', null, PARAM_URL);
$siteid = optional_param('id', null, PARAM_URL);
if ($chkurl !== null) {
    $hdrs = get_headers($chkurl.'/lib/womenslib.php',1);
    error_log((print_r($hdrs,true)));
    echo htmlspecialchars($hdrs[0]); // ' Mod:'. $hdrs[3]); //http code and modified.
    die();
}

$PAGE->navbar->add('Registered sites', new moodle_url('/local/linkchecker/'));
$PAGE->set_title(get_string('registeredmoodlesites_moodlenet', 'local_hub'));
$PAGE->set_heading(get_string('registeredmoodlesites', 'local_hub'));


echo $OUTPUT->header();

$totrecs = $DB->count_records('hub_site_directory');

$limitfrom = optional_param('limitfrom', rand(1, $totrecs), PARAM_INT);

list($where, $params) = local_hub_stats_get_confirmed_sql();

$failedrecs = $DB->get_records_sql('Select id, url, unreachable, score, fingerprint, errormsg '
        . 'from {hub_site_directory} r WHERE NOT('. $where. ')' , $params, $limitfrom, $limitnum);

//$passedrecs = $DB->get_records_sql('Select id, url, unreachable, score, fingerprint, errormsg '
//        . 'from {hub_site_directory} r WHERE '. $where, $params, $limitfrom, $limitnum);

// Outputs table
    $table = new html_table();
//    $table->attributes['class'] = 'collection';

    $table->head = array(
                "id",
                'url',
                'unreachable',
                'score',
                'fingerprint OR cron-linkchecker:errormsg',
                'Now checking for womens liberty..'
            );
    $table->colclasses = array();
    
    foreach ($failedrecs as $rec) {
        
        $cell = new html_table_cell('Checking..');
        $cell->attributes = array('id' => $rec->id, 'class' => 'manualcheck', 'url' => $rec->url);

        $row = array($rec->id, $rec->url, $rec->unreachable, $rec->score, (strlen($rec->errormsg)>0)?$rec->errormsg:'fingerprint:'.$rec->fingerprint, $cell);
        $table->data[] = $row;
    }
    $htmltable = html_writer::table($table);

    echo $htmltable ;
    
$jsscr = <<<js
<script>
    function linkchecker() {
            $(".manualcheck").each(function(index, element){
                element.innerHTML = 'Checking, sent to server, awaiting response..';
                $.get('index.php',
                    { url: this.getAttribute('url') },
                    function(responseText) {
                    console.log();
                    console.log(responseText);
                    element.innerHTML = responseText;
                    if (responseText.indexOf("200 OK") >= 0) {
                        element.innerHTML = '<span style="color:#ff0000">' + responseText + '</span>';
                    }
                });
            });
    }
    linkchecker();
</script>
js;

echo $jsscr; // bloody yui

echo $OUTPUT->footer();
