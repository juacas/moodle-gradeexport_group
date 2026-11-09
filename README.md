# Grade export to group

[![Moodle Plugin CI](https://github.com/juacas/moodle-gradeexport_group/actions/workflows/ci.yml/badge.svg)](https://github.com/juacas/moodle-gradeexport_group/actions/workflows/ci.yml)
[![Project website](https://img.shields.io/badge/website-live-22b8aa)](https://juacas.github.io/moodle-gradeexport_group/)

Create Moodle groups from gradebook criteria, then use those groups to adapt course activities and communications. Rules can keep group membership synchronized as grades change.

**[Project website](https://juacas.github.io/moodle-gradeexport_group/) · [Español](https://juacas.github.io/moodle-gradeexport_group/es/) · [Moodle Plugins Directory](https://marketplace.moodle.com/plugins/gradeexport_group) · [GitHub releases](https://github.com/juacas/moodle-gradeexport_group/releases)**

## Use cases

- Generate groups manually from a grade item and a pass/fail or grading status criterion.
- Keep a group up to date with students who have not passed a partial assessment.
- Use groups as availability restrictions for follow-up activities and resources.
- Send targeted communications to the students who meet a specific criterion.

## Usage

1. Open the course gradebook and select **Export grades to groups**.
2. Choose a grade item and the criterion to apply.
3. Select or create the destination group and save the export.
4. To keep membership synchronized, create an automatic rule for the grade item and criterion.

The plugin creates standard Moodle groups, which can be selected in activity availability settings and other Moodle features that use groups.

## How automatic rules work

An automatic rule is a course-level instruction that combines a grade item, a grade condition, an optional source group and a destination group. For example, a course can keep a “Needs resit” group populated with students who have not passed the midterm, while a second rule uses another grade item to maintain a separate group for a later assessment. A source group narrows the students considered by the rule; leaving it unset means all students considered in the course grade report are eligible for evaluation, including students with no grade when the selected condition includes them.

The condition is evaluated against the grade item's pass threshold and whether a final grade exists:

| Condition | Students selected |
| --- | --- |
| Passed | A final grade at or above the item's pass threshold |
| Failed | A final grade below the pass threshold |
| Failed or no grade | Below the threshold, including students without a grade |
| Graded | Any student with a final grade |
| No grade | Students without a final grade |

After a grade changes, Moodle's grade event causes the plugin to reevaluate only rules for that grade item and the affected student. When a student joins a source group, rules sourced from that group are reevaluated for that student; when they leave, the plugin removes them from a destination only if no remaining rule for that destination still justifies their membership. This lets several rules contribute to one destination group without one rule's result undoing another's. Normal group membership events are used, so the result is available to Moodle's group restrictions and communication tools.

Rules are stored with the course in a private, versioned custom-field value. A duplicate is defined by the same destination, source group, grade item and condition; saving that combination updates the existing rule. The plugin rejects newly added rules that would create a cycle between source and destination groups, and uses a request-local re-entry lock while reacting to group events. If a destination group has been deleted, its saved name lets the plugin find or recreate it when that rule is next applied. The “Empty target group before exporting” option is for a one-time export and is not part of a saved automatic rule.

Automatic rules respond to grade and source-group membership events; they do not continuously scan or rebuild every course group. Saving a rule from the grade export page also applies the selected condition to the grade report data loaded for that operation; later grade or source-group events keep the membership current incrementally. Review the rule list in the course grade export page to see its source, destination and current group membership count.

## Installation

Download a release ZIP from the [Moodle Plugins Directory](https://marketplace.moodle.com/plugins/gradeexport_group) or [GitHub Releases](https://github.com/juacas/moodle-gradeexport_group/releases), install it in `grade/export/group`, and complete installation through the Moodle administration interface.

## Compatibility and CI

The GitHub Actions workflow uses [Moodle Plugin CI](https://github.com/moodlehq/moodle-plugin-ci) and checks Moodle 4.5 and 5.3 on PHP 8.3. The plugin declares Moodle 4.1 as its minimum version.

## Releases and Marketplace publication

To publish a release, push a version tag such as `v1.0.5`. The release workflow creates a GitHub Release with a ZIP generated by `git archive` and submits the release to the Moodle Plugins Directory through Moodle's official reusable release workflow. Each release needs a `RELEASE.md` file with notes for that version.

Configure the repository's `MOODLE_MARKETPLACE_TOKEN` GitHub Actions secret before publishing. The token is available from the Moodle Plugins Directory account used to maintain this plugin.

The project website is deployed to GitHub Pages from `main`. In the repository settings, select **Settings → Pages → GitHub Actions** as the deployment source.

## Support

Report issues or request features in the [GitHub issue tracker](https://github.com/juacas/moodle-gradeexport_group/issues). See the [Moodle Plugins Directory entry](https://marketplace.moodle.com/plugins/gradeexport_group) for plugin information.

## License

This plugin is released under the [GNU General Public License v3 or later](https://www.gnu.org/licenses/gpl-3.0.en.html).
