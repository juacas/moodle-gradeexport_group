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
 * Strings for component 'gradeexport_group', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   gradeexport_group
 * @copyright 2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['addedtogroup'] = '{$a->added} students added to group <a href="{$a->url}">{$a->groupname}</a>';
$string['eventgradeexported'] = 'Grades exported to group';
$string['pluginname'] = 'Edit group members from grades';
$string['privacy:metadata'] = 'The Group grade export plugin does not store any personal data.';
$string['group:publish'] = 'Publish Group grade export';
$string['group:view'] = 'Use Group grade export';
$string['gradeitem'] = 'Grade item';
$string['failed'] = 'Failed';
$string['failednograde'] = 'Failed or no grade';
$string['nograde'] = 'No grade';
$string['graded'] = 'Graded';
$string['approved'] = 'Passed';
$string['gradesgroupdescription'] = 'Group with students that has a grade in {$a->itemname}: {$a->status}';
$string['status'] = 'Condition of the grades to export to group';
$string['source'] = 'Source group';
$string['source_help'] = 'Select the group of students to copy to target group filtering by the condition.';
$string['conditions'] = 'Conditions';
$string['conditions_help'] = 'Select the condition of the grades to export to target group.';
$string['targetgroup'] = 'Target group';
$string['targetgroup_help'] = 'Select the group into which the students from source group will be copied.';
$string['groupname'] = 'Name of the target group';
$string['groupdescription'] = 'Description of the target group';
$string['cleangroup'] = 'Empty target group before exporting';
$string['cleangroup_help'] = 'If checked, the target group will be emptied before exporting the students from the source group.';