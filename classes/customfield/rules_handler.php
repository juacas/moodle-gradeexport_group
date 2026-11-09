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

namespace gradeexport_group\customfield;

/**
 * Restrict the course custom-field area that stores automatic group-rule JSON.
 *
 * Only course editors can save the field, and its value is hidden from normal course views.
 *
 * @package    gradeexport_group
 * @copyright  2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_handler extends \core_customfield\handler {
    /**
     * Use the system context for configuring the custom-field definition.
     *
     * @return \context System configuration context.
     */
    public function get_configuration_context(): \context {
        return \context_system::instance();
    }

    /**
     * Point administrators to the plugin's custom-field settings page.
     *
     * @return \moodle_url Configuration page URL.
     */
    public function get_configuration_url(): \moodle_url {
        return new \moodle_url('/admin/settings.php', ['section' => 'gradeexport_group']);
    }

    /**
     * Resolve a course context for rule values, or the system context for configuration.
     *
     * @param int $instanceid Course ID for a stored field value, or zero for configuration.
     * @return \context Context associated with this custom-field operation.
     */
    public function get_instance_context(int $instanceid = 0): \context {
        return $instanceid ? \context_course::instance($instanceid) : \context_system::instance();
    }

    /**
     * Allow custom-field configuration only to site administrators.
     *
     * @return bool Whether the current user can configure the field definition.
     */
    public function can_configure(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Allow course editors to manage this field's rule data for their course.
     *
     * @param \core_customfield\field_controller $field Field being edited.
     * @param int $instanceid Course ID owning the field data.
     * @return bool Whether the user can edit this course's rule configuration.
     */
    public function can_edit(\core_customfield\field_controller $field, int $instanceid = 0): bool {
        return $instanceid > 0 && has_capability('moodle/course:manageactivities', $this->get_instance_context($instanceid));
    }

    /**
     * Keep the serialized rule field private from standard course field displays.
     *
     * @param \core_customfield\field_controller $field Field whose value is requested.
     * @param int $instanceid Course ID owning the field data.
     * @return bool Whether the field value can be viewed.
     */
    public function can_view(\core_customfield\field_controller $field, int $instanceid): bool {
        return false;
    }

    /**
     * Load course field values through the handler API when available, with a Moodle 4.5 API fallback.
     *
     * @param array $fields Field controllers requested from the custom-field API.
     * @param int $instanceid Course ID whose rule values are loaded.
     * @param bool $adddefaults Whether missing values should include defaults.
     * @return array Field data indexed by field ID.
     */
    public function get_instance_fields_data(array $fields, int $instanceid, bool $adddefaults = true): array {
        if (method_exists(\core_customfield\handler::class, __FUNCTION__)) {
            return parent::get_instance_fields_data($fields, $instanceid, $adddefaults);
        }
        return \core_customfield\api::get_instance_fields_data($fields, $instanceid, $adddefaults);
    }
}
