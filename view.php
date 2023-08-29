<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Displays the AU's of a course and their progress
 * @copyright  2023 Megan Bohland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


use mod_cmi5launch\local\progress;
use mod_cmi5launch\local\course;
use mod_cmi5launch\local\cmi5_connectors;
use mod_cmi5launch\local\au_helpers;
use mod_cmi5launch\local\session_helpers;

require_once("../../config.php");
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require('header.php');

// Bring in functions and classes.
$progress = new progress;
$aushelpers = new au_helpers;
$connectors = new cmi5_connectors;
$sessionhelpers = new session_helpers;

// Functions from other classes.
$saveaus = $aushelpers->get_cmi5launch_save_aus();
$createaus = $aushelpers->get_cmi5launch_create_aus();
$getaus = $aushelpers->get_cmi5launch_retrieve_aus_from_db();
$getregistration = $connectors->cmi5launch_get_registration_with_post();
$getregistrationinfo = $connectors->cmi5launch_get_registration_with_get();
$getprogress = $progress->get_cmi5launch_retrieve_statements();
$updatesession = $sessionhelpers->cmi5launch_get_update_session();

global $cmi5launch, $USER, $mod;

// Trigger module viewed event.
$event = \mod_cmi5launch\event\course_module_viewed::create(array(
    'objectid' => $cmi5launch->id,
    'context' => $context,
));
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('cmi5launch', $cmi5launch);
$event->add_record_snapshot('course_modules', $cm);
$event->trigger();

$PAGE->set_url('/mod/cmi5launch/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($cmi5launch->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$PAGE->requires->jquery();

// Output starts here.
echo $OUTPUT->header();

// Reload cmi5 course instance.
$record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));

if ($cmi5launch->intro) {
    // Conditions to show the intro can change to look for own settings or whatever.
    echo $OUTPUT->box(
        format_module_intro('cmi5launch', $cmi5launch, $cm->id),
        'generalbox mod_introbox',
        'cmi5launchintro'
    );
}

// TODO: Put all the php inserted data as parameters on the functions and put the functions in a separate JS file.
?>

    <script>
      
        function key_test(registration) {
        
            //Onclick calls this
            if (event.keyCode === 13 || event.keyCode === 32) {

                mod_cmi5launch_launchexperience(registration);
            }
        }

        // Function to run when the experience is launched (on click).
        function mod_cmi5launch_launchexperience(registrationInfo) {

            // Set the form paramters.
            $('#AU_view').val(registrationInfo);

            // Post it.
            $('#launchform').submit();

            //Add some new content.
            if (!$('#cmi5launch_status').length) {
                var message = "<? echo get_string('cmi5launch_progress', 'cmi5launch'); ?>";
                $('#region-main .card-body').append('\
                <div id="cmi5launch_status"> \
                    <span id="cmi5launch_completioncheck"></span> \
                    <p id="cmi5launch_attemptprogress">' + message + '</p> \
                    <p id="cmi5launch_exit"> \
                        <a href="complete.php?id=<?php echo $id ?>&n=<?php echo $n ?>" title="Return to course"> \
                            Return to course \
                        </a> \
                    </p> \
                </div>\
            ');
            }
            $('#cmi5launch_completioncheck').load('completion_check.php?id=<?php echo $id ?>&n=<?php echo $n ?>');
        }

        // TODO: there may be a better way to check completion. Out of scope for current project.
        //MB - Someone elses todo, may be worth looking into
    
        $(document).ready(function() {
            setInterval(function() {
                $('#cmi5launch_completioncheck').load('completion_check.php?id=<?php echo $id ?>&n=<?php echo $n ?>');
            }, 30000); // TODO: make this interval a configuration setting.
        });
    </script>
<?php

// Check if a course record exists for this user yet.
$exists = $DB->record_exists('cmi5launch_course', ['courseid'  => $record->courseid, 'userid'  => $USER->id]);

// If it does not exist, create it.
if ($exists == false) {

    $userscourse = new course($record);

    $userscourse->userid = $USER->id;

    // Build url to pass as returnUrl.
    $returnurl = $CFG->wwwroot .'/mod/cmi5launch/view.php'. '?id=' .$cm->id;
    $userscourse->returnurl = $returnurl;

    // Assign new record a registration id.
    $registrationid = $getregistration($record->courseid, $cmi5launch->id);
    $userscourse->registrationid = $registrationid;

    // Retrieve AU ids for this user/course.
    $aus = json_decode($record->aus);
    $auids = $saveaus($createaus($aus));
    $userscourse->aus = (json_encode($auids));
    // Save new record to DB.
    $DB->insert_record('cmi5launch_course', $userscourse);

} else {

    // Then we have a record, so we need to retrieve it.
    $userscourse = $DB->get_record('cmi5launch_course', ['courseid'  => $record->courseid, 'userid'  => $USER->id]);

    // Retrieve registration id.
    $registrationid = $userscourse->registrationid;

    // Retrieve AU ids.
    $auids = (json_decode($userscourse->aus) );
}

// Array to hold info for table population.
$tabledata = array();

// We need id to get progress.
$cmid = $cmi5launch->id;

// Create table to display on page.
$table = new html_table();
$table->id = 'cmi5launch_autable';
$table->caption = get_string('AUtableheader', 'cmi5launch');
$table->head = array(
    get_string('cmi5launchviewAUname', 'cmi5launch'),
    get_string('cmi5launchviewstatus', 'cmi5launch'),
    get_string('cmi5launchviewgradeheader', 'cmi5launch'),
    get_string('cmi5launchviewregistrationheader', 'cmi5launch'),
);

// TODO MB.
// Return to for grades.
// CMI5_update_grades($cmi5launch, 0).

// Cycle through AU IDs.
foreach ($auids as $key => $auid) {

    $au = $getaus($auid);

    // Verify object is an au object.
    if (!is_a($au, 'mod_cmi5launch\local\au', false)) {

        $reason = "Excepted AU, found ";
        var_dump($au);
        throw new moodle_exception($reason, 'cmi5launch', '', $warnings[$reason]);
    }

    // Retrieve AU's lmsID.
    $aulmsid = $au->lmsid;

    // Query CMI5 player for updated registration info.
    $registrationinfofromcmi5 = $getregistrationinfo($registrationid, $cmi5launch->id);
    // Take only info about AUs out of registrationinfofromcmi5.
    $ausfromcmi5 = array_chunk($registrationinfofromcmi5["metadata"]["moveOn"]["children"], 1, true);

    // TODO now we can get the AU's satisifed FROM the CMI5 player.
    // TODO (for that matter couldn't we make it, notattempetd, satisifed, not satisfied??).
    foreach ($ausfromcmi5 as $key => $auinfo) {

        // Array to hold scores for AU.
        $sessionscores = array();

        if ($auinfo[$key]["lmsId"] == $aulmsid) {

            // Grab it's 'satisfied' info.
            $ausatisfied = $auinfo[$key]["satisfied"];
        }
    }

    // If the 'sessions' in this AU are null we know this hasn't even been attempted.
    if ($au->sessions == null ) {

        $austatus = "Not attempted";

    } else {

        // Retrieve AUs moveon specification.
        $aumoveon = $au->moveon;

        // If it's been attempted but no moveon value.
        if ($aumoveon == "NotApplicable") {
            $austatus = "viewed";
        } else { // IF it DOES have a moveon value.

            // If satisifed is returned true.
            if ($ausatisfied == "true") {

                $austatus = "Satisfied";
                // Also update AU.
                $au->satisfied = "true";
            } else {

                // If not, its in progress.
                $austatus = "In Progress";
                // Also update AU.
                $au->satisfied = "false";
            }
        };
        // Ensure sessions are up to date.
        // Retrieve session ids.
        $sessionids = json_decode($au->sessions);

        // Iterate through each session by id.
        foreach ($sessionids as $key => $sessionid) {

            // Retrieve new info (if any) from CMI5 player on session.
            $session = $updatesession($sessionid, $cmi5launch->id);

            // Get progress from LRS.
            $session = $getprogress($registrationid, $cmi5launch->id, $session);

            // Add score to array for AU.
            $sessionscores[] = $session->score;

            // Update session in DB.
            $DB->update_record('cmi5launch_sessions', $session);
        }

         // Save the session scores to AU, it is ok to overwrite.
         $au->scores = json_encode($sessionscores);
    };

        // Create array of info to place in table.
        $auinfo = array();

        // Assign au name, progress, and index.
        $auinfo[] = $au->title;
        $auinfo[] = ($austatus);

        // Ok, now we need to retrieve the sessions and find the average score.
        $grade = 0;

    if ($au->moveon == "CompletedOrPassed" || "Passed") {

        // Currently it takes the highest grade out of sessions for grade.
        // Later this can be changed by linking it to plugin options.
        // However, since CMI5 player does not count any sessions after the first for scoring, by averaging we are adding unnessary.
        // 0', and artificailly lowering the grade.
        // Also, should we query for 'passed' or 'completed'? statements here?
        // Or can we have the cmi5player update our AU's moveon to 'passed' or 'completed'?

        if (!$sessionscores == null) {
            // If the grade is empty, we need to pass a null or NA.
            $grade = max($sessionscores);
            $au->grade = $grade;
            if ($grade == 0) {
                $auinfo[] = ("Passed");
            } else {
                $auinfo[] = ($grade);;
            }

        } else {
            $auinfo[] = ("Not Applicable");
        }
    } else {

        if (!$sessionscores == null) {
            // If the grade is empty, we need to pass a null or NA.
            $grade = max($sessionscores);
            $au->grade = $grade;
            $auinfo[] = ($grade);

        } else {
            $auinfo[] = ("Not Attempted");
        }
    }
        $auindex = $au->auindex;

        // AU id for next page (to be loaded).
        $infoForNextPage = $auid;

        // Assign au link to auviews.
        $auinfo[] = "<a tabindex=\"0\" id='cmi5relaunch_attempt'
            onkeyup=\"key_test('" . $infoForNextPage . "')\"
            onclick=\"mod_cmi5launch_launchexperience('" . $infoForNextPage . "')\" style='cursor: pointer;'>"
            . get_string('cmi5launchviewlaunchlink', 'cmi5launch') . "</a>";

        // Add to be fed to table.
        $tabledata[] = $auinfo;

        // Update the au in DB.
        $DB->update_record("cmi5launch_aus", $au);
}

// Lastly, update our course table.
$updated = $DB->update_record("cmi5launch_course", $userscourse);

// This feeds the table.
$table->data = $tabledata;

echo html_writer::table($table);

// Add a form to be posted based on the attempt selected.
?>
    <form id="launchform" action="AUview.php" method="get">
        <input id="AU_view" name="AU_view" type="hidden" value="default">
        <input id="AU_view_id" name="AU_view_id" type="hidden" value="default">
        <input id="id" name="id" type="hidden" value="<?php echo $id ?>">
        <input id="n" name="n" type="hidden" value="<?php echo $n ?>">
    </form>
<?php

echo $OUTPUT->footer();
