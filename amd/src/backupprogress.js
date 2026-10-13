// This file is part of Moodle - https://moodle.org/
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

/**
 * Progress of the backups in progress in the "Downloads and exports" tab.
 *
 * As the Moodle backup page does, the progress is read every 15 seconds with the Moodle
 * web service core_backup_get_async_backup_progress, only while at least one backup of the
 * page is in progress. The check delay is increased when a request fails. When all the
 * backups of the page are finished (done or failed), the page is reloaded once, to show
 * the download links or the potential errors.
 *
 * @module      report_coursemanager/backupprogress
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    'use strict';

    /** Backup status : finished with error (backup::STATUS_FINISHED_ERR). */
    var STATUS_FINISHED_ERR = 900;

    /** Check delay, as on the Moodle backup page. */
    var CHECK_DELAY = 15000;

    /** Check delay multiplier when a request fails, as on the Moodle backup page. */
    var DELAY_MULTIPLIER = 1.5;

    /** Maximum check delay. */
    var MAX_DELAY = 300000;

    /**
     * Check the progress of the backups of one course.
     *
     * @param {Object} course Backups of a course : cellid, contextid and backupids.
     * @return {Promise} Resolved with true if all the backups of the course are finished.
     */
    var checkCourse = function(course) {
        var request = {
            methodname: 'core_backup_get_async_backup_progress',
            args: {
                backupids: course.backupids,
                contextid: course.contextid,
            },
        };
        return Ajax.call([request])[0].then(function(results) {
            var progress = 0;
            var finished = true;
            results.forEach(function(result) {
                if (result.status >= STATUS_FINISHED_ERR) {
                    progress += 1;
                } else {
                    progress += result.progress;
                    finished = false;
                }
            });
            // The cell is not in the page when its row is on another page of the table.
            var cell = document.getElementById(course.cellid);
            if (cell) {
                cell.textContent = Math.round(100 * progress / results.length) + ' %';
            }
            return finished;
        });
    };

    return {
        /**
         * Start checking the progress of the backups in progress of the page.
         *
         * @param {string} elementId HTML id of the hidden element with the backups to follow (data-pending, JSON).
         */
        init: function(elementId) {
            var element = document.getElementById(elementId);
            if (!element) {
                return;
            }
            var courses = JSON.parse(element.dataset.pending);
            if (courses.length === 0) {
                return;
            }

            var delay = CHECK_DELAY;
            var check = function() {
                Promise.all(courses.map(checkCourse)).then(function(finished) {
                    delay = CHECK_DELAY;
                    if (finished.every(function(done) {
                        return done;
                    })) {
                        // All backups are finished : reload once to show links or errors.
                        window.location.reload();
                        return;
                    }
                    setTimeout(check, delay);
                    return;
                }).catch(function() {
                    delay = Math.min(delay * DELAY_MULTIPLIER, MAX_DELAY);
                    setTimeout(check, delay);
                });
            };
            setTimeout(check, delay);
        },
    };
});
