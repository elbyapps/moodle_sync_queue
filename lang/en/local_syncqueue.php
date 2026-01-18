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

/**
 * Language strings for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Sync Queue';
$string['privacy:metadata'] = 'The Sync Queue plugin stores event data for synchronization between central and school servers.';

// Settings - General.
$string['settings'] = 'Sync Queue Settings';
$string['enabled'] = 'Enable sync queue';
$string['enabled_desc'] = 'When enabled, the sync system will be active based on the selected mode.';
$string['mode'] = 'Operation mode';
$string['mode_desc'] = 'Select whether this instance operates as the central server or a school instance.';
$string['mode_school'] = 'School (syncs to central)';
$string['mode_central'] = 'Central (receives from schools)';

// Settings - School mode.
$string['schoolsettings'] = 'School Mode Settings';
$string['schoolsettings_desc'] = 'Configure these settings when running in school mode.';
$string['schoolid'] = 'School identifier';
$string['schoolid_desc'] = 'Unique identifier for this school instance (e.g., school_kigali_001).';
$string['centralserver'] = 'Central server URL';
$string['centralserver_desc'] = 'The URL of the central Moodle server to sync with.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'Secret key for authenticating with the central server.';
$string['syncinterval'] = 'Sync interval';
$string['syncinterval_desc'] = 'How often to attempt synchronization.';

// Settings - Central mode.
$string['centralsettings'] = 'Central Mode Settings';
$string['centralsettings_desc'] = 'Configure these settings when running in central mode.';
$string['allowregistration'] = 'Allow school registration';
$string['allowregistration_desc'] = 'When enabled, new schools can register using the registration secret.';
$string['registrationsecret'] = 'Registration secret';
$string['registrationsecret_desc'] = 'Secret key that schools must provide to register. Share this securely with school administrators.';

// Settings - Common.
$string['commonsettings'] = 'Common Settings';
$string['commonsettings_desc'] = 'Settings that apply to both central and school modes.';
$string['batchsize'] = 'Batch size';
$string['batchsize_desc'] = 'Maximum number of items to sync in a single batch.';
$string['maxretries'] = 'Maximum retries';
$string['maxretries_desc'] = 'Maximum number of retry attempts for failed sync items.';
$string['maxfilesize'] = 'Maximum file size';
$string['maxfilesize_desc'] = 'Maximum file size (in bytes) to include inline with sync data. Larger files are synced separately.';
$string['debug'] = 'Debug mode';
$string['debug_desc'] = 'Enable verbose logging for troubleshooting sync issues.';

// Queue statuses.
$string['status_pending'] = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_synced'] = 'Synced';
$string['status_failed'] = 'Failed';
$string['status_conflict'] = 'Conflict';

// Event types.
$string['eventtype_grade'] = 'Grade change';
$string['eventtype_submission'] = 'Assignment submission';
$string['eventtype_quiz'] = 'Quiz attempt';
$string['eventtype_forum'] = 'Forum post';
$string['eventtype_user'] = 'User update';
$string['eventtype_enrol'] = 'Enrollment change';
$string['eventtype_completion'] = 'Activity completion';

// Tasks.
$string['task_processqueue'] = 'Process sync queue';
$string['task_downloadupdates'] = 'Download updates from central server';
$string['task_cleanup'] = 'Clean up old sync records';

// Admin page.
$string['queuestatus'] = 'Queue status';
$string['pendingitems'] = 'Pending items';
$string['faileditem'] = 'Failed items';
$string['lastsync'] = 'Last successful sync';
$string['syncnow'] = 'Sync now';
$string['viewqueue'] = 'View queue';
$string['clearfailed'] = 'Clear failed items';

// Errors.
$string['error_noconnection'] = 'Cannot connect to central server';
$string['error_authfailed'] = 'Authentication failed';
$string['error_syncfailed'] = 'Synchronization failed: {$a}';
$string['error_invalidresponse'] = 'Invalid response from central server';
$string['error_conflict'] = 'Data conflict detected for {$a}';
