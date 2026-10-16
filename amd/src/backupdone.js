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
 * "Backup done" checkboxes of the "Downloads and exports" tab : the state is saved with a web service when
 * a checkbox changes, without reloading the page. The row class (backup-done / backup-todo) is updated for the
 * filter buttons, and the sort value of the cell for the table sort.
 *
 * @module      report_coursemanager/backupdone
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    /**
     * Shows a state in the row of a checkbox.
     *
     * @param {HTMLInputElement} checkbox
     * @param {boolean} done
     * @param {string} info Date and user, empty when not done.
     */
    var showState = function(checkbox, done, info) {
        checkbox.checked = done;
        var cell = checkbox.closest('td');
        var row = checkbox.closest('tr');
        var infoEl = cell ? cell.querySelector('.coursemanager-backupdone-info') : null;
        if (infoEl) {
            infoEl.textContent = info;
        }
        if (row) {
            row.classList.toggle('backup-done', done);
            row.classList.toggle('backup-todo', !done);
        }
        if (cell) {
            cell.setAttribute('data-order', done ? 1 : 0);
            // Sort value cached by DataTables.
            var table = cell.closest('table');
            if (table && $.fn.dataTable && $.fn.dataTable.isDataTable(table)) {
                $(table).DataTable().row(row).invalidate('dom');
            }
        }
    };

    return {
        /**
         * Saves the state of a course when its checkbox changes.
         */
        init: function() {
            document.addEventListener('change', function(e) {
                var checkbox = e.target;
                if (!checkbox.classList || !checkbox.classList.contains('coursemanager-backupdone')) {
                    return;
                }
                var done = checkbox.checked;
                checkbox.disabled = true;
                Ajax.call([{
                    methodname: 'report_coursemanager_set_backup_done',
                    args: {courseid: parseInt(checkbox.dataset.courseid, 10), done: done},
                }])[0].then(function(result) {
                    showState(checkbox, result.done, result.info);
                    checkbox.disabled = false;
                    return result;
                }).catch(function(error) {
                    // Not saved : back to the previous state.
                    checkbox.checked = !done;
                    checkbox.disabled = false;
                    Notification.exception(error);
                });
            });
        },
    };
});
