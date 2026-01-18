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
 * Manager for registered schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class school_manager {

    /** @var string Table name */
    protected const TABLE = 'local_syncqueue_schools';

    /**
     * Check if a school exists.
     *
     * @param string $schoolid School identifier.
     * @return bool
     */
    public function school_exists(string $schoolid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['schoolid' => $schoolid]);
    }

    /**
     * Get a school by ID.
     *
     * @param string $schoolid School identifier.
     * @return stdClass|null
     */
    public function get_school(string $schoolid): ?stdClass {
        global $DB;
        $school = $DB->get_record(self::TABLE, ['schoolid' => $schoolid]);
        return $school ?: null;
    }

    /**
     * Get all registered schools.
     *
     * @param string $status Optional status filter.
     * @return array
     */
    public function get_all_schools(string $status = ''): array {
        global $DB;

        $params = [];
        if (!empty($status)) {
            $params['status'] = $status;
        }

        return $DB->get_records(self::TABLE, $params, 'name ASC');
    }

    /**
     * Register a new school.
     *
     * @param string $schoolid School identifier.
     * @param string $name School name.
     * @param string $contactemail Contact email.
     * @param string $description Description.
     * @return string The generated API key (unhashed).
     */
    public function register_school(
        string $schoolid,
        string $name,
        string $contactemail = '',
        string $description = ''
    ): string {
        global $DB;

        // Generate API key.
        $apikey = $this->generate_apikey();
        $apikeyhash = $this->hash_apikey($apikey);

        $now = time();
        $school = new stdClass();
        $school->schoolid = $schoolid;
        $school->name = $name;
        $school->description = $description;
        $school->apikey = $apikeyhash;
        $school->status = 'active';
        $school->contactemail = $contactemail;
        $school->totalsynced = 0;
        $school->timecreated = $now;
        $school->timemodified = $now;

        $DB->insert_record(self::TABLE, $school);

        return $apikey; // Return unhashed key (only time it's available).
    }

    /**
     * Verify an API key for a school.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key to verify.
     * @return bool
     */
    public function verify_apikey(string $schoolid, string $apikey): bool {
        global $DB;

        $school = $this->get_school($schoolid);
        if (!$school) {
            return false;
        }

        return $this->check_apikey($apikey, $school->apikey);
    }

    /**
     * Regenerate API key for a school.
     *
     * @param string $schoolid School identifier.
     * @return string New API key (unhashed).
     */
    public function regenerate_apikey(string $schoolid): string {
        global $DB;

        $apikey = $this->generate_apikey();
        $apikeyhash = $this->hash_apikey($apikey);

        $DB->set_field(self::TABLE, 'apikey', $apikeyhash, ['schoolid' => $schoolid]);
        $DB->set_field(self::TABLE, 'timemodified', time(), ['schoolid' => $schoolid]);

        return $apikey;
    }

    /**
     * Update school status.
     *
     * @param string $schoolid School identifier.
     * @param string $status New status.
     */
    public function update_status(string $schoolid, string $status): void {
        global $DB;

        $validstatuses = ['active', 'suspended', 'pending'];
        if (!in_array($status, $validstatuses)) {
            throw new \invalid_parameter_exception('Invalid status: ' . $status);
        }

        $DB->set_field(self::TABLE, 'status', $status, ['schoolid' => $schoolid]);
        $DB->set_field(self::TABLE, 'timemodified', time(), ['schoolid' => $schoolid]);
    }

    /**
     * Update sync statistics after a sync.
     *
     * @param string $schoolid School identifier.
     * @param int $itemcount Number of items synced.
     */
    public function update_sync_stats(string $schoolid, int $itemcount): void {
        global $DB;

        $school = $this->get_school($schoolid);
        if (!$school) {
            return;
        }

        $school->lastsynced = time();
        $school->lastsyncitems = $itemcount;
        $school->totalsynced = $school->totalsynced + $itemcount;
        $school->timemodified = time();

        $DB->update_record(self::TABLE, $school);
    }

    /**
     * Delete a school registration.
     *
     * @param string $schoolid School identifier.
     */
    public function delete_school(string $schoolid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['schoolid' => $schoolid]);
    }

    /**
     * Get schools that haven't synced recently.
     *
     * @param int $threshold Seconds since last sync.
     * @return array Schools that are overdue for sync.
     */
    public function get_overdue_schools(int $threshold = 86400): array {
        global $DB;

        $cutoff = time() - $threshold;

        return $DB->get_records_select(
            self::TABLE,
            'status = :status AND (lastsynced IS NULL OR lastsynced < :cutoff)',
            ['status' => 'active', 'cutoff' => $cutoff],
            'lastsynced ASC'
        );
    }

    /**
     * Generate a new API key.
     *
     * @return string 64 character hex string.
     */
    protected function generate_apikey(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash an API key for storage.
     *
     * @param string $apikey Plain API key.
     * @return string Hashed key.
     */
    protected function hash_apikey(string $apikey): string {
        return hash('sha256', $apikey);
    }

    /**
     * Check if an API key matches a hash.
     *
     * @param string $apikey Plain API key.
     * @param string $hash Stored hash.
     * @return bool
     */
    protected function check_apikey(string $apikey, string $hash): bool {
        return hash_equals($hash, $this->hash_apikey($apikey));
    }
}
