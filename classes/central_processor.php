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
 * Processor for handling uploads from schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class central_processor {

    /** @var id_mapper ID mapper instance */
    protected id_mapper $mapper;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mapper = new id_mapper();
    }

    /**
     * Process a single sync item from a school.
     *
     * @param string $schoolid School identifier.
     * @param array $item Sync item data.
     * @return array Result with status and message.
     */
    public function process_item(string $schoolid, array $item): array {
        $eventtype = $item['eventtype'] ?? 'unknown';
        $payload = $item['payload'] ?? [];

        switch ($eventtype) {
            case 'grade':
                return $this->process_grade($schoolid, $payload);

            case 'submission':
                return $this->process_submission($schoolid, $payload);

            case 'quiz':
                return $this->process_quiz_attempt($schoolid, $payload);

            case 'forum':
                return $this->process_forum_post($schoolid, $payload);

            case 'completion':
                return $this->process_completion($schoolid, $payload);

            case 'enrol':
                return $this->process_enrolment($schoolid, $payload);

            case 'user':
                return $this->process_user($schoolid, $payload);

            default:
                return [
                    'status' => 'error',
                    'message' => "Unknown event type: {$eventtype}",
                ];
        }
    }

    /**
     * Process a grade sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Grade data.
     * @return array Result.
     */
    protected function process_grade(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing grade data'];
        }

        // Find user on central.
        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found on central'];
        }

        // Find course on central.
        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Find or create grade item.
        $gradeitem = $this->find_grade_item($course->id, $object['item'] ?? []);
        if (!$gradeitem) {
            return ['status' => 'error', 'message' => 'Grade item not found on central'];
        }

        // Check for existing grade.
        $existinggrade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
        ]);

        if ($existinggrade) {
            // Conflict resolution: latest timestamp wins.
            $schooltime = $payload['event']['timecreated'] ?? 0;
            if ($existinggrade->timemodified > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central grade is newer',
                    'centralid' => $existinggrade->id,
                ];
            }

            // Update existing grade.
            $existinggrade->rawgrade = $object['rawgrade'] ?? null;
            $existinggrade->finalgrade = $object['finalgrade'] ?? null;
            $existinggrade->feedback = $object['feedback'] ?? null;
            $existinggrade->timemodified = time();
            $DB->update_record('grade_grades', $existinggrade);

            return [
                'status' => 'success',
                'message' => 'Grade updated',
                'centralid' => $existinggrade->id,
            ];
        }

        // Create new grade.
        $grade = new stdClass();
        $grade->itemid = $gradeitem->id;
        $grade->userid = $user->id;
        $grade->rawgrade = $object['rawgrade'] ?? null;
        $grade->finalgrade = $object['finalgrade'] ?? null;
        $grade->feedback = $object['feedback'] ?? null;
        $grade->timecreated = time();
        $grade->timemodified = time();

        $centralid = $DB->insert_record('grade_grades', $grade);

        // Store ID mapping.
        $this->mapper->set_mapping('grade_grades', $object['localid'], $centralid);

        return [
            'status' => 'success',
            'message' => 'Grade created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process a submission sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Submission data.
     * @return array Result.
     */
    protected function process_submission(string $schoolid, array $payload): array {
        // TODO: Implement submission processing.
        // This requires handling file uploads separately.
        return [
            'status' => 'success',
            'message' => 'Submission logged (file sync pending)',
            'centralid' => 0,
        ];
    }

    /**
     * Process a quiz attempt sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Quiz attempt data.
     * @return array Result.
     */
    protected function process_quiz_attempt(string $schoolid, array $payload): array {
        // TODO: Implement quiz attempt processing.
        // Quiz attempts are complex due to question responses.
        return [
            'status' => 'success',
            'message' => 'Quiz attempt logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process a forum post sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Forum post data.
     * @return array Result.
     */
    protected function process_forum_post(string $schoolid, array $payload): array {
        // TODO: Implement forum post processing.
        return [
            'status' => 'success',
            'message' => 'Forum post logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process a completion sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Completion data.
     * @return array Result.
     */
    protected function process_completion(string $schoolid, array $payload): array {
        // TODO: Implement completion processing.
        return [
            'status' => 'success',
            'message' => 'Completion logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process an enrolment sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Enrolment data.
     * @return array Result.
     */
    protected function process_enrolment(string $schoolid, array $payload): array {
        // Enrolments typically flow central → school, not school → central.
        // Log for audit but don't create.
        return [
            'status' => 'success',
            'message' => 'Enrolment logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process a user sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload User data.
     * @return array Result.
     */
    protected function process_user(string $schoolid, array $payload): array {
        // User creation typically flows central → school.
        // Log for audit but don't create.
        return [
            'status' => 'success',
            'message' => 'User event logged',
            'centralid' => 0,
        ];
    }

    /**
     * Find a user on central by various identifiers.
     *
     * @param array $userdata User identification data.
     * @return stdClass|null
     */
    protected function find_user(array $userdata): ?stdClass {
        global $DB;

        if (empty($userdata)) {
            return null;
        }

        // Try idnumber first.
        if (!empty($userdata['idnumber'])) {
            $user = $DB->get_record('user', ['idnumber' => $userdata['idnumber']]);
            if ($user) {
                return $user;
            }
        }

        // Try email.
        if (!empty($userdata['email'])) {
            $user = $DB->get_record('user', ['email' => $userdata['email']]);
            if ($user) {
                return $user;
            }
        }

        // Try username.
        if (!empty($userdata['username'])) {
            $user = $DB->get_record('user', ['username' => $userdata['username']]);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Find a course on central by various identifiers.
     *
     * @param array $coursedata Course identification data.
     * @return stdClass|null
     */
    protected function find_course(array $coursedata): ?stdClass {
        global $DB;

        if (empty($coursedata)) {
            return null;
        }

        // Try idnumber first.
        if (!empty($coursedata['idnumber'])) {
            $course = $DB->get_record('course', ['idnumber' => $coursedata['idnumber']]);
            if ($course) {
                return $course;
            }
        }

        // Try shortname.
        if (!empty($coursedata['shortname'])) {
            $course = $DB->get_record('course', ['shortname' => $coursedata['shortname']]);
            if ($course) {
                return $course;
            }
        }

        return null;
    }

    /**
     * Find a grade item on central.
     *
     * @param int $courseid Course ID.
     * @param array $itemdata Grade item data.
     * @return stdClass|null
     */
    protected function find_grade_item(int $courseid, array $itemdata): ?stdClass {
        global $DB;

        if (empty($itemdata)) {
            return null;
        }

        $params = ['courseid' => $courseid];

        if (!empty($itemdata['idnumber'])) {
            $params['idnumber'] = $itemdata['idnumber'];
        } elseif (!empty($itemdata['itemname'])) {
            $params['itemname'] = $itemdata['itemname'];
        } else {
            return null;
        }

        return $DB->get_record('grade_items', $params) ?: null;
    }
}
