<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace gradeexport_group\customfield;

/** Private course custom-field area for automatic group rules. */
class rules_handler extends \core_customfield\handler {
    /** @return \context */
    public function get_configuration_context(): \context {
        return \context_system::instance();
    }

    /** @return \moodle_url */
    public function get_configuration_url(): \moodle_url {
        return new \moodle_url('/admin/settings.php', ['section' => 'gradeexport_group']);
    }

    /** @return \context */
    public function get_instance_context(int $instanceid = 0): \context {
        return $instanceid ? \context_course::instance($instanceid) : \context_system::instance();
    }

    /** @return bool */
    public function can_configure(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

    /** @return bool */
    public function can_edit(\core_customfield\field_controller $field, int $instanceid = 0): bool {
        return $instanceid > 0 && has_capability('moodle/course:manageactivities', $this->get_instance_context($instanceid));
    }

    /** @return bool */
    public function can_view(\core_customfield\field_controller $field, int $instanceid): bool {
        return false;
    }

    /** Moodle 4.5 compatibility wrapper; newer versions expose this on handler. */
    public function get_instance_fields_data(array $fields, int $instanceid, bool $adddefaults = true): array {
        if (method_exists(\core_customfield\handler::class, __FUNCTION__)) {
            return parent::get_instance_fields_data($fields, $instanceid, $adddefaults);
        }
        return \core_customfield\api::get_instance_fields_data($fields, $instanceid, $adddefaults);
    }
}
