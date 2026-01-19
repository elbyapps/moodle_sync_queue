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
use moodle_exception;

/**
 * Client for communicating with the central sync server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_client {

    /** @var string Central server URL */
    protected string $serverurl;

    /** @var string API key for authentication */
    protected string $apikey;

    /** @var string School identifier */
    protected string $schoolid;

    /** @var int Connection timeout in seconds */
    protected int $timeout = 30;

    /**
     * Constructor.
     *
     * @throws moodle_exception If not properly configured.
     */
    public function __construct() {
        $this->serverurl = get_config('local_syncqueue', 'centralserver');
        $this->apikey = get_config('local_syncqueue', 'apikey');
        $this->schoolid = get_config('local_syncqueue', 'schoolid');

        if (empty($this->serverurl) || empty($this->apikey) || empty($this->schoolid)) {
            throw new moodle_exception('error_notconfigured', 'local_syncqueue');
        }
    }

    /**
     * Check if the central server is reachable.
     *
     * @return bool True if server is reachable.
     */
    public function check_connection(): bool {
        try {
            $response = $this->request('GET', '/webservice/rest/server.php', [
                'wstoken' => $this->apikey,
                'wsfunction' => 'local_syncqueue_status',
                'moodlewsrestformat' => 'json',
                'schoolid' => $this->schoolid,
            ]);
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Upload queue items to the central server.
     *
     * @param array $items Array of queue items to upload.
     * @return array Response from server with results per item.
     * @throws moodle_exception On communication failure.
     */
    public function upload(array $items): array {
        if (empty($items)) {
            return [];
        }

        $payload = [
            'schoolid' => $this->schoolid,
            'timestamp' => time(),
            'items' => [],
        ];

        foreach ($items as $item) {
            $payload['items'][] = [
                'id' => $item->id,
                'eventtype' => $item->eventtype,
                'eventname' => $item->eventname,
                'payload' => json_decode($item->payload, true),
                'priority' => $item->priority,
                'timecreated' => $item->timecreated,
            ];
        }

        $response = $this->request('POST', '/webservice/rest/server.php', [
            'wstoken' => $this->apikey,
            'wsfunction' => 'local_syncqueue_upload',
            'moodlewsrestformat' => 'json',
            'data' => json_encode($payload),
        ]);

        return $response['results'] ?? [];
    }

    /**
     * Upload a file to the central server.
     *
     * @param stdClass $filerecord File queue record.
     * @param \stored_file $file The file to upload.
     * @return bool True on success.
     * @throws moodle_exception On failure.
     */
    public function upload_file(stdClass $filerecord, \stored_file $file): bool {
        $url = $this->serverurl . '/webservice/upload.php';

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 120, // Longer timeout for file uploads.
            'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
        ]);

        // Create temporary file for upload.
        $tempdir = make_temp_directory('syncqueue');
        $tempfile = $tempdir . '/' . $file->get_contenthash();
        $file->copy_content_to($tempfile);

        try {
            $params = [
                'token' => $this->apikey,
                'schoolid' => $this->schoolid,
                'contenthash' => $file->get_contenthash(),
                'filename' => $file->get_filename(),
                'file' => new \CURLFile($tempfile, $file->get_mimetype(), $file->get_filename()),
            ];

            $response = $curl->post($url, $params);
            $result = json_decode($response, true);

            return isset($result['success']) && $result['success'];
        } finally {
            @unlink($tempfile);
        }
    }

    /**
     * Download updates from the central server.
     *
     * @param int $since Timestamp to get updates since.
     * @return array Updates from the server.
     * @throws moodle_exception On communication failure.
     */
    public function download(int $since = 0): array {
        $response = $this->request('GET', '/webservice/rest/server.php', [
            'wstoken' => $this->apikey,
            'wsfunction' => 'local_syncqueue_download',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'since' => $since,
        ]);

        return $response['updates'] ?? [];
    }

    /**
     * Report sync completion to the central server.
     *
     * @param array $results Results of the sync operation.
     */
    public function report_sync(array $results): void {
        $this->request('POST', '/webservice/rest/server.php', [
            'wstoken' => $this->apikey,
            'wsfunction' => 'local_syncqueue_report',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'results' => json_encode($results),
        ]);
    }

    /**
     * Make an HTTP request to the central server.
     *
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array $params Request parameters.
     * @return array Decoded response.
     * @throws moodle_exception On failure.
     */
    protected function request(string $method, string $endpoint, array $params): array {
        $url = rtrim($this->serverurl, '/') . $endpoint;

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        if ($method === 'GET') {
            $response = $curl->get($url, $params);
        } else {
            $response = $curl->post($url, $params);
        }

        $info = $curl->get_info();
        $errno = $curl->get_errno();
        $error = $curl->error;

        // Check for curl errors or blocked URLs.
        if ($errno || $response === false) {
            throw new moodle_exception('error_noconnection', 'local_syncqueue', '', $error ?: 'Connection failed');
        }

        // Check HTTP status code (handle case where it might not be set).
        $httpcode = $info['http_code'] ?? 0;
        if ($httpcode !== 200) {
            if ($httpcode === 0) {
                throw new moodle_exception('error_noconnection', 'local_syncqueue', '', 'URL may be blocked or unreachable');
            }
            throw new moodle_exception('error_invalidresponse', 'local_syncqueue', '', $httpcode);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new moodle_exception('error_invalidresponse', 'local_syncqueue');
        }

        if (isset($decoded['exception'])) {
            throw new moodle_exception('error_syncfailed', 'local_syncqueue', '', $decoded['message'] ?? 'Unknown error');
        }

        return $decoded;
    }
}
