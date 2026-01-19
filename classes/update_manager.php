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

namespace local_syncqueue;

use stdClass;

/**
 * Manager for outgoing updates to schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_manager {

    /** @var string Updates table */
    protected const TABLE = 'local_syncqueue_updates';

    /** @var string School updates tracking table */
    protected const TRACKING_TABLE = 'local_syncqueue_school_updates';

    /**
     * Queue an update for all schools.
     *
     * @param string $type Update type (course, user, enrolment, content).
     * @param string $action Action (create, update, delete).
     * @param array $data Update data.
     * @param int $priority Priority 1-10.
     * @return int Update ID.
     */
    public function queue_update(string $type, string $action, array $data, int $priority = 5): int {
        return $this->queue_update_for_school(null, $type, $action, $data, $priority);
    }

    /**
     * Queue an update for a specific school.
     *
     * @param string|null $schoolid School ID (null for all schools).
     * @param string $type Update type.
     * @param string $action Action.
     * @param array $data Update data.
     * @param int $priority Priority.
     * @return int Update ID.
     */
    public function queue_update_for_school(
        ?string $schoolid,
        string $type,
        string $action,
        array $data,
        int $priority = 5
    ): int {
        global $DB;

        $update = new stdClass();
        $update->schoolid = $schoolid;
        $update->updatetype = $type;
        $update->action = $action;
        $update->objecttable = $data['table'] ?? null;
        $update->objectid = $data['id'] ?? null;
        $update->payload = json_encode($data);
        $update->priority = $priority;
        $update->timecreated = time();

        return $DB->insert_record(self::TABLE, $update);
    }

    /**
     * Get pending updates for a school.
     *
     * @param string $schoolid School identifier.
     * @param int $since Get updates since this timestamp.
     * @param int $limit Maximum updates.
     * @return array Updates.
     */
    public function get_updates_for_school(string $schoolid, int $since = 0, int $limit = 100): array {
        global $DB;

        // Get updates that are either for this specific school or for all schools.
        // Exclude updates already downloaded by this school.
        $sql = "SELECT u.*
                FROM {" . self::TABLE . "} u
                LEFT JOIN {" . self::TRACKING_TABLE . "} t
                    ON t.updateid = u.id AND t.schoolid = :schoolid
                WHERE u.timecreated > :since
                    AND (u.schoolid IS NULL OR u.schoolid = :schoolid2)
                    AND t.id IS NULL
                ORDER BY u.priority ASC, u.timecreated ASC";

        $params = [
            'schoolid' => $schoolid,
            'schoolid2' => $schoolid,
            'since' => $since,
        ];

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Mark an update as downloaded by a school.
     *
     * @param string $schoolid School identifier.
     * @param int $updateid Update ID.
     */
    public function mark_downloaded(string $schoolid, int $updateid): void {
        global $DB;

        // Check if already tracked.
        $exists = $DB->record_exists(self::TRACKING_TABLE, [
            'schoolid' => $schoolid,
            'updateid' => $updateid,
        ]);

        if (!$exists) {
            $tracking = new stdClass();
            $tracking->schoolid = $schoolid;
            $tracking->updateid = $updateid;
            $tracking->status = 'downloaded';
            $tracking->timedownloaded = time();

            $DB->insert_record(self::TRACKING_TABLE, $tracking);
        }
    }

    /**
     * Queue a course update.
     *
     * @param stdClass $course Course object.
     * @param string $action create, update, or delete.
     */
    public function queue_course_update(stdClass $course, string $action = 'update'): void {
        global $DB;

        // Get full category path.
        $categorypath = $this->get_category_path($course->category);

        $data = [
            'table' => 'course',
            'id' => $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber ?? '',
            'summary' => $course->summary ?? '',
            'summaryformat' => $course->summaryformat ?? FORMAT_HTML,
            'format' => $course->format ?? 'topics',
            'numsections' => $course->numsections ?? 10,
            'visible' => $course->visible,
            'startdate' => $course->startdate,
            'enddate' => $course->enddate,
            'category' => [
                'id' => $course->category,
                'path' => $categorypath,
            ],
        ];

        $this->queue_update('course', $action, $data, 2);
    }

    /**
     * Queue a course update with backup file.
     *
     * @param stdClass $course Course object.
     * @param string $backupfile Backup filename.
     * @param string $action create, update, or delete.
     */
    public function queue_course_update_with_backup(stdClass $course, string $backupfile, string $action = 'create'): void {
        global $CFG;

        // Get full category path.
        $categorypath = $this->get_category_path($course->category);

        $data = [
            'table' => 'course',
            'id' => $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber ?? '',
            'summary' => $course->summary ?? '',
            'summaryformat' => $course->summaryformat ?? FORMAT_HTML,
            'format' => $course->format ?? 'topics',
            'numsections' => $course->numsections ?? 10,
            'visible' => $course->visible,
            'startdate' => $course->startdate,
            'enddate' => $course->enddate,
            'category' => [
                'id' => $course->category,
                'path' => $categorypath,
            ],
            'backup' => [
                'filename' => $backupfile,
                'has_backup' => true,
            ],
        ];

        $this->queue_update('course', $action, $data, 2);
    }

    /**
     * Get category path as array of category names.
     *
     * @param int $categoryid Category ID.
     * @return array Category path from root to leaf.
     */
    protected function get_category_path(int $categoryid): array {
        $path = [];
        $category = \core_course_category::get($categoryid, IGNORE_MISSING);

        if (!$category) {
            return $path;
        }

        // Build path from root to this category.
        $parents = $category->get_parents();
        foreach ($parents as $parentid) {
            $parent = \core_course_category::get($parentid, IGNORE_MISSING);
            if ($parent) {
                $path[] = [
                    'id' => $parent->id,
                    'name' => $parent->name,
                    'idnumber' => $parent->idnumber ?? '',
                ];
            }
        }

        // Add current category.
        $path[] = [
            'id' => $category->id,
            'name' => $category->name,
            'idnumber' => $category->idnumber ?? '',
        ];

        return $path;
    }

    /**
     * Queue a user update.
     *
     * @param stdClass $user User object.
     * @param string $action create, update, or delete.
     */
    public function queue_user_update(stdClass $user, string $action = 'update'): void {
        $data = [
            'table' => 'user',
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'idnumber' => $user->idnumber ?? '',
        ];

        $this->queue_update('user', $action, $data, 3);
    }

    /**
     * Queue an enrolment update.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @param string $action create, update, or delete.
     * @param array $extra Extra enrolment data.
     */
    public function queue_enrolment_update(int $userid, int $courseid, string $action, array $extra = []): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, username, email, idnumber');
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, idnumber');

        $data = [
            'table' => 'user_enrolments',
            'userid' => $userid,
            'courseid' => $courseid,
            'user' => $user ? (array) $user : [],
            'course' => $course ? (array) $course : [],
            'status' => $extra['status'] ?? 0,
            'timestart' => $extra['timestart'] ?? 0,
            'timeend' => $extra['timeend'] ?? 0,
        ];

        $this->queue_update('enrolment', $action, $data, 3);
    }

    /**
     * Cleanup old updates that all schools have downloaded.
     *
     * @param int $daysold Days old to consider for cleanup.
     * @return int Number of deleted updates.
     */
    public function cleanup_old_updates(int $daysold = 30): int {
        global $DB;

        $cutoff = time() - ($daysold * DAYSECS);

        // Delete updates older than cutoff that all active schools have downloaded.
        $sql = "DELETE FROM {" . self::TABLE . "}
                WHERE timecreated < :cutoff
                AND id NOT IN (
                    SELECT DISTINCT u.id
                    FROM {" . self::TABLE . "} u
                    CROSS JOIN {local_syncqueue_schools} s
                    LEFT JOIN {" . self::TRACKING_TABLE . "} t
                        ON t.updateid = u.id AND t.schoolid = s.schoolid
                    WHERE s.status = 'active'
                        AND t.id IS NULL
                        AND (u.schoolid IS NULL OR u.schoolid = s.schoolid)
                )";

        return $DB->execute($sql, ['cutoff' => $cutoff]);
    }
}
