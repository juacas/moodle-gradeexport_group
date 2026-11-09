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
 * Navigation callbacks for gradeexport_group.
 *
 * @package    gradeexport_group
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the grade export link to the course Groups menu.
 *
 * @param navigation_node $parentnode Course administration navigation node.
 * @param stdClass $course Course object.
 * @param context_course $context Course context.
 */
function gradeexport_group_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
): void {
    if (!has_all_capabilities(['moodle/grade:export', 'gradeexport/group:view'], $context)) {
        return;
    }

    // Core adds this node when the current user can manage course groups.
    $usersnode = $parentnode->get('users');
    if (!$usersnode) {
        return;
    }
    $groupsnode = $usersnode->get('groups');
    $groupmenu = $usersnode->get('gradeexport_group_groups');
    if (!$groupsnode || $groupmenu) {
        return;
    }

    $url = new moodle_url('/grade/export/group/index.php', ['id' => $course->id]);
    // The participants dropdown reads third-party navigation sections from direct children of "users".
    // Core builds its "Groups" section separately, so add a Moodle navigation section for this action.
    $groupmenu = $usersnode->add(
        get_string('groups'),
        null,
        navigation_node::TYPE_CONTAINER,
        null,
        'gradeexport_group_groups'
    );
    $groupmenu->add(
        get_string('creategroupsfromgrades', 'gradeexport_group'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'gradeexport_group',
        new pix_icon('i/export', '')
    );
}
