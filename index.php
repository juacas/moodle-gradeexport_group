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

use gradeexport_group\exportgroup_form;
use gradeexport_group\group_export_grader;

require_once('../../../config.php');
require_once($CFG->dirroot.'/grade/export/lib.php');
// Patch for classloader missing exportgroup_form and group_export_grader classes (at UVa servers).
require_once('classes/exportgroup_form.php');
require_once('classes/group_export_grader.php');
// End of patch.

$id = required_param('id', PARAM_INT); // Course id.

$PAGE->set_url('/grade/export/group/index.php', ['id' => $id]);

if (!$course = $DB->get_record('course', ['id' => $id])) {
    throw new \moodle_exception('invalidcourseid');
}

require_login($course);
$context = context_course::instance($id);

require_capability('moodle/grade:export', $context);
require_capability('gradeexport/group:view', $context);

$actionbar = new \core_grades\output\export_action_bar($context, null, 'group');
print_grade_page_head($COURSE->id, 'export', 'group',
    get_string('exportto', 'grades') . ' ' . get_string('pluginname', 'gradeexport_group'),
    false, false, true, null, null, null, $actionbar);
export_verify_grades($COURSE->id);

if (!empty($CFG->gradepublishing)) {
    $CFG->gradepublishing = has_capability('gradeexport/group:publish', $context);
}

$actionurl = new moodle_url('/grade/export/group/index.php');
$formoptions = (object)['course' => $course, 'context' => $context, 'id' => $course->id];

// Don't use group modes. User can access all groups and users.

// $groupmode    = groups_get_course_groupmode($course);   // Groups are being used.
// $currentgroup = groups_get_course_group($course, true);
// if (($groupmode == SEPARATEGROUPS) &&
// (!$currentgroup) &&
// (!has_capability('moodle/site:accessallgroups', $context))) {
    
//     echo $OUTPUT->heading(get_string("notingroup"));
//     echo $OUTPUT->footer();
//     die;
// }
// groups_print_course_menu($course, 'index.php?id='.$id);

$mform = new exportgroup_form($actionurl, $formoptions);

echo '<div class="clearer"></div>';
// Check the form data.
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/grade/export/index.php', ['id' => $id]));
} else if ($data = $mform->get_data()) {
    // Use grader report as base to get the grades of the students.
    $groupid = $data->group;
    $currentgroup = groups_get_group($groupid);
    $groupname = $data->groupname;
    $grader = new group_export_grader($course->id, $currentgroup, $context);
    // Get itemname.
    $names = $grader->get_item_names();
    $itemid = $data->item;
    $status = $data->status;
    $itemname = $names[$itemid];
    $statusname = get_string($data->status, 'gradeexport_group');
  
    $groupid = groups_get_group_by_name($course->id, $groupname);
    if ($groupid) {
        // Empty and start over.
        $members = groups_get_members($groupid);
        foreach ($members as $member) {
            groups_remove_member($groupid, $member->id);
        }
    } else {
        // Create a new group.
        $groupid = groups_create_group((object) ['courseid' => $course->id,
                                                'name' => $groupname,
                                                'description' => $data->groupdescription,
                                                'visibility' => 3,
                                                ]);
    }


    // Get the students that meet the grading criteria: failed, approved, absent.
    $grader->load_users();
    $grader->load_final_grades();
    $grades = $grader->get_grades();
    $gradeitem = $grader->get_gradeitem($itemid);
    $added = 0;
    // Add the selected users to the group.
    foreach ($grades ?? [] as $userid => $grade) {
        $value = $grade[$itemid];
        $approved = $value->finalgrade >= $gradeitem->gradepass;
        $absent = $value->finalgrade == false;
        // Depending on the status, add the user to the group.
        switch ($status) {
            case 'failed':
                if (!$approved && !$absent) {
                    groups_add_member($groupid, $userid);
                    $added++;
                }
                break;
            case 'failednograde':
                if (!$approved || $absent) {
                    groups_add_member($groupid, $userid);
                    $added++;
                }
                break;
            case 'nograde':
                if ($absent) {
                    groups_add_member($groupid, $userid);
                    $added++;
                }
                break;
            case 'graded':
                if ($value->finalgrade !== null && $value->finalgrade !== false) {
                    groups_add_member($groupid, $userid);
                    $added++;
                }
                break;
            case 'approved':
                if ($approved) {
                    groups_add_member($groupid, $userid);
                    $added++;
                }
                break;
        }
    }
    // Print a message with the number of students added to the group.
    echo $OUTPUT->notification(get_string('addedtogroup', 'gradeexport_group',
        (object) [
            'groupname' => $groupname,
            'added' => $added,
            'url' => (new moodle_url('/group/members.php', ['group' => $groupid, 'id' => $course->id]))->out(),
            ]));
}

$mform->display();

echo $OUTPUT->footer();

