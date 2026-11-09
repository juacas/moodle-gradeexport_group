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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace gradeexport_group;

/**
 * Apply automatic grade-to-group rules when grades or source-group memberships change.
 *
 * The callbacks delegate to rule_manager, which limits each update to the affected user and rules.
 *
 * @package    gradeexport_group
 * @copyright  2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Reconcile the user's target groups for rules tied to the grade item that changed.
     *
     * @param \core\event\user_graded $event Grade-change event with the item, course and user IDs.
     */
    public static function user_graded(\core\event\user_graded $event): void {
        $grade = $event->get_grade();
        rule_manager::update_user(
            (int)$event->courseid,
            (int)$event->other['itemid'],
            (int)$event->relateduserid,
            $grade->finalgrade
        );
    }

    /**
     * Apply source-group rules after a user joins a group.
     *
     * @param \core\event\group_member_added $event Group membership event.
     */
    public static function group_member_added(\core\event\group_member_added $event): void {
        rule_manager::update_source_group_member(
            (int)$event->courseid,
            (int)$event->objectid,
            (int)$event->relateduserid
        );
    }

    /**
     * Reconcile targets after a user leaves a source group, retaining memberships justified by other rules.
     *
     * @param \core\event\group_member_removed $event Group membership event.
     */
    public static function group_member_removed(\core\event\group_member_removed $event): void {
        rule_manager::remove_user_from_source_group_targets(
            (int)$event->courseid,
            (int)$event->objectid,
            (int)$event->relateduserid
        );
    }
}
