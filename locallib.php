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
 * Private page module utility functions
 *
 * @package export_grade
 * @copyright  2023 Juan Pablo de Castro <juanpablo.decastro@uva.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/grade/report/grader/lib.php');

class grade_report_listing extends grade_report_grader {
    /**
     * Constructor to override the calculation of grade_tree (avoid removing collapsed categories).
     */
    public function __construct($courseid, $gpr, $context, $page=null, $sortitemid='lastname') {
        global $CFG;
        parent::__construct($courseid, $gpr, $context, $page);

        // Don't collapse categories.
        $this->collapsed =  ['aggregatesonly' => [], 'gradesonly' => []];

        if (empty($CFG->enableoutcomes)) {
            $nooutcomes = false;
        } else {
            $nooutcomes = get_user_preferences('grade_report_shownooutcomes');
        }

        // if user report preference set or site report setting set use it, otherwise use course or site setting
        $switch = $this->get_pref('aggregationposition');
        if ($switch == '') {
            $switch = grade_get_setting($this->courseid, 'aggregationposition', $CFG->grade_aggregationposition);
        }
        // Grab the grade_tree for this course
        $this->gtree = new grade_tree($this->courseid, true, $switch, $this->collapsed, $nooutcomes);
        $this->sortitemid = $sortitemid;    
    }
    
    /**
     * Gets the gradetree object.
     */
    public function get_gradetree() {
        return $this->gtree;
    }
    public function get_item_names() {
        $items = [];
        // Get grade category names.
        $categories = $this->get_category_names();
        foreach ($this->get_gradeitems() as $key => $item) {
            if ($item->itemtype == 'course') {
                $items[$key] = $item->get_name();
            } else if ($item->itemtype == 'category') {
                $items[$key] = get_string('total') . ' ' . $categories[$item->iteminstance];
            } else {
                $items[$key] = $item->get_name();
            }
        }
        return $items;
    }
    public function get_category_names() {
        $categories = [];
        array_walk_recursive($this->gtree->top_element, function($item, $key) use (&$categories) {
            if ($item instanceof grade_category) {
                $categories[$item->id] = $item->get_name();
            }
        });
        return $categories;
    }
    public function get_gradeitems() {
        $items = $this->gtree->get_items();
        $allgradeitems = array_filter($items, function ($item) {
            return $item->gradetype != GRADE_TYPE_NONE;
        });
        return $allgradeitems;
    }
     public function get_gradeitem($itemid) {
        return $this->gtree->get_item($itemid);
    }
    public function get_grades() {
        return $this->grades;
    }
    public function get_users() {
        return $this->users;
    }
    public function get_students_per_page(): int {
        return PHP_INT_MAX;
    }
}
