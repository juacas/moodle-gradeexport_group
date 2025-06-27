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
 * Page configuration form
 *
 * @package     export_group
 * @copyright   2023 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace gradeexport_group;

defined('MOODLE_INTERNAL') || die;

class exportgroup_form extends \moodleform {
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form;

        $data = $this->_customdata;
        // Header for source with help.
        $mform->addElement('header', 'source', get_string('source', 'gradeexport_group'));
        $mform->addHelpButton('source', 'source', 'gradeexport_group');

        // Hidden course id.
        $mform->addElement('hidden', 'id', $data->course->id);
        $mform->setType('id', PARAM_INT);
        // Select group.
        $groups = groups_get_all_groups($data->course->id);
        // Add all groups option.
        $groupoptions = ['0' => get_string('allparticipants')];
        foreach ($groups as $group) {
            // Get member count.
            $members = groups_get_members($group->id);
            $groupoptions[$group->id] = $group->name. " ( " . count($members) . " )";
        }
        $mform->addElement('select', 'group', get_string('group'), $groupoptions);
        $mform->addRule('group', get_string('required'), 'required');
        $mform->setDefault('group', array_key_first($groupoptions));
        // Header for conditions with help.
        $mform->addElement('header', 'conditions', get_string('conditions', 'gradeexport_group'));
        $mform->addHelpButton('conditions', 'conditions', 'gradeexport_group');
        // Get Grade item from the course and create a multi-select box.
        // Use grader report as base to get the grades of the students.
        $grader = new group_export_grader($data->course->id, "0", $data->context);
        $items = $grader->get_item_names();

        $mform->addElement('select', 'item', get_string('gradeitem', 'gradeexport_group'), $items);
        $mform->addRule('item', get_string('required'), 'required');
        // Select last element by default.
        $mform->setDefault('item', array_key_last($items));
        // Select for supended, failed+absent, absent, approved students.
        $options = [
            'failed' => get_string('failed', 'gradeexport_group'),
            'failednograde' => get_string('failednograde', 'gradeexport_group'),
            'nograde' => get_string('nograde', 'gradeexport_group'),
            'graded' => get_string('graded', 'gradeexport_group'),
            'approved' => get_string('approved', 'gradeexport_group'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'gradeexport_group'), $options);
        $mform->setDefault('status', 'failed');
        $mform->addRule('status', get_string('required'), 'required');
        // Header for target group with help.
        $mform->addElement('header', 'targetgroup', get_string('targetgroup', 'gradeexport_group'));
        $mform->addHelpButton('targetgroup', 'targetgroup', 'gradeexport_group');
        // Name of the group.
        $mform->addElement('text', 'groupname', get_string('groupname', 'gradeexport_group'));
        $mform->addRule('groupname', get_string('required'), 'required');
        $mform->setType('groupname', PARAM_TEXT);
        $mform->setDefault('groupname', "Group {$items[array_key_last($items)]} - {$options['failed']}");
        // Description of the group.
        $mform->addElement('textarea', 'groupdescription', get_string('groupdescription', 'gradeexport_group'));
        $mform->setType('groupdescription', PARAM_TEXT);
        $mform->setDefault('groupdescription', get_string('gradesgroupdescription', 'gradeexport_group',
            (object)['itemname' => $items[array_key_last($items)],
            'status' => get_string('failed', 'gradeexport_group'),
            ]));

        // Checkbox for cleaning the group with help.
        $mform->addElement('checkbox', 'cleangroup', get_string('cleangroup', 'gradeexport_group'));
        $mform->setDefault('cleangroup', 0);
        $mform->addHelpButton('cleangroup', 'cleangroup', 'gradeexport_group');
        $this->add_action_buttons();
    }
}
