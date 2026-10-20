<?php
/** Ejecutar: php tests/month-availability.php. Comparación aislada, sin WordPress/MySQL. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function row($values) { return (object) $values; }
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function matches_professional($row, $id) {
    return $id === null || (int) $row->professional_id === (int) $id;
}
function overlaps_day($row, $date) {
    if ($row->duration_unit !== 'days' || $row->booking_date > $date) { return false; }
    return (new DateTimeImmutable($row->booking_date))->modify('+' . $row->duration . ' days')->format('Y-m-d') > $date;
}

class Test_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = 0;
    public function prepare($sql, $params) {
        return array('sql' => $sql, 'params' => is_array($params) ? $params : array_slice(func_get_args(), 1));
    }
    public function get_var($query) {
        ++$this->queries;
        return $query['params'][0]; // Las tablas opcionales existen en este fixture.
    }
    public function get_results($query) {
        ++$this->queries;
        $sql = $query['sql'];
        $params = $query['params'];
        if (strpos($sql, 'FROM wp_ov_booking_professional_schedule_exceptions') !== false) {
            $ids = array_slice($params, 0, -2);
            return array_values(array_filter($GLOBALS['exceptions'], function($r) use ($ids, $params) {
                return in_array($r->professional_id, $ids, true) && $r->start_date <= $params[count($params)-2] && $r->end_date >= end($params);
            }));
        }
        if (strpos($sql, 'FROM wp_ov_booking_professional_schedules') !== false) {
            return array_values(array_filter($GLOBALS['professional_weekly'], function($r) use ($params) {
                return in_array($r->professional_id, $params, true);
            }));
        }
        if (strpos($sql, 'FROM wp_ov_booking_special_schedules') !== false) {
            return array_values(array_filter($GLOBALS['general_special'], function($r) use ($params) {
                return $r->start_date <= $params[1] && $r->end_date >= $params[2];
            }));
        }
        if (strpos($sql, 'FROM wp_ov_booking_schedules') !== false) { return $GLOBALS['general_weekly']; }
        if (strpos($sql, 'FROM wp_ov_booking_holidays') !== false) {
            return array_values(array_filter($GLOBALS['holidays'], function($r) use ($params) {
                return $r->holiday_date >= $params[1] && $r->holiday_date <= $params[2];
            }));
        }
        if (strpos($sql, 'FROM wp_ov_booking_breaks') !== false) {
            return array_values(array_filter($GLOBALS['breaks'], function($r) use ($params) {
                return $r->break_date === null || ($r->break_date >= $params[1] && $r->break_date <= $params[2]);
            }));
        }
        if (strpos($sql, 'FROM wp_ov_booking_reservations') !== false) {
            $days = strpos($sql, "duration_unit = 'days'") !== false;
            $month = strpos($sql, 'SELECT id, service_id') !== false;
            $rows = array_filter($GLOBALS['reservations'], function($r) use ($days, $month, $params, $sql) {
                if ($r->status === 'cancelled') { return false; }
                if ($month) {
                    $scope = array_slice($params, 3);
                    if (strpos($sql, 'AND professional_id IN') !== false && !in_array($r->professional_id, $scope, true)) { return false; }
                    if (strpos($sql, 'AND service_id = %d') !== false && $r->service_id !== $scope[0]) { return false; }
                }
                if ($days) {
                    if ($r->duration_unit !== 'days') { return false; }
                    if ($month) {
                        return $r->booking_date <= $params[1] &&
                            (new DateTimeImmutable($r->booking_date))->modify('+' . $r->duration . ' days')->format('Y-m-d') > $params[2];
                    }
                    if (!overlaps_day($r, $params[1])) { return false; }
                    $index = 3;
                    if (strpos($sql, 'AND service_id = %d') !== false && (int) $r->service_id !== (int) $params[$index++]) { return false; }
                    if (strpos($sql, 'AND professional_id = %d') !== false && (int) $r->professional_id !== (int) $params[$index]) { return false; }
                    return true;
                }
                return $r->booking_date >= $params[1] && $r->booking_date <= $params[2];
            });
            usort($rows, function($a, $b) use ($days) {
                $order = strcmp($a->booking_date, $b->booking_date);
                return $order ?: ($days ? 0 : strcmp($a->booking_time, $b->booking_time));
            });
            return array_values($rows);
        }
        throw new RuntimeException('Consulta inesperada: ' . $sql);
    }
}

class OV_Form {
    public function get($id) { ++$GLOBALS['wpdb']->queries; return $GLOBALS['form']; }
}
class OV_Service {
    public function get($id) { ++$GLOBALS['wpdb']->queries; return $GLOBALS['service']; }
}
class OV_Schedule {
    public function get_special_by_date($form_id, $date, $day, $professional_id) {
        ++$GLOBALS['wpdb']->queries;
        $rows = $professional_id === null ? $GLOBALS['general_special'] : $GLOBALS['exceptions'];
        return array_values(array_filter($rows, function($r) use ($date, $day, $professional_id) {
            return matches_professional($r, $professional_id) && $r->start_date <= $date && $r->end_date >= $date &&
                in_array((string) $day, explode(',', $r->days_of_week), true);
        }));
    }
    public function get_all_by_day($form_id, $day, $professional_id) {
        ++$GLOBALS['wpdb']->queries;
        $rows = $professional_id === null ? $GLOBALS['general_weekly'] : $GLOBALS['professional_weekly'];
        return array_values(array_filter($rows, function($r) use ($day, $professional_id) {
            return (int) $r->day_of_week === (int) $day && matches_professional($r, $professional_id);
        }));
    }
    public function get_holidays($form_id, $professional_id) {
        ++$GLOBALS['wpdb']->queries;
        return array_map(function($r) { return $r->holiday_date; }, array_values(array_filter($GLOBALS['holidays'], function($r) use ($professional_id) {
            return matches_professional($r, $professional_id);
        })));
    }
    public function get_breaks($form_id, $professional_id, $date, $day) {
        ++$GLOBALS['wpdb']->queries;
        return array_values(array_filter($GLOBALS['breaks'], function($r) use ($professional_id, $date, $day) {
            return matches_professional($r, $professional_id) && ($r->break_date === null || $r->break_date === $date) &&
                ($r->day_of_week === null || (int) $r->day_of_week === (int) $day);
        }));
    }
}
class OV_Reservation {
    public function get_table_name() { return 'wp_ov_booking_reservations'; }
    public function get_by_date($form_id, $date, $service_id = null, $professional_id = null, $exclude_id = null) {
        ++$GLOBALS['wpdb']->queries;
        $rows = array_values(array_filter($GLOBALS['reservations'], function($r) use ($date, $service_id, $professional_id) {
            return $r->status !== 'cancelled' && $r->booking_date === $date &&
                ($service_id === null || $r->service_id === $service_id) && matches_professional($r, $professional_id);
        }));
        usort($rows, function($a, $b) { return strcmp($a->booking_time, $b->booking_time); });
        return $rows;
    }
    public function count_bookings_in_slot($form_id, $date, $time, $service_id = null, $professional_id = null, $exclude_id = null) {
        ++$GLOBALS['wpdb']->queries;
        $count = 0;
        foreach ($this->get_fixture_rows($date, $service_id, $professional_id) as $r) {
            if ($r->booking_time === $time . ':00') { ++$count; }
        }
        return $count;
    }
    private function get_fixture_rows($date, $service_id, $professional_id) {
        return array_filter($GLOBALS['reservations'], function($r) use ($date, $service_id, $professional_id) {
            return $r->status !== 'cancelled' && $r->booking_date === $date &&
                ($service_id === null || $r->service_id === $service_id) && matches_professional($r, $professional_id);
        });
    }
}

class OV_DateTime {
    public function get_current_datetime($timezone) {
        return new DateTime('2026-10-20 12:00:00', new DateTimeZone($timezone));
    }
    public function is_today($date, $timezone) {
        return $date === $this->get_current_datetime($timezone)->format('Y-m-d');
    }
    public function get_day_of_week($date) {
        return (int) (new DateTime($date))->format('w');
    }
}
require dirname(__DIR__) . '/includes/helpers/class-ov-month-availability-data.php';
require dirname(__DIR__) . '/includes/helpers/class-ov-availability.php';

$GLOBALS['wpdb'] = new Test_DB();
$GLOBALS['form'] = row(array(
    'id' => 1, 'timezone' => 'America/Santiago', 'enable_professionals' => 1,
    'max_bookings_per_slot_type' => 'per_professional', 'max_bookings_per_slot' => 2,
    'booking_limit_hours' => 0,
));
$GLOBALS['general_weekly'] = array();
$GLOBALS['professional_weekly'] = array();
for ($day = 0; $day < 7; ++$day) {
    $GLOBALS['general_weekly'][] = row(array('professional_id' => null, 'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '12:00:00'));
    foreach (array(10, 11) as $id) {
        $GLOBALS['professional_weekly'][] = row(array('professional_id' => $id, 'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '12:00:00'));
    }
}
$GLOBALS['general_special'] = array(row(array(
    'professional_id' => null, 'start_date' => '2026-11-03', 'end_date' => '2026-11-03',
    'days_of_week' => '2', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'closed_all_day' => 0,
)));
$GLOBALS['exceptions'] = array(
    row(array('professional_id' => 10, 'start_date' => '2026-11-05', 'end_date' => '2026-11-05', 'days_of_week' => '4', 'start_time' => null, 'end_time' => null, 'closed_all_day' => 1)),
    row(array('professional_id' => 11, 'start_date' => '2026-11-05', 'end_date' => '2026-11-05', 'days_of_week' => '4', 'start_time' => '10:00:00', 'end_time' => '14:00:00', 'closed_all_day' => 0)),
);
$GLOBALS['holidays'] = array(
    row(array('professional_id' => 11, 'holiday_date' => '2026-11-06')),
    row(array('professional_id' => 10, 'holiday_date' => '2026-11-07')),
);
$GLOBALS['breaks'] = array(
    row(array('professional_id' => null, 'break_date' => '2026-11-03', 'day_of_week' => null, 'start_time' => '10:00:00', 'end_time' => '11:00:00')),
    row(array('professional_id' => 10, 'break_date' => '2026-11-04', 'day_of_week' => null, 'start_time' => '09:00:00', 'end_time' => '10:00:00')),
    row(array('professional_id' => 10, 'break_date' => null, 'day_of_week' => 0, 'start_time' => '09:00:00', 'end_time' => '10:00:00')),
);
function reservation($id, $service, $professional, $date, $time, $end, $unit = 'minutes', $duration = 60, $status = 'confirmed') {
    return row(array('id' => $id, 'service_id' => $service, 'professional_id' => $professional,
        'booking_date' => $date, 'booking_time' => $time, 'end_time' => $end,
        'duration_unit' => $unit, 'duration' => $duration, 'status' => $status));
}
$GLOBALS['reservations'] = array(
    reservation(1, 2, 10, '2026-11-04', '09:00:00', '10:00:00'),
    reservation(2, 2, 11, '2026-11-04', '09:00:00', '10:00:00'),
    reservation(3, 3, 10, '2026-11-04', '10:00:00', '11:00:00'),
    reservation(4, 2, 11, '2026-11-04', '10:30:00', '11:30:00'),
    reservation(5, 2, 10, '2026-11-04', '11:00:00', '12:00:00', 'minutes', 60, 'cancelled'),
    reservation(6, 4, 10, '2026-10-31', '09:00:00', '23:59:59', 'days', 3),
    reservation(7, 4, 11, '2026-11-04', '09:00:00', '23:59:59', 'days', 2),
    reservation(8, 2, 10, '2026-11-02', '09:00:30', '10:00:00'),
);

$cases = 0;
$baseline_queries = 0;
$snapshot_queries = 0;
foreach (array('minutes', 'days') as $unit) {
    $GLOBALS['service'] = row(array('id' => $unit === 'days' ? 4 : 2, 'duration_unit' => $unit,
        'duration' => $unit === 'days' ? 2 : 60, 'slot_interval' => 30, 'buffer_time' => $unit === 'days' ? 0 : 15));
    foreach (array('per_professional', 'global') as $limit_type) {
        $GLOBALS['form']->max_bookings_per_slot_type = $limit_type;
        foreach (array(0, 24 * 14) as $limit_hours) {
            $GLOBALS['form']->booking_limit_hours = $limit_hours;
            foreach (array(array(null), array(10), array(10, 11)) as $professionals) {
                $GLOBALS['wpdb']->queries = 0;
                $expected = array();
                $daily_slots = array();
                for ($day = 1; $day <= 8; ++$day) {
                    $date = sprintf('2026-11-%02d', $day);
                    foreach ($professionals as $professional_id) {
                        $slots = (new OV_Availability())->get_available_slots(1, $GLOBALS['service']->id, $date, $professional_id);
                        $daily_slots[$date][(string) $professional_id] = $slots;
                        foreach ($slots as $slot) {
                            if (!empty($slot['available'])) { $expected[$date] = true; break; }
                        }
                    }
                }
                $before = $GLOBALS['wpdb']->queries;
                $GLOBALS['wpdb']->queries = 0;
                $data = new OV_Month_Availability_Data($GLOBALS['form'], $GLOBALS['service'], '2026-11-01', '2026-11-08', $professionals);
                $availability = new OV_Availability();
                $property = new ReflectionProperty('OV_Availability', 'month_data');
                $property->setAccessible(true);
                $property->setValue($availability, $data);
                foreach ($daily_slots as $date => $professionals_slots) {
                    foreach ($professionals as $professional_id) {
                        $actual = $availability->get_available_slots(1, $GLOBALS['service']->id, $date, $professional_id);
                        check($actual === $professionals_slots[(string) $professional_id], 'Slots/cupos distintos: ' . $unit . ' ' . $limit_type . ' ' . $date . ' ' . (string) $professional_id);
                    }
                }
                $after = $GLOBALS['wpdb']->queries;
                check($after < $before && $after <= 8, 'La precarga debe acotar consultas');
                $dates = (new OV_Availability())->get_month_available_dates($GLOBALS['form'], $GLOBALS['service'], '2026-11-01', '2026-11-08', $professionals);
                check($dates === array_keys($expected), 'Fechas distintas: ' . $unit . ' ' . $limit_type);
                ++$cases;
                $baseline_queries += $before;
                $snapshot_queries += $after;
            }
        }
    }
}
$GLOBALS['form']->enable_professionals = 0;
$GLOBALS['form']->booking_limit_hours = 0;
$GLOBALS['form']->max_bookings_per_slot_type = 'global';
$GLOBALS['service'] = row(array('id' => 2, 'duration_unit' => 'minutes', 'duration' => 60, 'slot_interval' => 30, 'buffer_time' => 15));
$GLOBALS['wpdb']->queries = 0;
$daily = (new OV_Availability())->get_available_slots(1, 2, '2026-11-04');
$baseline_queries += $GLOBALS['wpdb']->queries;
$GLOBALS['wpdb']->queries = 0;
$data = new OV_Month_Availability_Data($GLOBALS['form'], $GLOBALS['service'], '2026-11-04', '2026-11-04', array());
$availability = new OV_Availability();
$property->setValue($availability, $data);
check($availability->get_available_slots(1, 2, '2026-11-04') === $daily, 'Cupo global sin profesionales');
$snapshot_queries += $GLOBALS['wpdb']->queries;
check((new OV_Availability())->get_month_available_dates($GLOBALS['form'], $GLOBALS['service'], '2026-11-04', '2026-11-04', array(null)) ===
    array('2026-11-04'), 'Fecha mensual sin profesionales');
++$cases;
echo "Equivalencia mensual: {$cases} escenarios, {$baseline_queries} consultas base frente a {$snapshot_queries} precargadas (fixture)\n";
