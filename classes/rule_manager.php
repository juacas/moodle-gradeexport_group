<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace gradeexport_group;

use gradeexport_group\customfield\rules_handler;

defined('MOODLE_INTERNAL') || die();

/** Store and apply automatic group rules for a course. */
class rule_manager {
    /** Version of the stored JSON document. */
    private const SCHEMA_VERSION = 1;
    /** Custom-field short name. */
    private const FIELD_SHORTNAME = 'automatic_group_rules';
    /** @var array Request-local cache of course rules. */
    private static array $rulecache = [];
    /** @var array Request-local index of rules by course and grade item. */
    private static array $itemrulecache = [];

    /** Get all rules configured for a course. */
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
                if (is_array($document) && ($document['version'] ?? null) === self::SCHEMA_VERSION &&
                        is_array($document['rules'] ?? null)) {
                    return self::$rulecache[$courseid] = $document['rules'];
                }
            }
        }
        // Migrate rules created by the first implementation, which stored them in course format options.
        return self::$rulecache[$courseid] = self::read_legacy_rules($courseid);
    }

    /** Ensure the private versioned JSON field exists. */
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

    /** Find the plugin's custom-field definition without creating it. */
    private static function find_field(rules_handler $handler): ?\core_customfield\field_controller {
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === self::FIELD_SHORTNAME) {
                return $field;
            }
        }
        return null;
    }

    /** Read configuration from the legacy format-option location. */
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

    /** Add or replace a rule, keyed by its target/source/item/condition to prevent duplicates. */
    public static function save_rule(int $courseid, array $rule): void {
        self::save_rules($courseid, $rule, null);
    }

    /** Save a rule, optionally replacing its previous key. */
    public static function save_rules(int $courseid, array $rule, ?string $replacekey): void {
        $rules = self::get_rules($courseid);
        if ($replacekey !== null) {
            unset($rules[$replacekey]);
        }
        $key = implode(':', [(int)$rule['groupid'], (int)$rule['sourcegroupid'], (int)$rule['itemid'], $rule['status']]);
        $rules[$key] = $rule;
        self::write_rules($courseid, $rules);
        unset(self::$rulecache[$courseid]);
        unset(self::$itemrulecache[$courseid]);
    }

    /** Delete one rule by its stored key. */
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

    /** Persist the complete course rule set. */
    private static function write_rules(int $courseid, array $rules): void {
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

    /** Determine whether a grade meets a rule's condition. */
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

    /** Apply an add/edit operation using the grade report data already loaded in memory. */
    public static function apply_rule_operation(
            int $courseid,
            array $rule,
            array $grades,
            array $users,
            ?array $oldrule,
            array $existingrules,
            ?string $oldrulekey,
            bool $cleangroup,
            bool $autoupdate): int {
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

    /** Get eligible IDs for one rule from already-loaded grade data. */
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

    /** Update one user's membership for rules matching the changed grade item only. */
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
        $applicablerules = array_filter($rules, static function($rule) use ($membergroupids) {
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

    /** Return only the rules for one grade item, cached for this request. */
    private static function get_rules_for_item(int $courseid, int $itemid): array {
        if (!array_key_exists($itemid, self::$itemrulecache[$courseid] ?? [])) {
            self::$itemrulecache[$courseid][$itemid] = array_filter(
                self::get_rules($courseid),
                static function($rule) use ($itemid) {
                    return (int)$rule['itemid'] === $itemid;
                }
            );
        }
        return self::$itemrulecache[$courseid][$itemid];
    }

    /** Load the group membership API, which is not included in every gradebook entry point. */
    private static function load_group_library(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
    }
}
