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

namespace report_coursemanager\local\config;

/**
 * Export and import of the settings of the plugin in JSON, to copy a configuration to other sites or to fix a wrong
 * setting on many sites with a script (cli/config.php).
 *
 * The settings are read from the admin tree of the plugin, so that every setting of the settings page is handled,
 * and written with the admin settings API : each value is validated as in the settings page, and each change is
 * recorded in the config changes report.
 *
 * Ids are not the same on every site : roles are written with their short name, the trash category with its id
 * number when it has one, otherwise with its path ("Category / Subcategory").
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_json {

    /** Component of the settings. */
    const PLUGIN = 'report_coursemanager';

    /** @var string[] Settings containing one role id or a list of role ids. */
    const ROLE_SETTINGS = ['teacher_role_dashboard', 'other_teacher_role_dashboard', 'student_role_report',
        'delete_send_mail'];

    /** @var string[] Settings containing a course category id. */
    const CATEGORY_SETTINGS = ['category_bin'];

    /**
     * Settings of the plugin, from the admin tree (headings and descriptions excluded).
     *
     * @return \admin_setting[] indexed by setting name
     */
    public static function get_settings(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $settings = [];
        self::collect(admin_get_root(false, true), $settings);
        ksort($settings);
        return $settings;
    }

    /**
     * Walks the admin tree and keeps the settings of the plugin.
     *
     * @param \part_of_admin_tree $node
     * @param \admin_setting[] $settings
     */
    protected static function collect($node, array &$settings): void {
        if ($node instanceof \admin_category) {
            foreach ($node->children as $child) {
                self::collect($child, $settings);
            }
        } else if ($node instanceof \admin_settingpage) {
            foreach ($node->settings as $setting) {
                if ($setting->plugin === self::PLUGIN && !($setting instanceof \admin_setting_heading)
                        && !($setting instanceof \admin_setting_description)) {
                    $settings[$setting->name] = $setting;
                }
            }
        }
    }

    /**
     * Current configuration, with portable values for roles and categories.
     *
     * @return array plugin, version, settings
     * @throws \moodle_exception when a role or the category of a setting does not exist any more
     */
    public static function export(): array {
        $values = [];
        foreach (self::get_settings() as $name => $setting) {
            $value = $setting->get_setting();
            if ($value === null) {
                // Never saved : the default value is used by the plugin.
                $value = $setting->get_defaultsetting();
            }
            $values[$name] = self::to_portable($name, $value);
        }
        return [
            'plugin' => self::PLUGIN,
            'version' => (int)get_config(self::PLUGIN, 'version'),
            'settings' => $values,
        ];
    }

    /**
     * Checks a configuration and applies it unless $dryrun : nothing is written if a value is wrong.
     *
     * Settings missing from the configuration are not changed, so that a file can contain only the settings to fix.
     *
     * @param array $data decoded JSON : an export (with "settings") or only the settings
     * @param bool $dryrun true to check and list the changes without writing them
     * @return array changes (list of [name, old value, new value], portable values), errors, warnings (strings),
     *     written (number of settings written)
     */
    public static function import(array $data, bool $dryrun): array {
        $result = ['changes' => [], 'errors' => [], 'warnings' => [], 'written' => 0];
        if (isset($data['plugin']) && $data['plugin'] !== self::PLUGIN) {
            $result['errors'][] = get_string('configjson_wrongplugin', self::PLUGIN, s((string)$data['plugin']));
            return $result;
        }
        $values = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : $data;
        unset($values['plugin'], $values['version']);

        $settings = self::get_settings();
        $towrite = [];
        foreach ($values as $name => $portable) {
            if (!isset($settings[$name])) {
                $result['warnings'][] = get_string('configjson_unknownsetting', self::PLUGIN, s($name));
                continue;
            }
            $setting = $settings[$name];
            try {
                $value = self::from_portable($name, $portable);
            } catch (\moodle_exception $e) {
                $result['errors'][] = $name . ' : ' . $e->getMessage();
                continue;
            }
            $error = self::validate($setting, $value);
            if ($error !== '') {
                $result['errors'][] = $name . ' : ' . $error;
                continue;
            }
            $current = $setting->get_setting();
            if (self::normalise($current) === self::normalise($value)) {
                continue;
            }
            $towrite[$name] = $value;
            $result['changes'][] = [$name, $current === null ? null : self::to_portable($name, $current, false),
                self::to_portable($name, $value, false)];
        }

        // All or nothing : a configuration applied in part would be harder to fix.
        if ($dryrun || $result['errors']) {
            return $result;
        }
        foreach ($towrite as $name => $value) {
            $setting = $settings[$name];
            $error = $setting->write_setting($value);
            if ($error !== '') {
                $result['errors'][] = $name . ' : ' . $error;
                continue;
            }
            $result['written']++;
            // As in the settings page.
            $callback = $setting->updatedcallback;
            if (!empty($callback) && is_callable($callback)) {
                $callback($setting->get_full_name());
            }
        }
        return $result;
    }

    /**
     * Checks a value as the settings page would.
     *
     * @param \admin_setting $setting
     * @param mixed $value value with site ids
     * @return string error, empty if the value is valid
     */
    protected static function validate(\admin_setting $setting, $value): string {
        if ($setting instanceof \admin_setting_configmultiselect) {
            if (!is_array($value)) {
                return get_string('configjson_notalist', self::PLUGIN);
            }
            $setting->load_choices();
            foreach ($value as $item) {
                if (!is_scalar($item) || !array_key_exists((string)$item, $setting->choices)) {
                    return get_string('configjson_invalidchoice', self::PLUGIN, s(json_encode($item)));
                }
            }
            return '';
        }
        if (!is_scalar($value) && $value !== null) {
            return get_string('configjson_notavalue', self::PLUGIN);
        }
        if ($setting instanceof \admin_setting_configselect) {
            $setting->load_choices();
            if (!array_key_exists((string)$value, $setting->choices)) {
                return get_string('configjson_invalidchoice', self::PLUGIN, s((string)$value));
            }
            return '';
        }
        if ($setting instanceof \admin_setting_configcheckbox) {
            if ((string)$value !== (string)$setting->yes && (string)$value !== (string)$setting->no) {
                return get_string('configjson_invalidchoice', self::PLUGIN, s((string)$value));
            }
            return '';
        }
        if (method_exists($setting, 'validate')) {
            $valid = $setting->validate((string)$value);
            return $valid === true ? '' : (string)$valid;
        }
        return '';
    }

    /**
     * Value with comparable form : lists sorted, everything as strings.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function normalise($value) {
        if (is_array($value)) {
            $value = array_map('strval', array_values($value));
            sort($value);
            return $value;
        }
        return $value === null ? null : (string)$value;
    }

    /**
     * Site value to portable value : role short names, category id number or path.
     *
     * @param string $name setting name
     * @param mixed $value
     * @param bool $strict true to throw an exception when a role or a category does not exist (export), false to
     *     show the id (list of changes)
     * @return mixed
     */
    protected static function to_portable(string $name, $value, bool $strict = true) {
        global $DB;
        if (in_array($name, self::ROLE_SETTINGS)) {
            $ids = is_array($value) ? $value : (($value === '' || $value === null) ? [] : [$value]);
            $shortnames = [];
            foreach ($ids as $id) {
                $shortname = $DB->get_field('role', 'shortname', ['id' => (int)$id]);
                if ($shortname === false) {
                    if ($strict) {
                        throw new \moodle_exception('configjson_roleidnotfound', self::PLUGIN, '', (int)$id);
                    }
                    $shortname = '#' . (int)$id;
                }
                $shortnames[] = $shortname;
            }
            return is_array($value) ? $shortnames : ($shortnames[0] ?? '');
        }
        if (in_array($name, self::CATEGORY_SETTINGS)) {
            if ($value === '' || $value === null) {
                return '';
            }
            $category = $DB->get_record('course_categories', ['id' => (int)$value], 'id, idnumber');
            if (!$category) {
                if ($strict) {
                    throw new \moodle_exception('configjson_categoryidnotfound', self::PLUGIN, '', (int)$value);
                }
                return '#' . (int)$value;
            }
            if ((string)$category->idnumber !== '') {
                return ['idnumber' => $category->idnumber];
            }
            return ['path' => \core_course_category::make_categories_list()[$category->id] ?? ''];
        }
        return $value;
    }

    /**
     * Portable value to site value : role ids, category id.
     *
     * @param string $name setting name
     * @param mixed $value
     * @return mixed
     * @throws \moodle_exception when a role or a category cannot be found on this site
     */
    protected static function from_portable(string $name, $value) {
        global $DB;
        if (in_array($name, self::ROLE_SETTINGS)) {
            $shortnames = is_array($value) ? $value : (($value === '' || $value === null) ? [] : [$value]);
            $ids = [];
            foreach ($shortnames as $shortname) {
                $id = is_scalar($shortname) ? $DB->get_field('role', 'id', ['shortname' => (string)$shortname]) : false;
                if ($id === false) {
                    throw new \moodle_exception('configjson_rolenotfound', self::PLUGIN, '', s(json_encode($shortname)));
                }
                $ids[] = (string)$id;
            }
            return is_array($value) ? $ids : ($ids[0] ?? '');
        }
        if (in_array($name, self::CATEGORY_SETTINGS)) {
            if ($value === '' || $value === null) {
                return '';
            }
            if (is_array($value) && isset($value['idnumber']) && (string)$value['idnumber'] !== '') {
                $ids = $DB->get_fieldset_select('course_categories', 'id', 'idnumber = :idnumber',
                    ['idnumber' => (string)$value['idnumber']]);
                $label = 'idnumber ' . $value['idnumber'];
            } else if (is_array($value) && isset($value['path']) && (string)$value['path'] !== '') {
                $wanted = trim((string)$value['path']);
                $ids = array_keys(array_filter(\core_course_category::make_categories_list(),
                    function($path) use ($wanted) {
                        return trim($path) === $wanted;
                    }));
                $label = $value['path'];
            } else {
                throw new \moodle_exception('configjson_categoryformat', self::PLUGIN);
            }
            if (!$ids) {
                throw new \moodle_exception('configjson_categorynotfound', self::PLUGIN, '', s($label));
            }
            if (count($ids) > 1) {
                throw new \moodle_exception('configjson_categoryambiguous', self::PLUGIN, '', s($label));
            }
            return (string)reset($ids);
        }
        // Stored values are strings ; JSON booleans are accepted for checkboxes.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (is_int($value) || is_float($value)) ? (string)$value : $value;
    }
}
