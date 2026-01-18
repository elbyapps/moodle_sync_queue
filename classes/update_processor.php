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
 * Processor for updates downloaded from the central server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_processor {

    /** @var id_mapper ID mapping helper */
    protected id_mapper $mapper;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mapper = new id_mapper();
    }

    /**
     * Process a batch of updates from the central server.
     *
     * @param array $updates Array of update records.
     * @return array Results with success/failed/skipped counts.
     */
    public function process(array $updates): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($updates as $update) {
            try {
                $processed = $this->process_update($update);
                if ($processed) {
                    $results['success']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'update' => $update,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Process a single update.
     *
     * @param array $update Update data.
     * @return bool True if processed, false if skipped.
     */
    protected function process_update(array $update): bool {
        $type = $update['type'] ?? null;

        switch ($type) {
            case 'course':
                return $this->process_course_update($update);

            case 'user':
                return $this->process_user_update($update);

            case 'enrolment':
                return $this->process_enrolment_update($update);

            case 'course_content':
                return $this->process_content_update($update);

            default:
                debugging("Unknown update type: {$type}", DEBUG_DEVELOPER);
                return false;
        }
    }

    /**
     * Process a course update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_course_update(array $update): bool {
        global $DB;

        $data = $update['data'];
        $centralid = $data['id'];

        // Check if we already have this course mapped.
        $localid = $this->mapper->get_local_id('course', $centralid);

        if ($localid) {
            // Update existing course.
            $course = $DB->get_record('course', ['id' => $localid]);
            if ($course) {
                $course->fullname = $data['fullname'];
                $course->shortname = $data['shortname'];
                $course->summary = $data['summary'] ?? '';
                $course->timemodified = time();
                $DB->update_record('course', $course);
                return true;
            }
        }

        // Course doesn't exist locally - skip for now.
        // Full course creation would require backup/restore.
        return false;
    }

    /**
     * Process a user update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_user_update(array $update): bool {
        global $DB;

        $data = $update['data'];
        $centralid = $data['id'];

        // Try to find user by email or username.
        $user = $DB->get_record('user', ['email' => $data['email']]);
        if (!$user) {
            $user = $DB->get_record('user', ['username' => $data['username']]);
        }

        if ($user) {
            // Update mapping.
            $this->mapper->set_mapping('user', $user->id, $centralid);

            // Update user fields (non-auth fields only).
            $user->firstname = $data['firstname'];
            $user->lastname = $data['lastname'];
            $user->idnumber = $data['idnumber'] ?? '';
            $user->timemodified = time();
            $DB->update_record('user', $user);
            return true;
        }

        // Create new user.
        $newuser = new stdClass();
        $newuser->username = $data['username'];
        $newuser->email = $data['email'];
        $newuser->firstname = $data['firstname'];
        $newuser->lastname = $data['lastname'];
        $newuser->idnumber = $data['idnumber'] ?? '';
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $DB->get_field('mnet_host', 'id', ['wwwroot' => $GLOBALS['CFG']->wwwroot]);
        $newuser->password = hash_internal_user_password(random_string(20));
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        $localid = $DB->insert_record('user', $newuser);

        // Save mapping.
        $this->mapper->set_mapping('user', $localid, $centralid);

        return true;
    }

    /**
     * Process an enrolment update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_enrolment_update(array $update): bool {
        global $DB;

        $data = $update['data'];

        // Get local IDs.
        $localuserid = $this->mapper->get_local_id('user', $data['userid']);
        $localcourseid = $this->mapper->get_local_id('course', $data['courseid']);

        if (!$localuserid || !$localcourseid) {
            return false; // Can't enrol without both.
        }

        // Get manual enrol instance.
        $enrol = $DB->get_record('enrol', [
            'courseid' => $localcourseid,
            'enrol' => 'manual',
        ]);

        if (!$enrol) {
            return false;
        }

        // Check if already enrolled.
        $existing = $DB->get_record('user_enrolments', [
            'enrolid' => $enrol->id,
            'userid' => $localuserid,
        ]);

        if ($existing) {
            // Update status if needed.
            if ($existing->status != $data['status']) {
                $existing->status = $data['status'];
                $existing->timemodified = time();
                $DB->update_record('user_enrolments', $existing);
            }
            return true;
        }

        // Create enrolment.
        $enrolment = new stdClass();
        $enrolment->enrolid = $enrol->id;
        $enrolment->userid = $localuserid;
        $enrolment->status = $data['status'] ?? 0;
        $enrolment->timestart = $data['timestart'] ?? 0;
        $enrolment->timeend = $data['timeend'] ?? 0;
        $enrolment->timecreated = time();
        $enrolment->timemodified = time();

        $DB->insert_record('user_enrolments', $enrolment);

        // Assign role.
        $context = \context_course::instance($localcourseid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($roleid) {
            role_assign($roleid, $localuserid, $context->id);
        }

        return true;
    }

    /**
     * Process a course content update (requires backup/restore).
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_content_update(array $update): bool {
        // Course content updates require backup/restore.
        // This is a placeholder for the more complex implementation.

        $data = $update['data'];
        $backupurl = $data['backup_url'] ?? null;

        if (!$backupurl) {
            return false;
        }

        // TODO: Download backup file and restore.
        // This requires significant implementation for:
        // 1. Download backup file from central server
        // 2. Extract and validate
        // 3. Run restore process
        // 4. Update ID mappings

        return false;
    }
}
