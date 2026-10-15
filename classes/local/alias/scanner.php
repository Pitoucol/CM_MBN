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
 * Finds the files of a course that are aliases (file references), and tells which ones would be lost
 * when the course is restored from a backup (mbz) on another site.
 *
 * What the restore does with an alias (restore_process_file_aliases_queue, Moodle 4.5 and 5.1):
 *  - repository "Server files", "Legacy course files" or "Content bank": the alias is recreated only if its
 *    source file is also in the backup; otherwise, on another site, it is dropped ("referenced file not included");
 *  - "Private files" or any external repository: on another site, it is dropped ("restoring at another site").
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scanner {

    /** Source file located in the same course: the alias is restored, pointing to the restored copy. */
    const KIND_INTERNAL = 'internal';
    /** Server file, legacy course file or content bank file located outside the course. */
    const KIND_OUTSIDE = 'outside';
    /** File from the private files of a user. */
    const KIND_PRIVATE = 'private';
    /** Any other repository (Dropbox, Google Drive...), including repositories that are not installed any more. */
    const KIND_EXTERNAL = 'external';
    /** Reference that cannot be read (corrupted, or the files_reference row is missing). */
    const KIND_BROKEN = 'broken';

    /** Only the aliases that would not be restored. */
    const SCOPE_LOST = 'lost';
    /** Every alias, including those whose source is in the course. */
    const SCOPE_ALL = 'all';

    /** Type of file: image. */
    const TYPE_IMAGE = 'image';
    /** Type of file: H5P package. */
    const TYPE_H5P = 'h5p';
    /** Type of file: any other file. */
    const TYPE_FILE = 'file';

    /** Name of the report storing, for each course, the number of aliases lost by a backup (report_coursemanager_reports). */
    const REPORT = 'alias';

    /** @var string[] Repository types whose reference is a file of this Moodle site (file_storage::pack_reference). */
    const SITE_FILE_REPOSITORIES = ['local', 'coursefiles', 'contentbank'];

    /**
     * Ids of the contexts of a course: the course, its activities and blocks. These are the contexts whose
     * files are included in the backup of the course.
     *
     * @param int $courseid
     * @return array context ids as keys
     */
    public static function course_context_ids(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid);
        $ids = $DB->get_fieldset_select('context', 'id', 'id = :id OR ' . $DB->sql_like('path', ':path'),
            ['id' => $context->id, 'path' => $DB->sql_like_escape($context->path) . '/%']);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * All aliases stored in the contexts of a course (question banks of the course and of its activities
     * included, because their files are stored in these contexts). Every type of file: images, H5P packages
     * and other files.
     *
     * @param int $courseid
     * @param bool $backuponly true keeps only the files of a course backup without user data
     *     (see report_coursemanager_get_backup_files_where())
     * @return \stdClass[] list, see {@see classify()} for the properties
     */
    public static function scan_course(int $courseid, bool $backuponly = false): array {
        global $CFG, $DB;
        $context = \context_course::instance($courseid);
        $coursecontexts = self::course_context_ids($courseid);

        if ($backuponly) {
            require_once($CFG->dirroot . '/report/coursemanager/lib.php');
            [$where, $params] = report_coursemanager_get_backup_files_where($context);
        } else {
            $where = "f.filename <> :dot AND (ctx.id = :ctxid OR " . $DB->sql_like('ctx.path', ':path') . ")";
            $params = ['dot' => '.', 'ctxid' => $context->id, 'path' => $DB->sql_like_escape($context->path) . '/%'];
        }

        $sql = "SELECT f.id, f.contenthash, f.pathnamehash, f.contextid, f.component, f.filearea, f.itemid,
                       f.filepath, f.filename, f.filesize, f.mimetype, f.status, f.referencefileid,
                       fr.repositoryid, fr.reference, fr.lastsync AS referencelastsync,
                       r.type AS repotype, ri.name AS reponame
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
             LEFT JOIN {files_reference} fr ON fr.id = f.referencefileid
             LEFT JOIN {repository_instances} ri ON ri.id = fr.repositoryid
             LEFT JOIN {repository} r ON r.id = ri.typeid
                 WHERE f.referencefileid IS NOT NULL
                   AND $where
              ORDER BY f.contextid, f.component, f.filearea, f.itemid, f.filepath, f.filename";

        $aliases = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $aliases[] = self::classify($row, $coursecontexts);
        }
        $rs->close();
        return $aliases;
    }

    /**
     * Describes where the source of an alias is, and whether it will be restored.
     *
     * Adds to the record: kind (KIND_*), lost (bool), type (TYPE_*), isimage (bool), source (array from
     * file_storage::unpack_reference, or null), sourceexists (bool or null when unknown).
     *
     * @param \stdClass $row record with the files columns and repositoryid, reference, repotype, reponame
     * @param array $coursecontexts ids of the contexts included in the backup of the course, as keys
     * @return \stdClass the same record, completed
     */
    public static function classify(\stdClass $row, array $coursecontexts): \stdClass {
        $row->source = null;
        $row->sourceexists = null;
        $row->isimage = \file_mimetype_in_typegroup((string)$row->mimetype, 'image')
            || \file_extension_in_typegroup((string)$row->filename, 'image');
        if ($row->isimage) {
            $row->type = self::TYPE_IMAGE;
        } else if (strtolower(pathinfo((string)$row->filename, PATHINFO_EXTENSION)) === 'h5p') {
            $row->type = self::TYPE_H5P;
        } else {
            $row->type = self::TYPE_FILE;
        }

        if (empty($row->repositoryid) || $row->reference === null || $row->reference === '') {
            $row->kind = self::KIND_BROKEN;
        } else if (in_array((string)$row->repotype, self::SITE_FILE_REPOSITORIES, true)) {
            $source = self::unpack((string)$row->reference);
            if ($source === null) {
                $row->kind = self::KIND_BROKEN;
            } else {
                $row->source = $source;
                $row->kind = isset($coursecontexts[$source['contextid']]) ? self::KIND_INTERNAL : self::KIND_OUTSIDE;
                $row->sourceexists = get_file_storage()->file_exists($source['contextid'], $source['component'],
                    $source['filearea'], $source['itemid'], $source['filepath'], $source['filename']);
            }
        } else if ((string)$row->repotype === 'user') {
            $row->kind = self::KIND_PRIVATE;
        } else {
            $row->kind = self::KIND_EXTERNAL;
        }
        $row->lost = $row->kind !== self::KIND_INTERNAL;
        return $row;
    }

    /**
     * Reads the reference of a file of this site.
     *
     * @param string $reference
     * @return array|null contextid, component, filearea, itemid, filepath, filename; null if unreadable
     */
    protected static function unpack(string $reference): ?array {
        try {
            $params = \file_storage::unpack_reference($reference);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($params) || empty($params['contextid']) || empty($params['filename'])) {
            return null;
        }
        return [
            'contextid' => (int)$params['contextid'],
            'component' => (string)($params['component'] ?? ''),
            'filearea' => (string)($params['filearea'] ?? ''),
            'itemid' => (int)($params['itemid'] ?? 0),
            'filepath' => (string)($params['filepath'] ?? '/'),
            'filename' => (string)$params['filename'],
        ];
    }

    /**
     * Keeps the aliases that fall in a scope.
     *
     * @param \stdClass[] $aliases from scan_course()
     * @param string $scope SCOPE_LOST or SCOPE_ALL
     * @return \stdClass[]
     */
    public static function filter(array $aliases, string $scope): array {
        if ($scope === self::SCOPE_ALL) {
            return array_values($aliases);
        }
        return array_values(array_filter($aliases, function(\stdClass $alias): bool {
            return $alias->lost;
        }));
    }

    /**
     * Counts the aliases.
     *
     * @param \stdClass[] $aliases
     * @return array total, lost, internal, missingsource, one counter per kind and one per type
     */
    public static function summarise(array $aliases): array {
        $s = ['total' => 0, 'lost' => 0, 'internal' => 0, 'missingsource' => 0];
        foreach ([self::KIND_INTERNAL, self::KIND_OUTSIDE, self::KIND_PRIVATE, self::KIND_EXTERNAL,
                self::KIND_BROKEN] as $kind) {
            $s[$kind] = 0;
        }
        foreach ([self::TYPE_IMAGE, self::TYPE_H5P, self::TYPE_FILE] as $type) {
            $s['type_' . $type] = 0;
        }
        foreach ($aliases as $alias) {
            $s['total']++;
            $s[$alias->kind]++;
            $s['type_' . $alias->type]++;
            if ($alias->lost) {
                $s['lost']++;
            }
            if ($alias->sourceexists === false) {
                $s['missingsource']++;
            }
        }
        return $s;
    }

    /**
     * Counts the aliases of a course that a backup without user data would lose, and stores the result
     * in report_coursemanager_reports, for the "Downloads and exports" tab.
     *
     * Called by the nightly course content task, and by the alias page after a conversion or an undo.
     *
     * @param int $courseid
     * @return int number of aliases that would be lost
     */
    public static function save_report(int $courseid): int {
        global $DB;
        $lost = count(self::filter(self::scan_course($courseid, true), self::SCOPE_LOST));
        $data = (object)['course' => $courseid, 'report' => self::REPORT, 'detail' => $lost];
        $existing = $DB->get_record('report_coursemanager_reports', ['course' => $courseid, 'report' => self::REPORT],
            'id', IGNORE_MULTIPLE);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('report_coursemanager_reports', $data);
        } else {
            $DB->insert_record('report_coursemanager_reports', $data);
        }
        return $lost;
    }
}
