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
require_once('classes/rule_manager.php');
require_once('classes/customfield/rules_handler.php');
// End of patch.

$id = required_param('id', PARAM_INT); // Course id.
$editrule = optional_param('editrule', '', PARAM_RAW_TRIMMED);
$deleterule = optional_param('deleterule', '', PARAM_RAW_TRIMMED);

$pageparams = ['id' => $id];
if ($editrule !== '') {
    $pageparams['editrule'] = $editrule;
}
$PAGE->set_url('/grade/export/group/index.php', $pageparams);

if (!$course = $DB->get_record('course', ['id' => $id])) {
    throw new \moodle_exception('invalidcourseid');
}

require_login($course);
$context = context_course::instance($id);

require_capability('moodle/grade:export', $context);
require_capability('gradeexport/group:view', $context);

$rules = \gradeexport_group\rule_manager::get_rules($course->id);
if ($deleterule !== '') {
    require_sesskey();
    if (!\gradeexport_group\rule_manager::delete_rule($course->id, $deleterule)) {
        throw new \invalid_parameter_exception('Unknown grade export rule.');
    }
    redirect(new moodle_url('/grade/export/group/index.php', ['id' => $id]));
}
if ($editrule !== '' && !array_key_exists($editrule, $rules)) {
    throw new \invalid_parameter_exception('Unknown grade export rule.');
}

$actionbar = new \core_grades\output\export_action_bar($context, null, 'group');
print_grade_page_head($COURSE->id, 'export', 'group',
    get_string('exportto', 'grades') . ' ' . get_string('pluginname', 'gradeexport_group'),
    false, false, true, null, null, null, $actionbar);
export_verify_grades($COURSE->id);

if (!empty($CFG->gradepublishing)) {
    $CFG->gradepublishing = has_capability('gradeexport/group:publish', $context);
}

$actionurl = new moodle_url('/grade/export/group/index.php', ['id' => $course->id]);
if ($editrule !== '') {
    $actionurl->param('editrule', $editrule);
}
$formoptions = (object)['course' => $course, 'context' => $context, 'id' => $course->id,
    'editrule' => $editrule, 'rule' => $editrule !== '' ? $rules[$editrule] : null];

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
    $oldrulekey = trim((string)($data->editrule ?? ''));
    if ($oldrulekey !== '' && !array_key_exists($oldrulekey, $rules)) {
        throw new \invalid_parameter_exception('Unknown grade export rule.');
    }
    $oldrule = $oldrulekey !== '' ? $rules[$oldrulekey] : null;
    // Use grader report as base to get the grades of the students.
    $groupid = $data->group;
    $currentgroup = groups_get_group($groupid);
    $sourcegroupid = (int)$groupid;
    $groupname = $data->groupname;
    $grader = new group_export_grader($course->id, $currentgroup, $context);
    $itemid = $data->item;
    $status = $data->status;
    // Scheduled rules maintain memberships incrementally and must never clear the group.
    $cleangroup = empty($data->autoupdate) && !empty($data->cleangroup);
  
    $groupid = groups_get_group_by_name($course->id, $groupname);
    if (!$groupid) {
        // Create a new group.
        $groupid = groups_create_group((object) ['courseid' => $course->id,
                                                'name' => $groupname,
                                                'description' => $data->groupdescription,
                                                'visibility' => 3,
                                                ]);
    }

    if (!empty($data->autoupdate) && $oldrulekey === '') {
        $rulekey = implode(':', [(int)$groupid, $sourcegroupid, (int)$itemid, $status]);
        if (isset($rules[$rulekey])) {
            $oldrulekey = $rulekey;
            $oldrule = $rules[$rulekey];
        }
    }

    if (!empty($data->autoupdate)) {
        $newrule = [
            'groupid' => (int)$groupid,
            'groupname' => $groupname,
            'sourcegroupid' => $sourcegroupid,
            'itemid' => (int)$itemid,
            'status' => $status,
        ];
        if ($oldrulekey !== '') {
            \gradeexport_group\rule_manager::save_rules($course->id, $newrule, $oldrulekey);
        } else {
            \gradeexport_group\rule_manager::save_rule($course->id, $newrule);
        }
    } else if ($oldrulekey !== '') {
        \gradeexport_group\rule_manager::delete_rule($course->id, $oldrulekey);
    }


    // Get the students that meet the grading criteria: failed, approved, absent.
    $grader->load_users();
    $grader->load_final_grades();
    $grades = $grader->get_grades();
    $users = $grader->get_users();
    $added = \gradeexport_group\rule_manager::apply_rule_operation(
        $course->id,
        [
            'groupid' => (int)$groupid,
            'groupname' => $groupname,
            'sourcegroupid' => $sourcegroupid,
            'itemid' => (int)$itemid,
            'status' => $status,
            'cleangroup' => $cleangroup,
        ],
        $grades ?? [],
        $users ?? [],
        $oldrule,
        $rules,
        $oldrulekey !== '' ? $oldrulekey : null,
        $cleangroup,
        !empty($data->autoupdate)
    );
    // Print a message with the number of students added to the group.
    echo $OUTPUT->notification(get_string('addedtogroup', 'gradeexport_group',
        (object) [
            'groupname' => $groupname,
            'added' => $added,
            'url' => (new moodle_url('/group/members.php', ['group' => $groupid, 'id' => $course->id]))->out(),
            ]));
}

$rules = \gradeexport_group\rule_manager::get_rules($course->id);
if ($rules) {
    echo $OUTPUT->heading(get_string('rulesheading', 'gradeexport_group'), 3);
    if ($editrule !== '') {
        echo $OUTPUT->notification(get_string('editingrule', 'gradeexport_group') . ' ' .
            html_writer::link(new moodle_url('/grade/export/group/index.php', ['id' => $id]),
                get_string('addrule', 'gradeexport_group')), 'info');
    } else {
        echo html_writer::div(html_writer::link(new moodle_url('/grade/export/group/index.php', ['id' => $id]),
            get_string('addrule', 'gradeexport_group')), 'mb-3');
    }
    $ruletable = new html_table();
    $ruletable->head = [
        get_string('group'),
        get_string('source', 'gradeexport_group'),
        get_string('gradeitem', 'gradeexport_group'),
        get_string('status', 'gradeexport_group'),
        get_string('actions'),
    ];
    $grader = new group_export_grader($course->id, '0', $context);
    $itemnames = $grader->get_item_names();
    $groupcounts = [];
    $getmembercount = static function($group) use (&$groupcounts, $DB): int {
        $groupid = (int)$group->id;
        if (!array_key_exists($groupid, $groupcounts)) {
            $groupcounts[$groupid] = $DB->count_records('groups_members', ['groupid' => $groupid]);
        }
        return $groupcounts[$groupid];
    };
    foreach ($rules as $rulekey => $rule) {
        $targetgroup = groups_get_group((int)$rule['groupid']);
        $sourcegroupid = (int)$rule['sourcegroupid'];
        $sourcegroup = $sourcegroupid ? groups_get_group($sourcegroupid) : null;
        $source = $sourcegroupid ? ($sourcegroup ? $sourcegroup->name : '') : get_string('allparticipants');
        $targetlabel = $targetgroup
            ? format_string($targetgroup->name) . ' (' . $getmembercount($targetgroup) . ')'
            : get_string('missinggroup', 'gradeexport_group');
        if ($targetgroup) {
            $targetlabel = html_writer::link(new moodle_url('/group/members.php', [
                'group' => $targetgroup->id,
                'id' => $course->id,
            ]), $targetlabel);
        }
        $sourcelabel = $sourcegroup
            ? format_string($sourcegroup->name) . ' (' . $getmembercount($sourcegroup) . ')'
            : ($source !== '' ? format_string($source) : get_string('missinggroup', 'gradeexport_group'));
        if ($sourcegroup) {
            $sourcelabel = html_writer::link(new moodle_url('/group/members.php', [
                'group' => $sourcegroup->id,
                'id' => $course->id,
            ]), $sourcelabel);
        }
        $editurl = new moodle_url('/grade/export/group/index.php', ['id' => $id, 'editrule' => $rulekey]);
        $deleteurl = new moodle_url('/grade/export/group/index.php', [
            'id' => $id,
            'deleterule' => $rulekey,
            'sesskey' => sesskey(),
        ]);
        $actions = html_writer::link($editurl, get_string('edit')) . ' | ' .
            html_writer::link($deleteurl, get_string('delete'));
        $ruletable->data[] = [
            $targetlabel,
            $sourcelabel,
            $itemnames[$rule['itemid']] ?? get_string('missingitem', 'gradeexport_group'),
            get_string($rule['status'], 'gradeexport_group'),
            $actions,
        ];
    }
    echo html_writer::table($ruletable);
}

$mform->display();

echo $OUTPUT->footer();

