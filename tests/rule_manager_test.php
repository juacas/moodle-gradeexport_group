<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace gradeexport_group;

defined('MOODLE_INTERNAL') || die();

/** Tests for scheduled group rules and membership event handling. */
class rule_manager_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->reset_rule_manager_caches();
        $this->setAdminUser();
    }

    /** A new rule is rejected if its source-to-target edge closes a direct or indirect cycle. */
    public function test_rejects_new_direct_and_indirect_cycles(): void {
        $course = $this->getDataGenerator()->create_course();
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        rule_manager::save_rule($course->id, $this->rule($groupa->id, $groupb->id));
        rule_manager::save_rule($course->id, $this->rule($groupb->id, $groupc->id));

        try {
            rule_manager::save_rule($course->id, $this->rule($groupc->id, $groupa->id));
            $this->fail('Expected the rule that closes an indirect cycle to be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('rulecycle', $exception->errorcode);
        }

        try {
            rule_manager::save_rule($course->id, $this->rule($groupa->id, $groupa->id));
            $this->fail('Expected a self-referencing rule to be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('rulecycle', $exception->errorcode);
        }
    }

    /** Acyclic chains and all-participant rules remain valid. */
    public function test_accepts_acyclic_rules_and_all_participants_source(): void {
        $course = $this->getDataGenerator()->create_course();
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        rule_manager::save_rule($course->id, $this->rule($groupa->id, $groupb->id));
        rule_manager::save_rule($course->id, $this->rule($groupb->id, $groupc->id));
        rule_manager::save_rule($course->id, $this->rule(0, $groupa->id));

        $this->assertCount(3, rule_manager::get_rules($course->id));
    }

    /** Existing cycles are left alone; cycle validation applies to the new edge only. */
    public function test_does_not_scan_or_reject_preexisting_cycles(): void {
        $course = $this->getDataGenerator()->create_course();
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupd = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $existingrules = [
            'legacy-a-b' => $this->rule($groupa->id, $groupb->id),
            'legacy-b-a' => $this->rule($groupb->id, $groupa->id),
        ];
        $writer = new \ReflectionMethod(rule_manager::class, 'write_rules');
        $writer->setAccessible(true);
        $writer->invoke(null, $course->id, $existingrules);

        rule_manager::save_rule($course->id, $this->rule($groupc->id, $groupd->id));

        $this->assertCount(3, rule_manager::get_rules($course->id));
    }

    /** A user stays in a shared target only when another target rule still qualifies. */
    public function test_source_group_removal_evaluates_all_rules_for_each_target(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $sourcea = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $sourceb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $sourcec = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $keepgroup = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $removegroup = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $item1 = $this->create_grade_item($course->id, $student->id, 80);
        $item2 = $this->create_grade_item($course->id, $student->id, 20);

        groups_add_member($sourcea, $student);
        groups_add_member($sourceb, $student);
        groups_add_member($sourcec, $student);
        groups_add_member($keepgroup, $student);
        groups_add_member($removegroup, $student);

        // sourcea's rules stop applying after removal. sourceb keeps keepgroup; sourcec does
        // not meet the condition for removegroup.
        rule_manager::save_rule($course->id, $this->rule($sourcea->id, $keepgroup->id, $item1->id, 'failed'));
        rule_manager::save_rule($course->id, $this->rule($sourceb->id, $keepgroup->id, $item1->id, 'approved'));
        rule_manager::save_rule($course->id, $this->rule($sourcea->id, $removegroup->id, $item1->id, 'failed'));
        rule_manager::save_rule($course->id, $this->rule($sourcec->id, $removegroup->id, $item2->id, 'approved'));

        groups_remove_member($sourcea, $student);
        rule_manager::remove_user_from_source_group_targets($course->id, $sourcea->id, $student->id);

        $this->assertTrue(groups_is_member($keepgroup->id, $student->id));
        $this->assertFalse(groups_is_member($removegroup->id, $student->id));
    }

    /** Group-added events propagate through an acyclic chain. */
    public function test_group_added_rules_propagate_through_acyclic_chain(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $source = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $middle = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $target = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $item = $this->create_grade_item($course->id, $student->id, 80);
        rule_manager::save_rule($course->id, $this->rule($source->id, $middle->id, $item->id, 'graded'));
        rule_manager::save_rule($course->id, $this->rule($middle->id, $target->id, $item->id, 'graded'));

        groups_add_member($source, $student);
        // Call explicitly as well so the test remains independent of the site observer cache.
        observer::group_member_added($this->group_event(\core\event\group_member_added::class,
            $course->id, $source->id, $student->id));

        $this->assertTrue(groups_is_member($middle->id, $student->id));
        $this->assertTrue(groups_is_member($target->id, $student->id));
    }

    /** A repeated event for the same source cannot re-enter its active processing path. */
    public function test_source_event_reentry_lock_short_circuits_duplicate_processing(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $source = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $target = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $item = $this->create_grade_item($course->id, $student->id, 80);
        rule_manager::save_rule($course->id, $this->rule($source->id, $target->id, $item->id, 'graded'));

        $lockkey = implode(':', [$course->id, $student->id, $source->id]);
        $locks = new \ReflectionProperty(rule_manager::class, 'sourceeventlocks');
        $locks->setAccessible(true);
        $locks->setValue(null, [$lockkey => true]);
        try {
            rule_manager::update_source_group_member($course->id, $source->id, $student->id);
            $this->assertFalse(groups_is_member($target->id, $student->id));
        } finally {
            $locks->setValue(null, []);
        }
    }

    /** Build a group rule with defaults suitable for storage. */
    private function rule(int $sourcegroupid, int $targetgroupid, int $itemid = 1, string $status = 'graded'): array {
        return [
            'groupid' => $targetgroupid,
            'groupname' => 'Target ' . $targetgroupid,
            'sourcegroupid' => $sourcegroupid,
            'itemid' => $itemid,
            'status' => $status,
        ];
    }

    /** Create an item with a gradepass and a final grade for a student. */
    private function create_grade_item(int $courseid, int $userid, float $finalgrade): \grade_item {
        $record = $this->getDataGenerator()->create_grade_item([
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'itemname' => 'Rule test grade',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademin' => 0,
            'grademax' => 100,
        ]);
        $item = \grade_item::fetch(['id' => $record->id, 'courseid' => $courseid]);
        $item->gradepass = 50;
        $item->update();
        $item->update_final_grade($userid, $finalgrade, 'unittest', false, FORMAT_PLAIN, null, null, true);
        return $item;
    }

    /** Create a group membership event object for directly exercising its observer. */
    private function group_event(string $classname, int $courseid, int $groupid, int $userid) {
        return $classname::create([
            'context' => \context_course::instance($courseid),
            'objectid' => $groupid,
            'relateduserid' => $userid,
            'other' => ['component' => '', 'itemid' => 0],
        ]);
    }

    /** Clear request-local rule caches between tests. */
    private function reset_rule_manager_caches(): void {
        foreach (['rulecache', 'itemrulecache', 'sourceeventlocks'] as $propertyname) {
            $property = new \ReflectionProperty(rule_manager::class, $propertyname);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }
}
