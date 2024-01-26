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

defined('MOODLE_INTERNAL') || die;

require_once('locallib.php');
require_once($CFG->dirroot.'/grade/lib.php');

class export_group_form extends moodleform {
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form;

        $data = $this->_customdata;
        $mform->addElement('header', 'general', get_string('general', 'form'));
       
        // Hidden course id.
        $mform->addElement('hidden', 'id', $data->course->id);
        $mform->setType('id', PARAM_INT);
        // Get Grade item from the course and create a multi-select box.
        // Use grader report as base to get the grades of the students.
        $grader = new grade_report_listing($data->course->id, "0", $data->context);
        $items = $grader->get_item_names();
       
        $mform->addElement('select', 'item', get_string('gradeitem', 'gradeexport_group'), $items);
        $mform->addRule('item', get_string('required'), 'required');
        // Select last element by default.
        $mform->setDefault('item', array_key_last($items));
        // Select for supended, failed+absent, absent, approved students.
        $options = array(
            'failed' => get_string('failed', 'gradeexport_group'),
            'failednograde' => get_string('failednograde', 'gradeexport_group'),
            'nograde' => get_string('nograde', 'gradeexport_group'),
            'approved' => get_string('approved', 'gradeexport_group'),
        );
        $mform->addElement('select', 'status', get_string('status', 'gradeexport_group'), $options);
        $mform->setDefault('status', 'failed');
        $mform->addRule('status', get_string('required'), 'required');

        $this->add_action_buttons();
    }
}

