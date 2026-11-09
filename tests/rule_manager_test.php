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
 * Verify cycle checks, grade-based memberships and event propagation for automatic group rules.
 *
 * @package    gradeexport_group
 * @copyright 2026 Juan Pablo de Castro <juanpablo.decastro@uva.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \gradeexport_group\rule_manager
 */
final class rule_manager_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->reset_rule_manager_caches();
        $this->setAdminUser();
    }

    /**
     * Reject both a self-reference and an indirect path that would close a cycle.
     *
     * @covers ::save_rule
     */
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

    /**
     * Accept acyclic source chains and rules that use all course participants as their source.
     *
     * @covers ::save_rule
     * @covers ::get_rules
     */
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

    /**
     * Preserve legacy cycles and validate only the edge being added to the existing rule set.
     *
     * @covers ::save_rule
     * @covers ::get_rules
     */
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

    /**
     * Keep a shared target membership only when another source rule still qualifies the user.
     *
     * @covers ::remove_user_from_source_group_targets
     */
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

        // Source A's rules stop applying after removal. Source B keeps the student in keepgroup; source C does
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

    /**
     * Propagate a source group addition through successive rules in an acyclic chain.
     *
     * @covers \gradeexport_group\observer::group_member_added
     */
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
        observer::group_member_added(
            $this->group_event(
                \core\event\group_member_added::class,
                $course->id,
                $source->id,
                $student->id
            )
        );

        $this->assertTrue(groups_is_member($middle->id, $student->id));
        $this->assertTrue(groups_is_member($target->id, $student->id));
    }

    /**
     * Short-circuit a repeated source-group event while the same user and source are being processed.
     *
     * @covers ::update_source_group_member
     */
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

    /**
     * Build a serialized rule record for the group membership tests.
     *
     * @param int $sourcegroupid Source group, or zero for all participants.
     * @param int $targetgroupid Group populated by the rule.
     * @param int $itemid Grade item evaluated by the rule.
     * @param string $status Grade condition used to select students.
     * @return array Stored rule data with the defaults used by the manager.
     */
    private function rule(int $sourcegroupid, int $targetgroupid, int $itemid = 1, string $status = 'graded'): array {
        return [
            'groupid' => $targetgroupid,
            'groupname' => 'Target ' . $targetgroupid,
            'sourcegroupid' => $sourcegroupid,
            'itemid' => $itemid,
            'status' => $status,
        ];
    }

    /**
     * Create a manual grade item with a pass threshold and one student's final grade.
     *
     * @param int $courseid Course containing the test grade item.
     * @param int $userid Student receiving the grade.
     * @param float $finalgrade Final grade value to save.
     * @return \grade_item Updated grade item.
     */
    private function create_grade_item(int $courseid, int $userid, float $finalgrade): \grade_item {
        $record = $this->getDataGenerator()->create_grade_item([
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'itemname' => 'Rule test grade',
            'gradetype' => \GRADE_TYPE_VALUE,
            'grademin' => 0,
            'grademax' => 100,
        ]);
        $item = \grade_item::fetch(['id' => $record->id, 'courseid' => $courseid]);
        $item->gradepass = 50;
        $item->update();
        $item->update_final_grade($userid, $finalgrade, 'unittest', false, FORMAT_PLAIN, null, null, true);
        return $item;
    }

    /**
     * Build a core group-membership event to invoke an observer without dispatching it globally.
     *
     * @param string $classname Core event class name.
     * @param int $courseid Course that owns the affected group.
     * @param int $groupid Group whose membership changed.
     * @param int $userid Student whose membership changed.
     * @return \core\event\base Group membership event instance.
     */
    private function group_event(string $classname, int $courseid, int $groupid, int $userid) {
        return $classname::create([
            'context' => \context_course::instance($courseid),
            'objectid' => $groupid,
            'relateduserid' => $userid,
            'other' => ['component' => '', 'itemid' => 0],
        ]);
    }

    /**
     * Reset static rule indexes and re-entry locks so test cases remain isolated.
     */
    private function reset_rule_manager_caches(): void {
        foreach (['rulecache', 'itemrulecache', 'sourceeventlocks'] as $propertyname) {
            $property = new \ReflectionProperty(rule_manager::class, $propertyname);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }
}
