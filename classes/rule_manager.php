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

use gradeexport_group\customfield\rules_handler;

/**
 * Persist, validate and incrementally apply course rules that export grades to groups.
 *
 * The rule set is stored as versioned JSON in a private course custom field; request-local indexes keep event handling
 * limited to rules for the changed grade item or source group.
 *
 * @package    gradeexport_group
 * @copyright  2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_manager {
    /** Version of the stored JSON document. */
    private const SCHEMA_VERSION = 1;
    /** Custom-field short name. */
    private const FIELD_SHORTNAME = 'automatic_group_rules';
    /** @var array Request-local cache of course rules. */
    private static array $rulecache = [];
    /** @var array Request-local index of rules by course and grade item. */
    private static array $itemrulecache = [];
    /** @var array Re-entry locks for group membership event propagation. */
    private static array $sourceeventlocks = [];

    /**
     * Load a course's automatic export rules from its private custom field, falling back to the legacy format option.
     *
     * @param int $courseid Course whose rules are requested.
     * @return array Rules keyed by their target, source, grade item and condition.
     */
    public static function get_rules(int $courseid): array {
        if (array_key_exists($courseid, self::$rulecache)) {
            return self::$rulecache[$courseid];
        }
        $handler = rules_handler::create();
        $field = self::find_field($handler);
        if ($field) {
            $data = $handler->get_instance_fields_data([(int)$field->get('id') => $field], $courseid, false);
            $controller = $data[(int)$field->get('id')] ?? null;
            if ($controller) {
                $document = json_decode((string)$controller->get('value'), true);
                if (
                        is_array($document) && ($document['version'] ?? null) === self::SCHEMA_VERSION &&
                        is_array($document['rules'] ?? null)
                ) {
                    return self::$rulecache[$courseid] = $document['rules'];
                }
            }
        }
        // Migrate rules created by the first implementation, which stored them in course format options.
        return self::$rulecache[$courseid] = self::read_legacy_rules($courseid);
    }

    /**
     * Retrieve or create the private custom field used to store serialized rule sets.
     *
     * @return \core_customfield\field_controller Rule storage field controller.
     */
    private static function ensure_field(): \core_customfield\field_controller {
        $handler = rules_handler::create();
        $field = self::find_field($handler);
        if ($field) {
            return $field;
        }
        $categoryid = $handler->create_category('Grade export group');
        $categories = $handler->get_categories_with_fields();
        if (method_exists($handler, 'get_category_from_array')) {
            $category = $handler->get_category_from_array($categories, $categoryid, 'gradeexport_group', 'rules', 0);
        } else {
            $category = $categories[$categoryid] ?? null;
        }
        if (!$category) {
            throw new \coding_exception('Could not load the grade export rules custom-field category.');
        }
        $field = \core_customfield\field_controller::create(0, (object)['type' => 'textarea'], $category);
        $handler->save_field_configuration($field, (object)[
            'name' => 'Automatic group rules',
            'shortname' => self::FIELD_SHORTNAME,
            'description' => 'Private versioned JSON maintained by gradeexport_group.',
            'descriptionformat' => FORMAT_PLAIN,
            'configdata' => ['required' => 0, 'uniquevalues' => 0, 'visibility' => 0],
        ]);
        return $field;
    }

    /**
     * Find the rule-storage field by its stable short name without creating plugin configuration.
     *
     * @param rules_handler $handler Course custom-field handler.
     * @return \core_customfield\field_controller|null Existing field, or null before first use.
     */
    private static function find_field(rules_handler $handler): ?\core_customfield\field_controller {
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === self::FIELD_SHORTNAME) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Read rules saved by the earlier implementation in course format options.
     *
     * @param int $courseid Course whose legacy rule data is being migrated.
     * @return array Decoded legacy rules, or an empty list when no valid data exists.
     */
    private static function read_legacy_rules(int $courseid): array {
        global $DB;
        $course = get_course($courseid);
        $record = $DB->get_record('course_format_options', [
            'courseid' => $courseid,
            'format' => $course->format,
            'sectionid' => 0,
            'name' => 'gradeexport_group_rules',
        ]);
        if (!$record || !$record->value) {
            return [];
        }
        $rules = json_decode($record->value, true);
        return is_array($rules) ? $rules : [];
    }

    /**
     * Save one rule, replacing any rule with the same target, source, grade item and condition key.
     *
     * @param int $courseid Course receiving the rule.
     * @param array $rule Rule definition to persist.
     */
    public static function save_rule(int $courseid, array $rule): void {
        self::save_rules($courseid, $rule, null);
    }

    /**
     * Update a course's rule set and reject a new source-to-target edge that introduces a cycle.
     *
     * @param int $courseid Course receiving the updated rule set.
     * @param array $rule Rule definition to save.
     * @param string|null $replacekey Existing rule key to remove when editing a rule.
     */
    public static function save_rules(int $courseid, array $rule, ?string $replacekey): void {
        $rules = self::get_rules($courseid);
        if ($replacekey !== null) {
            unset($rules[$replacekey]);
        }
        $key = implode(':', [(int)$rule['groupid'], (int)$rule['sourcegroupid'], (int)$rule['itemid'], $rule['status']]);
        unset($rules[$key]);
        self::validate_no_new_cycle($rules, (int)$rule['sourcegroupid'], (int)$rule['groupid']);
        $rules[$key] = $rule;
        self::write_rules($courseid, $rules);
        unset(self::$rulecache[$courseid]);
        unset(self::$itemrulecache[$courseid]);
    }

    /**
     * Reject only a new edge that makes a path from the target lead back to its source.
     *
     * @param array $rules Existing course rules after removing any edited rule.
     * @param int $sourcegroupid Source group; zero represents all course participants.
     * @param int $targetgroupid Destination group receiving eligible students.
     */
    private static function validate_no_new_cycle(array $rules, int $sourcegroupid, int $targetgroupid): void {
        // Source 0 means all participants and is not a group-to-group edge.
        if ($sourcegroupid <= 0) {
            return;
        }
        $graph = [];
        foreach ($rules as $existingrule) {
            $sourceid = (int)$existingrule['sourcegroupid'];
            $targetid = (int)$existingrule['groupid'];
            if ($sourceid > 0) {
                $graph[$sourceid][$targetid] = true;
            }
        }
        // An edge that already exists does not introduce a new cycle.
        if (isset($graph[$sourcegroupid][$targetgroupid])) {
            return;
        }
        $pending = [$targetgroupid];
        $visited = [];
        while ($pending) {
            $current = (int)array_pop($pending);
            if ($current === $sourcegroupid) {
                throw new \moodle_exception('rulecycle', 'gradeexport_group');
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach (array_keys($graph[$current] ?? []) as $next) {
                if (!isset($visited[$next])) {
                    $pending[] = (int)$next;
                }
            }
        }
    }

    /**
     * Delete a rule and invalidate the request-local rule indexes for its course.
     *
     * @param int $courseid Course containing the rule.
     * @param string $key Stored rule key.
     * @return bool Whether a rule with that key existed and was removed.
     */
    public static function delete_rule(int $courseid, string $key): bool {
        $rules = self::get_rules($courseid);
        if (!array_key_exists($key, $rules)) {
            return false;
        }
        unset($rules[$key]);
        self::write_rules($courseid, $rules);
        unset(self::$rulecache[$courseid]);
        unset(self::$itemrulecache[$courseid]);
        return true;
    }

    /**
     * Write the versioned course rule document and omit the one-time export clean-group option.
     *
     * @param int $courseid Course whose custom-field value is updated.
     * @param array $rules Complete rule set to serialize.
     */
    private static function write_rules(int $courseid, array $rules): void {
        foreach ($rules as &$rule) {
            // This option applies only to one-time exports, never to scheduled rules.
            unset($rule['cleangroup']);
        }
        unset($rule);
        $handler = rules_handler::create();
        $field = self::ensure_field();
        $data = $handler->get_instance_fields_data([(int)$field->get('id') => $field], $courseid);
        $controller = $data[(int)$field->get('id')] ?? null;
        if (!$controller) {
            throw new \coding_exception('Could not load the grade export rules custom-field data controller.');
        }
        $controller->set('instanceid', $courseid);
        $controller->set('contextid', \context_course::instance($courseid)->id);
        $controller->set('value', json_encode([
            'version' => self::SCHEMA_VERSION,
            'rules' => $rules,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $controller->set('valueformat', FORMAT_PLAIN);
        $controller->set('valuetrust', 0);
        $controller->save();
    }

    /**
     * Evaluate the supported pass, fail, missing-grade and graded conditions for one final grade.
     *
     * @param mixed $finalgrade Final grade value, or null when the item has no grade.
     * @param float $gradepass Pass threshold configured on the grade item.
     * @param string $status Rule condition identifier.
     * @return bool Whether the final grade satisfies the condition.
     */
    public static function matches($finalgrade, float $gradepass, string $status): bool {
        $nograde = $finalgrade === null;
        $approved = !$nograde && $finalgrade >= $gradepass;
        switch ($status) {
            case 'failed':
                return !$nograde && !$approved;
            case 'failednograde':
                return !$approved;
            case 'nograde':
                return $nograde;
            case 'graded':
                return !$nograde;
            case 'approved':
                return $approved;
        }
        return false;
    }

    /**
     * Apply one manual export or saved-rule edit using the grade report data already loaded for the page.
     *
     * @param int $courseid Course containing the source and target groups.
     * @param array $rule Rule fields used for this operation.
     * @param array $grades Grade report data indexed by item and user.
     * @param bool $cleangroup Whether a one-time export first empties its target group.
     * @return int Number of students added to the target group.
     */
    public static function apply_rule_operation(
        int $courseid,
        array $rule,
        array $grades,
        array $users,
        ?array $oldrule,
        array $existingrules,
        ?string $oldrulekey,
        bool $cleangroup,
        bool $autoupdate
    ): int {
        self::load_group_library();
        $remainingrules = $existingrules;
        if ($oldrulekey !== null) {
            unset($remainingrules[$oldrulekey]);
        }

        $targetgroupid = (int)$rule['groupid'];
        $targetgroup = groups_get_group($targetgroupid);
        if (!$targetgroup || (int)$targetgroup->courseid !== $courseid) {
            throw new \invalid_parameter_exception('Invalid target group for grade export rule.');
        }
        $currentmembers = groups_get_members($targetgroupid);
        $memberids = array_fill_keys(array_map('intval', array_keys($currentmembers)), true);

        $targethasotherrules = false;
        foreach ($remainingrules as $existingrule) {
            if ((int)$existingrule['groupid'] === $targetgroupid) {
                $targethasotherrules = true;
                break;
            }
        }
        $eligibleids = self::eligible_user_ids($courseid, $rule, $grades);
        if ($cleangroup) {
            foreach ($currentmembers as $member) {
                groups_remove_member($targetgroup, $member);
            }
            $memberids = [];
        } else if (($oldrule !== null || $autoupdate) && !$targethasotherrules) {
            foreach ($currentmembers as $member) {
                if (!isset($eligibleids[(int)$member->id])) {
                    groups_remove_member($targetgroup, $member);
                    unset($memberids[(int)$member->id]);
                }
            }
        }

        $added = 0;
        foreach (array_keys($eligibleids) as $userid) {
            if (isset($memberids[$userid])) {
                continue;
            }
            $user = $users[$userid] ?? \core_user::get_user($userid);
            if (!$user || !empty($user->deleted)) {
                continue;
            }
            if (groups_add_member($targetgroup, $user)) {
                $added++;
                $memberids[$userid] = true;
            }
        }

        // If editing moves a rule to another group, remove its old contribution only when no other rule targets it.
        if ($oldrule !== null && (int)$oldrule['groupid'] !== $targetgroupid) {
            $oldgroupid = (int)$oldrule['groupid'];
            $oldgrouphasotherrules = false;
            foreach ($remainingrules as $existingrule) {
                if ((int)$existingrule['groupid'] === $oldgroupid) {
                    $oldgrouphasotherrules = true;
                    break;
                }
            }
            if (!$oldgrouphasotherrules) {
                $oldgroup = groups_get_group($oldgroupid);
                if ($oldgroup && (int)$oldgroup->courseid === $courseid) {
                    $oldmembers = groups_get_members($oldgroupid);
                    $oldeligibleids = self::eligible_user_ids($courseid, $oldrule, $grades);
                    foreach ($oldmembers as $member) {
                        if (isset($oldeligibleids[(int)$member->id])) {
                            groups_remove_member($oldgroup, $member);
                        }
                    }
                }
            }
        }
        return $added;
    }

    /**
     * Select eligible user IDs from in-memory grades and the optional source-group membership.
     *
     * @param int $courseid Course whose participants are considered.
     * @param array $rule Rule condition and source group.
     * @param array $grades Loaded grade data for the relevant grade item.
     * @return array User IDs that meet the rule.
     */
    private static function eligible_user_ids(int $courseid, array $rule, array $grades): array {
        $itemid = (int)$rule['itemid'];
        $gradeitem = \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
        if (!$gradeitem) {
            return [];
        }
        $sourceids = null;
        $sourcegroupid = (int)$rule['sourcegroupid'];
        if ($sourcegroupid) {
            $sourceids = array_fill_keys(
                array_map('intval', array_keys(groups_get_members($sourcegroupid, 'u.id'))),
                true
            );
        }
        $eligible = [];
        foreach ($grades as $userid => $usergrades) {
            if ($sourceids !== null && !isset($sourceids[(int)$userid])) {
                continue;
            }
            $grade = $usergrades[$itemid] ?? null;
            if ($grade && self::matches($grade->finalgrade, (float)$gradeitem->gradepass, $rule['status'])) {
                $eligible[(int)$userid] = true;
            }
        }
        return $eligible;
    }

    /**
     * Propagate a group-member addition through rules that use that group as their source.
     *
     * @param int $courseid Course owning the source group.
     * @param int $sourcegroupid Group the user joined.
     * @param int $userid User whose membership changed.
     */
    public static function update_source_group_member(int $courseid, int $sourcegroupid, int $userid): void {
        if ($courseid <= 0 || $sourcegroupid <= 0 || $userid <= 0) {
            return;
        }
        $lockkey = $courseid . ':' . $userid . ':' . $sourcegroupid;
        if (isset(self::$sourceeventlocks[$lockkey])) {
            return;
        }
        self::$sourceeventlocks[$lockkey] = true;
        try {
            $itemids = [];
            foreach (self::get_rules($courseid) as $rule) {
                if ((int)$rule['sourcegroupid'] === $sourcegroupid) {
                    $itemids[(int)$rule['itemid']] = true;
                }
            }
            foreach (array_keys($itemids) as $itemid) {
                $grade = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
                self::update_user($courseid, (int)$itemid, $userid, $grade ? $grade->finalgrade : null);
            }
        } finally {
            unset(self::$sourceeventlocks[$lockkey]);
        }
    }

    /**
     * Reconcile destination groups after a user leaves a source group.
     *
     * @param int $courseid Course owning the source group.
     * @param int $sourcegroupid Group the user left.
     * @param int $userid User whose membership changed.
     */
    public static function remove_user_from_source_group_targets(
        int $courseid,
        int $sourcegroupid,
        int $userid
    ): void {
        if ($courseid <= 0 || $sourcegroupid <= 0 || $userid <= 0) {
            return;
        }
        $lockkey = $courseid . ':' . $userid . ':' . $sourcegroupid;
        if (isset(self::$sourceeventlocks[$lockkey])) {
            return;
        }
        self::$sourceeventlocks[$lockkey] = true;
        try {
            self::remove_user_from_source_group_targets_locked($courseid, $sourcegroupid, $userid);
        } finally {
            unset(self::$sourceeventlocks[$lockkey]);
        }
    }

    /**
     * Keep a user in each affected target only when another applicable rule still qualifies them.
     *
     * @param int $courseid Course owning the source and destination groups.
     * @param int $sourcegroupid Source group the user left.
     * @param int $userid User whose destination memberships are reconciled.
     */
    private static function remove_user_from_source_group_targets_locked(
        int $courseid,
        int $sourcegroupid,
        int $userid
    ): void {
        self::load_group_library();
        $rules = self::get_rules($courseid);
        $targetgroupids = [];
        foreach ($rules as $rule) {
            if ((int)$rule['sourcegroupid'] === $sourcegroupid) {
                $targetgroupids[(int)$rule['groupid']] = true;
            }
        }
        if (!$targetgroupids) {
            return;
        }
        $affectedtargets = $targetgroupids;
        $membergroupids = array_fill_keys(
            array_map('intval', array_keys(groups_get_all_groups($courseid, $userid))),
            true
        );
        $grades = [];
        $gradeitems = [];
        foreach ($rules as $rule) {
            $targetgroupid = (int)$rule['groupid'];
            if (!isset($affectedtargets[$targetgroupid])) {
                continue;
            }
            $sourceid = (int)$rule['sourcegroupid'];
            if ($sourceid && !isset($membergroupids[$sourceid])) {
                continue;
            }
            $itemid = (int)$rule['itemid'];
            if (!array_key_exists($itemid, $grades)) {
                $grade = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
                $grades[$itemid] = $grade ? $grade->finalgrade : null;
                $gradeitems[$itemid] = \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
            }
            $gradeitem = $gradeitems[$itemid];
            if ($gradeitem && self::matches($grades[$itemid], (float)$gradeitem->gradepass, $rule['status'])) {
                // At least one remaining applicable rule keeps the user in this target.
                unset($targetgroupids[$targetgroupid]);
            }
        }
        foreach (array_keys($targetgroupids) as $targetgroupid) {
            $targetgroup = groups_get_group((int)$targetgroupid);
            if ($targetgroup && (int)$targetgroup->courseid === $courseid) {
                if (groups_is_member((int)$targetgroup->id, $userid)) {
                    groups_remove_member($targetgroup, $userid);
                }
            }
        }
    }

    /**
     * Reconcile one user's group memberships using only rules for the grade item that changed.
     *
     * @param int $courseid Course where the grade changed.
     * @param int $itemid Grade item whose final grade changed.
     * @param int $userid User whose grade changed.
     * @param mixed $finalgrade New final grade, or null when no grade is present.
     */
    public static function update_user(int $courseid, int $itemid, int $userid, $finalgrade): void {
        self::load_group_library();
        $allrules = self::get_rules($courseid);
        // A deleted target group can be recreated from its stored name. Persist the new id
        // once for every rule that shared the deleted target.
        $recreated = [];
        foreach ($allrules as $rulekey => $rule) {
            if ((int)$rule['itemid'] !== $itemid) {
                continue;
            }
            $groupid = (int)$rule['groupid'];
            if (array_key_exists($groupid, $recreated)) {
                continue;
            }
            $group = groups_get_group($groupid);
            if ($group && (int)$group->courseid === $courseid) {
                continue;
            }
            $groupname = trim((string)($rule['groupname'] ?? ''));
            if ($groupname === '') {
                continue;
            }
            $newgroupid = groups_get_group_by_name($courseid, $groupname);
            if (!$newgroupid) {
                $newgroupid = groups_create_group((object)[
                    'courseid' => $courseid,
                    'name' => $groupname,
                    'description' => '',
                    'visibility' => 3,
                ]);
            }
            $recreated[$groupid] = (int)$newgroupid;
        }
        if ($recreated) {
            $updatedrules = [];
            foreach ($allrules as $rule) {
                $oldgroupid = (int)$rule['groupid'];
                if (isset($recreated[$oldgroupid])) {
                    $rule['groupid'] = $recreated[$oldgroupid];
                }
                $newkey = implode(':', [(int)$rule['groupid'], (int)$rule['sourcegroupid'],
                    (int)$rule['itemid'], $rule['status']]);
                $updatedrules[$newkey] = $rule;
            }
            self::write_rules($courseid, $updatedrules);
            self::$rulecache[$courseid] = $updatedrules;
            unset(self::$itemrulecache[$courseid]);
            $allrules = $updatedrules;
        }
        $rules = self::get_rules_for_item($courseid, $itemid);
        if (!$rules) {
            return;
        }

        $coursegroups = groups_get_all_groups($courseid, $userid);
        $membergroupids = array_fill_keys(array_map('intval', array_keys($coursegroups)), true);
        $applicablerules = array_filter($rules, static function ($rule) use ($membergroupids) {
            $sourcegroupid = (int)$rule['sourcegroupid'];
            return !$sourcegroupid || isset($membergroupids[$sourcegroupid]);
        });
        if (!$applicablerules) {
            return;
        }
        $gradeitem = \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
        if (!$gradeitem) {
            return;
        }
        $matchesbygroup = [];
        $hasotheritemrules = [];
        foreach ($allrules as $rule) {
            $groupid = (int)$rule['groupid'];
            if ((int)$rule['itemid'] !== $itemid) {
                $hasotheritemrules[$groupid] = true;
            }
        }
        foreach ($applicablerules as $rule) {
            $groupid = (int)$rule['groupid'];
            $matchesbygroup[$groupid] = ($matchesbygroup[$groupid] ?? false) ||
                self::matches($finalgrade, (float)$gradeitem->gradepass, $rule['status']);
        }
        $changes = [];
        foreach ($matchesbygroup as $groupid => $matches) {
            $ismember = isset($membergroupids[$groupid]);
            if ($matches && !$ismember) {
                $changes[$groupid] = true;
            } else if (!$matches && $ismember && empty($hasotheritemrules[$groupid])) {
                // Keep membership if another grade item can independently qualify this user.
                $changes[$groupid] = false;
            }
        }
        if (!$changes) {
            return;
        }
        $user = \core_user::get_user($userid);
        if (!$user || !empty($user->deleted)) {
            return;
        }
        foreach ($changes as $groupid => $shouldbemember) {
            $group = $coursegroups[$groupid] ?? groups_get_group($groupid);
            if (!$group || (int)$group->courseid !== $courseid) {
                continue;
            }
            if ($shouldbemember) {
                groups_add_member($group, $user);
            } else {
                groups_remove_member($group, $user);
            }
        }
    }

    /**
     * Return and memoize the subset of course rules associated with one grade item.
     *
     * @param int $courseid Course whose rules are indexed.
     * @param int $itemid Grade item used to filter the rules.
     * @return array Rules that respond to this grade item.
     */
    private static function get_rules_for_item(int $courseid, int $itemid): array {
        if (!array_key_exists($itemid, self::$itemrulecache[$courseid] ?? [])) {
            self::$itemrulecache[$courseid][$itemid] = array_filter(
                self::get_rules($courseid),
                static function ($rule) use ($itemid) {
                    return (int)$rule['itemid'] === $itemid;
                }
            );
        }
        return self::$itemrulecache[$courseid][$itemid];
    }

    /**
     * Load Moodle's group API for gradebook entry points that do not include it by default.
     */
    private static function load_group_library(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
    }
}
