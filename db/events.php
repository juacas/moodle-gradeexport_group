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

/**
 * Subscribe grade-export group rules to grade and source-group membership changes.
 *
 * @package    gradeexport_group
 * @copyright  2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\user_graded',
        'callback' => '\\gradeexport_group\\observer::user_graded',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\core\\event\\group_member_added',
        'callback' => '\\gradeexport_group\\observer::group_member_added',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\core\\event\\group_member_removed',
        'callback' => '\\gradeexport_group\\observer::group_member_removed',
        'priority' => 9999,
    ],
];
