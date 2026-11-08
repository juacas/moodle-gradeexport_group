<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace gradeexport_group;

defined('MOODLE_INTERNAL') || die();

/** Event observers for grade changes. */
class observer {
    /** Update configured groups when a user's grade changes. */
    public static function user_graded(\core\event\user_graded $event): void {
        $grade = $event->get_grade();
        rule_manager::update_user(
            (int)$event->courseid,
            (int)$event->other['itemid'],
            (int)$event->relateduserid,
            $grade->finalgrade
        );
    }
}
