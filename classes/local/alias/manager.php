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

namespace report_coursemanager\local\alias;

/**
 * Turns aliases into real files (same place, same name, same content) and undoes it.
 *
 * Because the file keeps its context, component, file area, item id, path and name, no text has to be
 * rewritten: every @@PLUGINFILE@@ link keeps working.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** @var int files.status of a file whose source is missing (see stored_file::set_missingsource). */
    const STATUS_MISSING = 666;

    /**
     * Converts aliases into real files, keeping what is needed to undo.
     *
     * @param \stdClass[] $aliases from scanner::scan_course() (already filtered by the caller)
     * @param int $courseid
     * @param int $userid
     * @param string $origin web|cli
     * @param string $scope scanner::SCOPE_* the caller used to choose the aliases (kept in the history)
     * @return \stdClass the run record, with a "failed" list of [alias, language string key]
     */
    public function convert(array $aliases, int $courseid, int $userid, string $origin = 'web',
            string $scope = scanner::SCOPE_LOST): \stdClass {
        global $DB;
        \core_php_time_limit::raise();

        $run = (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'origin' => $origin,
            'scope' => $scope,
            'aliases' => 0,
            'failures' => 0,
            'timecreated' => time(),
            'timereverted' => 0,
        ];
        $failed = [];
        $transaction = $DB->start_delegated_transaction();
        $run->id = $DB->insert_record('report_coursemanager_arun', $run);
        foreach ($aliases as $alias) {
            $error = $this->convert_one($alias, (int)$run->id);
            if ($error === null) {
                $run->aliases++;
            } else {
                $run->failures++;
                $failed[] = [$alias, $error];
            }
        }
        $DB->update_record('report_coursemanager_arun', (object)['id' => $run->id, 'aliases' => $run->aliases,
            'failures' => $run->failures]);
        $transaction->allow_commit();
        $run->failed = $failed;
        return $run;
    }

    /**
     * Converts one alias.
     *
     * @param \stdClass $alias
     * @param int $runid
     * @return string|null null when done, otherwise the key of the language string explaining the failure
     */
    protected function convert_one(\stdClass $alias, int $runid): ?string {
        global $DB;
        $fs = get_file_storage();

        $record = $DB->get_record('files', ['id' => $alias->id]);
        if (!$record || empty($record->referencefileid)) {
            return 'alias_err_notalias';
        }

        // Preferred path: the file API. It needs the repository plugin of the alias to be loadable.
        try {
            $file = $fs->get_file_by_id($record->id);
        } catch (\Throwable $e) {
            $file = false;
        }
        if ($file && !$file->is_external_file()) {
            return 'alias_err_notalias';
        }
        if ($file) {
            // Best effort: take the content currently served for the alias.
            try {
                $file->sync_external_file();
            } catch (\Throwable $e) {
                debugging('report_coursemanager: synchronisation failed for file ' . $record->id . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
            $record = $DB->get_record('files', ['id' => $record->id], '*', MUST_EXIST);
        }

        // The content must exist in the file pool, otherwise the copy would be empty.
        $probe = clone($record);
        $probe->repositoryid = null;
        if (!$fs->get_file_system()->is_file_readable_remotely_by_storedfile(new \stored_file($fs, $probe))) {
            return 'alias_err_unreadable';
        }

        $reference = $DB->get_record('files_reference', ['id' => $record->referencefileid]);
        $log = (object)[
            'runid' => $runid,
            'fileid' => (int)$record->id,
            'contextid' => (int)$record->contextid,
            'component' => $record->component,
            'filearea' => $record->filearea,
            'itemid' => (int)$record->itemid,
            'filepath' => $record->filepath,
            'filename' => $record->filename,
            'contenthash' => $record->contenthash,
            'repositoryid' => $reference ? (int)$reference->repositoryid : 0,
            'reference' => $reference ? $reference->reference : null,
            'referencelastsync' => $reference ? $reference->lastsync : null,
            'oldstatus' => (int)$record->status,
            'mode' => $file ? 'api' : 'db',
            'reverted' => 0,
        ];

        if ($file) {
            $file->delete_reference();
        } else {
            $this->unlink_in_database($record);
        }
        // An alias whose source was deleted is flagged as missing; now it is a regular file with its content.
        if ((int)$record->status === self::STATUS_MISSING) {
            $DB->set_field('files', 'status', 0, ['id' => $record->id]);
        }
        $DB->insert_record('report_coursemanager_alog', $log);
        return null;
    }

    /**
     * Same as stored_file::delete_reference(), for an alias whose repository plugin cannot be loaded.
     *
     * @param \stdClass $record files record
     */
    protected function unlink_in_database(\stdClass $record): void {
        global $DB;
        if ($DB->count_records('files', ['referencefileid' => $record->referencefileid]) == 1) {
            $DB->delete_records('files_reference', ['id' => $record->referencefileid]);
        }
        $DB->set_field('files', 'referencefileid', null, ['id' => $record->id]);
    }

    /**
     * Turns the converted files of a run back into aliases. A file modified since the conversion is left untouched.
     *
     * @param int $runid
     * @return array ['restored' => int, 'conflicts' => int]
     */
    public function undo(int $runid): array {
        global $DB;
        \core_php_time_limit::raise();
        $DB->get_record('report_coursemanager_arun', ['id' => $runid], 'id', MUST_EXIST);
        $restored = 0;
        $conflicts = 0;
        $transaction = $DB->start_delegated_transaction();
        $rs = $DB->get_recordset('report_coursemanager_alog', ['runid' => $runid, 'reverted' => 0], 'id DESC');
        foreach ($rs as $log) {
            $file = $DB->get_record('files', ['id' => $log->fileid]);
            if (!$file || !empty($file->referencefileid) || $file->contenthash !== $log->contenthash
                    || $log->reference === null || !$DB->record_exists('repository_instances', ['id' => $log->repositoryid])) {
                $conflicts++;
                continue;
            }
            $hash = sha1($log->reference);
            $refid = $DB->get_field('files_reference', 'id', ['repositoryid' => $log->repositoryid,
                'referencehash' => $hash], IGNORE_MULTIPLE);
            if (!$refid) {
                $refid = $DB->insert_record('files_reference', (object)['repositoryid' => $log->repositoryid,
                    'reference' => $log->reference, 'referencehash' => $hash, 'lastsync' => $log->referencelastsync]);
            }
            $DB->set_field('files', 'referencefileid', $refid, ['id' => $file->id]);
            if ((int)$log->oldstatus !== (int)$file->status) {
                $DB->set_field('files', 'status', $log->oldstatus, ['id' => $file->id]);
            }
            $DB->set_field('report_coursemanager_alog', 'reverted', 1, ['id' => $log->id]);
            $restored++;
        }
        $rs->close();
        if ($conflicts === 0) {
            $DB->set_field('report_coursemanager_arun', 'timereverted', time(), ['id' => $runid]);
        }
        $transaction->allow_commit();
        return ['restored' => $restored, 'conflicts' => $conflicts];
    }
}
