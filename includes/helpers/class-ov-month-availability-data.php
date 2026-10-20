<?php
/**
 * Datos de disponibilidad cargados solo durante una consulta mensual pública.
 *
 * @package OV_Booking_System
 */

class OV_Month_Availability_Data {
    public $form;
    public $service;

    private $start_date;
    private $end_date;
    private $professional_ids;
    private $weekly = array();
    private $special = array();
    private $weekly_by_professional = array();
    private $special_by_professional = array();
    private $holidays = array();
    private $breaks = array();
    private $breaks_by_professional = array();
    private $reservations = array();
    private $day_reservations = array();

    public function __construct($form, $service, $start_date, $end_date, $professional_ids) {
        $this->form = $form;
        $this->service = $service;
        $this->start_date = $start_date;
        $this->end_date = $end_date;

        $professional_ids = array_values(array_unique(array_filter(array_map('intval', $professional_ids))));
        $this->professional_ids = $professional_ids;
        $this->load_schedules($professional_ids);
        $this->load_holidays();
        $this->load_breaks();
        $this->load_reservations();
    }

    /** Los identificadores de tablas se construyen únicamente con el prefijo interno. */
    private function rows($sql, $params) {
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare($sql, $params));
        if ($result === null || !empty($wpdb->last_error)) {
            throw new RuntimeException('No se pudo precargar la disponibilidad mensual.');
        }
        return $result;
    }

    private function table_exists($table) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!empty($wpdb->last_error)) {
            throw new RuntimeException('No se pudo comprobar la tabla de horarios.');
        }
        return $found === $table;
    }

    private function placeholders($count) {
        return implode(', ', array_fill(0, $count, '%d'));
    }

    private function professional_key($professional_id) {
        return $professional_id === null ? 'general' : (string) (int) $professional_id;
    }

    private function index_schedules() {
        foreach ($this->weekly as $row) {
            $key = $this->professional_key($row->professional_id);
            $this->weekly_by_professional[$key][(int) $row->day_of_week][] = $row;
        }
        foreach ($this->special as $row) {
            $key = $this->professional_key($row->professional_id);
            $this->special_by_professional[$key][] = $row;
        }
        $this->weekly = array();
        $this->special = array();
    }

    private function load_schedules($professional_ids) {
        global $wpdb;
        $form_id = (int) $this->form->id;

        if (empty($professional_ids)) {
            $table = $wpdb->prefix . 'ov_booking_schedules';
            $this->weekly = $this->rows(
                "SELECT * FROM {$table} WHERE form_id = %d AND professional_id IS NULL AND active = 1 ORDER BY start_time ASC",
                array($form_id)
            );
            $table = $wpdb->prefix . 'ov_booking_special_schedules';
            if ($this->table_exists($table)) {
                $this->special = $this->rows(
                    "SELECT * FROM {$table} WHERE form_id = %d AND professional_id IS NULL AND active = 1 AND start_date <= %s AND end_date >= %s ORDER BY professional_id DESC, start_time ASC, id ASC",
                    array($form_id, $this->end_date, $this->start_date)
                );
            }
            $this->index_schedules();
            return;
        }

        $table = $wpdb->prefix . 'ov_booking_professional_schedules';
        if ($this->table_exists($table)) {
            $this->weekly = $this->rows(
                "SELECT * FROM {$table} WHERE professional_id IN (" . $this->placeholders(count($professional_ids)) . ") AND active = 1 ORDER BY day_of_week ASC, start_time ASC",
                $professional_ids
            );
        }
        $table = $wpdb->prefix . 'ov_booking_professional_schedule_exceptions';
        if ($this->table_exists($table)) {
            $params = array_merge($professional_ids, array($this->end_date, $this->start_date));
            $this->special = $this->rows(
                "SELECT * FROM {$table} WHERE professional_id IN (" . $this->placeholders(count($professional_ids)) . ") AND active = 1 AND start_date <= %s AND end_date >= %s ORDER BY start_time ASC, id ASC",
                $params
            );
        }
        $this->index_schedules();
    }

    private function load_holidays() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_holidays';
        $rows = $this->rows(
            "SELECT professional_id, holiday_date FROM {$table} WHERE form_id = %d AND holiday_date BETWEEN %s AND %s",
            array((int) $this->form->id, $this->start_date, $this->end_date)
        );
        foreach ($rows as $row) {
            $this->holidays[$row->holiday_date][] = $row->professional_id;
        }
    }

    private function load_breaks() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_breaks';
        $this->breaks = $this->rows(
            "SELECT * FROM {$table} WHERE form_id = %d AND active = 1 AND (break_date IS NULL OR break_date BETWEEN %s AND %s)",
            array((int) $this->form->id, $this->start_date, $this->end_date)
        );
        foreach ($this->breaks as $row) {
            $this->breaks_by_professional[$this->professional_key($row->professional_id)][] = $row;
        }
    }

    private function load_reservations() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_reservations';
        $form_id = (int) $this->form->id;
        $per_professional = !empty($this->form->enable_professionals) &&
            ($this->form->max_bookings_per_slot_type ?? 'per_professional') === 'per_professional' &&
            !empty($this->professional_ids);
        $scope = $per_professional
            ? ' AND professional_id IN (' . $this->placeholders(count($this->professional_ids)) . ')'
            : ' AND service_id = %d';
        $scope_params = $per_professional ? $this->professional_ids : array((int) $this->service->id);
        if (($this->service->duration_unit ?? 'minutes') === 'days') {
            $rows = $this->rows(
                "SELECT id, service_id, professional_id, booking_date, duration FROM {$table} WHERE form_id = %d AND status != 'cancelled' AND duration_unit = 'days' AND booking_date <= %s AND DATE_ADD(booking_date, INTERVAL duration DAY) > %s{$scope} ORDER BY booking_date ASC",
                array_merge(array($form_id, $this->end_date, $this->start_date), $scope_params)
            );
            foreach ($rows as $row) {
                if ((int) $row->duration <= 0) {
                    continue;
                }
                $first = max($this->start_date, $row->booking_date);
                $last_exclusive = (new DateTimeImmutable($row->booking_date))->modify('+' . (int) $row->duration . ' days')->format('Y-m-d');
                for ($date = new DateTimeImmutable($first); $date->format('Y-m-d') <= $this->end_date && $date->format('Y-m-d') < $last_exclusive; $date = $date->modify('+1 day')) {
                    $this->day_reservations[$date->format('Y-m-d')][] = $row;
                }
            }
            return;
        }

        $rows = $this->rows(
            "SELECT id, service_id, professional_id, booking_date, booking_time, end_time FROM {$table} WHERE form_id = %d AND status != 'cancelled' AND booking_date BETWEEN %s AND %s{$scope} ORDER BY booking_date ASC, booking_time ASC",
            array_merge(array($form_id, $this->start_date, $this->end_date), $scope_params)
        );
        foreach ($rows as $row) {
            $this->reservations[$row->booking_date][] = $row;
        }
    }

    private function matches_professional($row, $professional_id) {
        return $professional_id === null || (int) $row->professional_id === (int) $professional_id;
    }

    public function get_schedule($date, $day_of_week, $professional_id) {
        $key = $this->professional_key($professional_id);
        $special = array();
        foreach ($this->special_by_professional[$key] ?? array() as $row) {
            if ($date >= $row->start_date && $date <= $row->end_date &&
                in_array((string) $day_of_week, explode(',', (string) $row->days_of_week), true)) {
                $special[] = $row;
            }
        }
        if ($special) {
            return $special;
        }

        return $this->weekly_by_professional[$key][(int) $day_of_week] ?? array();
    }

    public function is_holiday($date, $professional_id) {
        if (empty($this->holidays[$date])) {
            return false;
        }
        if ($professional_id === null) {
            // OV_Schedule::get_holidays() incluye todos los profesionales sin filtro.
            return true;
        }
        foreach ($this->holidays[$date] as $id) {
            if ((int) $id === (int) $professional_id && $id !== null) {
                return true;
            }
        }
        return false;
    }

    public function get_breaks($date, $day_of_week, $professional_id) {
        $result = array();
        $rows = $professional_id === null ? $this->breaks :
            ($this->breaks_by_professional[$this->professional_key($professional_id)] ?? array());
        foreach ($rows as $row) {
            if (($row->break_date === null || $row->break_date === $date) &&
                ($row->day_of_week === null || (int) $row->day_of_week === (int) $day_of_week)) {
                $result[] = $row;
            }
        }
        return $result;
    }

    public function get_reservations($date, $service_id, $professional_id, $whole_day = false) {
        $rows = $whole_day ? ($this->day_reservations[$date] ?? array()) : ($this->reservations[$date] ?? array());
        $result = array();
        foreach ($rows as $row) {
            if (($service_id === null || (int) $row->service_id === (int) $service_id) &&
                $this->matches_professional($row, $professional_id)) {
                $result[] = $row;
            }
        }
        return $result;
    }
}
