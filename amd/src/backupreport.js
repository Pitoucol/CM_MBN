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
 * Popup showing the steps of a backup launched from the "Downloads and exports" tab.
 *
 * Uses the Moodle modal (core/modal), so it works the same in Moodle 4.5 (Bootstrap 4)
 * and Moodle 5.x (Bootstrap 5). The user can close it.
 *
 * @module      report_coursemanager/backupreport
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/modal'], function(ModalModule) {
    'use strict';

    // Moodle ES modules are loaded as AMD modules with a "default" export.
    var Modal = ModalModule.default || ModalModule;

    return {
        /**
         * Show the popup with the content of a hidden element of the page.
         *
         * @param {string} elementId HTML id of the hidden element (title in data-title, steps as content).
         */
        init: function(elementId) {
            var element = document.getElementById(elementId);
            if (!element) {
                return;
            }
            Modal.create({
                title: element.dataset.title,
                body: element.innerHTML,
                show: true,
                removeOnClose: true,
            });
        },
    };
});
