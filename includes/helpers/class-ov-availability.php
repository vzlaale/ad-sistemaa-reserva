<?php
/**
 * Helper para calcular disponibilidad de horarios
 * 
 * @package OV_Booking_System
 */

class OV_Availability {

    private $form;
    private $service;
    private $professional_id;
    private $date;
    private $exclude_reservation_id;
    
    private $schedule_model;
    private $reservation_model;
    private $datetime_helper;

    public function __construct() {
        $this->schedule_model = new OV_Schedule();
        $this->reservation_model = new OV_Reservation();
        $this->datetime_helper = new OV_DateTime();
    }

    /**
     * Obtener slots disponibles para una fecha y servicio
     */
    public function get_available_slots($form_id, $service_id, $date, $professional_id = null, $exclude_reservation_id = null) {
        // Cargar configuración
        $form_model = new OV_Form();
        $service_model = new OV_Service();
        
        $this->form = $form_model->get($form_id);
        $this->service = $service_model->get($service_id);
        $this->professional_id = $professional_id;
        $this->date = $date;
        $this->exclude_reservation_id = $exclude_reservation_id ? (int) $exclude_reservation_id : null;
        
        if (!$this->form || !$this->service) {
            return array();
        }

        // Obtener horario del día. Los horarios especiales por rango de fechas
        // tienen prioridad y reemplazan el horario semanal regular.
        $schedule = $this->get_day_schedule($date);
        if (empty($schedule)) {
            return array();
        }

        // Una regla especial puede cerrar completamente el día.
        foreach ($schedule as $range) {
            if (!empty($range->closed_all_day)) {
                return array();
            }
        }

        // Verificar si es día festivo
        if ($this->is_holiday($date)) {
            return array();
        }

        // Generar slots (ahora puede haber múltiples franjas)
        $slots = array();

        foreach ($schedule as $range) {
            $range_slots = $this->generate_time_slots($range);
            if (!empty($range_slots)) {
                $slots = array_merge($slots, $range_slots);
            }
        }

        // Ordenar y eliminar duplicados por hora (por si se solapan rangos)
        if (!empty($slots)) {
            $map = array();
            foreach ($slots as $s) {
                $map[$s['time']] = $s;
            }
            ksort($map);
            $slots = array_values($map);
        }

        // Aplicar buffer time
        if ($this->service->buffer_time > 0) {
            $slots = $this->apply_buffer_time($slots);
        }

        // Filtrar slots ocupados
        $slots = $this->filter_booked_slots($slots);

        // Filtrar descansos
        $slots = $this->filter_breaks($slots);

        // Filtrar horarios pasados (solo para hoy)
        if ($this->datetime_helper->is_today($date, $this->form->timezone)) {
            $slots = $this->filter_past_slots($slots);
        }

        return $slots;
    }

    /**
     * Verificar si es día laborable
     */
    private function is_working_day($date) {
        $day_of_week = $this->datetime_helper->get_day_of_week($date);
        
        // Verificar en horarios configurados (pueden existir múltiples franjas)
        $schedules = $this->schedule_model->get_all_by_day(
            $this->form->id,
            $day_of_week,
            $this->professional_id
        );

        if (empty($schedules)) {
            return false;
        }

        // Verificar si es día festivo
        $holidays = $this->schedule_model->get_holidays(
            $this->form->id,
            $this->professional_id
        );
        
        // Asegurarse de que $holidays sea un array
        if (!is_array($holidays)) {
            $holidays = array();
        }
        
        if (in_array($date, $holidays)) {
            return false;
        }

        return true;
    }

    /**
     * Verificar si es día festivo
     */
    private function is_holiday($date) {
        $holidays = $this->schedule_model->get_holidays(
            $this->form->id,
            $this->professional_id
        );
        
        // Asegurarse de que $holidays sea un array
        if (!is_array($holidays)) {
            $holidays = array();
        }
        
        $is_holiday = in_array($date, $holidays);
        
        return $is_holiday;
    }

    /**
     * Verificar límite de anticipación para un slot específico
     */
    private function meets_booking_limit_for_slot($date, $time) {
        $limit_hours = intval($this->form->booking_limit_hours ?? 0);
        
        if ($limit_hours <= 0) {
            return true; // Sin límite
        }

        $now = $this->datetime_helper->get_current_datetime($this->form->timezone);
        $booking_datetime = new DateTime($date . ' ' . $time, new DateTimeZone($this->form->timezone));
        
        $diff_hours = ($booking_datetime->getTimestamp() - $now->getTimestamp()) / 3600;
        
        return $diff_hours >= $limit_hours;
    }

    /**
     * Verificar límite de anticipación para la fecha (usado en calendario)
     */
    private function meets_booking_limit($date) {
        $limit_hours = intval($this->form->booking_limit_hours ?? 0);
        
        if ($limit_hours <= 0) {
            return true; // Sin límite
        }

        $now = $this->datetime_helper->get_current_datetime($this->form->timezone);
        
        // Para el calendario, verificamos si HAY ALGÚN slot disponible ese día
        // Asumimos horario hasta las 23:59
        $end_of_day = new DateTime($date . ' 23:59:59', new DateTimeZone($this->form->timezone));
        
        $diff_hours = ($end_of_day->getTimestamp() - $now->getTimestamp()) / 3600;
        
        return $diff_hours >= $limit_hours;
    }

    /**
     * Obtener horario del día
     */
    private function get_day_schedule($date) {
        $day_of_week = $this->datetime_helper->get_day_of_week($date);

        // Primero buscar reglas especiales para esta fecha. Si existen,
        // sustituyen por completo el horario semanal normal de ese día.
        $special = $this->schedule_model->get_special_by_date(
            $this->form->id,
            $date,
            $day_of_week,
            $this->professional_id
        );
        if (!empty($special)) {
            return $special;
        }

        // Fallback: horario semanal regular (soporta múltiples rangos).
        $schedules = $this->schedule_model->get_all_by_day(
            $this->form->id,
            $day_of_week,
            $this->professional_id
        );

        return is_array($schedules) ? $schedules : array();
    }

    /**
     * Generar slots de tiempo
     */
    private function generate_time_slots($schedule) {
        $slots = array();
        
        try {
            // Validar servicio
            if (!$this->service || !isset($this->service->duration) || !isset($this->service->duration_unit)) {
                return array();
            }
            
            // Calcular duración en minutos
            $duration_minutes = $this->get_service_duration_minutes();
            
            if ($duration_minutes <= 0) {
                return array();
            }
            
            // Determinar intervalo (slot_interval o duration)
            $interval_minutes = $this->service->slot_interval 
                ? intval($this->service->slot_interval) 
                : $duration_minutes;

            // Convertir horas a minutos
            $start_time_parts = explode(':', $schedule->start_time);
            $start_minutes = (intval($start_time_parts[0]) * 60) + intval($start_time_parts[1]);
            
            $end_time_parts = explode(':', $schedule->end_time);
            $end_minutes = (intval($end_time_parts[0]) * 60) + intval($end_time_parts[1]);

            // Reservas por día: mostrar un único bloque que ocupa el día completo.
            if (($this->service->duration_unit ?? 'minutes') === 'days') {
                $time = sprintf('%02d:%02d', floor($start_minutes / 60), $start_minutes % 60);
                $slots[] = array(
                    'time' => $time,
                    'end_time' => '23:59:59',
                    'available' => true,
                    'reserved' => false,
                    'all_day' => true
                );
                return $slots;
            }

            // Generar slots
            for ($minutes = $start_minutes; $minutes < $end_minutes; $minutes += $interval_minutes) {
                $hours = floor($minutes / 60);
                $mins = $minutes % 60;
                
                $time = sprintf('%02d:%02d', $hours, $mins);
                
                // Calcular hora de fin
                $end_slot_minutes = $minutes + $duration_minutes;
                
                // Verificar que no se pase del horario de cierre
                if ($end_slot_minutes > $end_minutes) {
                    break;
                }
                
                $end_hours = floor($end_slot_minutes / 60);
                $end_mins = $end_slot_minutes % 60;
                $end_time = sprintf('%02d:%02d', $end_hours, $end_mins);
                
                $slots[] = array(
                    'time' => $time,
                    'end_time' => $end_time,
                    'available' => true,
                    'reserved' => false
                );
            }
            
        } catch (Exception $e) {
            return array();
        }

        return $slots;
    }

    /**
     * Obtener duración del servicio en minutos
     */
    private function get_service_duration_minutes() {
        $duration = intval($this->service->duration);
        
        switch ($this->service->duration_unit) {
            case 'hours':
                return $duration * 60;
            case 'days':
                return $duration * 1440; // 24 * 60
            default: // minutes
                return $duration;
        }
    }

    /**
     * Aplicar tiempo de buffer
     */
    private function apply_buffer_time($slots) {
        $buffer_minutes = intval($this->service->buffer_time);
        
        if ($buffer_minutes <= 0) {
            return $slots;
        }

        // Recalcular end_time de cada slot incluyendo buffer
        foreach ($slots as &$slot) {
            $time_parts = explode(':', $slot['end_time']);
            $end_minutes = (intval($time_parts[0]) * 60) + intval($time_parts[1]) + $buffer_minutes;
            
            $hours = floor($end_minutes / 60);
            $mins = $end_minutes % 60;
            
            $slot['end_time'] = sprintf('%02d:%02d', $hours, $mins);
            $slot['has_buffer'] = true;
        }

        return $slots;
    }

    /**
     * Obtener reservas por día que cubren la fecha consultada.
     *
     * Regla:
     * - Minutos bloquean minutos.
     * - Horas bloquean horas.
     * - Días bloquean días completos.
     *
     * Para una reserva de 2 días creada el 2026-07-07, se bloquean:
     * 2026-07-07 y 2026-07-08. El 2026-07-09 vuelve a estar libre.
     */
    private function get_day_blocking_reservations($service_id = null, $professional_id = null) {
        global $wpdb;

        $table_name = $this->reservation_model->get_table_name();

        $sql = "SELECT * FROM {$table_name}
                WHERE form_id = %d
                AND status != 'cancelled'
                AND duration_unit = 'days'
                AND booking_date <= %s
                AND DATE_ADD(booking_date, INTERVAL duration DAY) > %s";

        $params = array(
            $this->form->id,
            $this->date,
            $this->date
        );

        if ($service_id) {
            $sql .= " AND service_id = %d";
            $params[] = $service_id;
        }

        if ($professional_id) {
            $sql .= " AND professional_id = %d";
            $params[] = $professional_id;
        }

        if ($this->exclude_reservation_id) {
            $sql .= " AND id != %d";
            $params[] = $this->exclude_reservation_id;
        }

        $sql .= " ORDER BY booking_date ASC";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }


    /**
     * Agregar información de cupos al slot para mostrarla en el frontend.
     */
    private function set_slot_capacity(&$slot, $used, $capacity) {
        $capacity = max(1, intval($capacity));
        $used = max(0, intval($used));
        $remaining = max(0, $capacity - $used);

        $slot['capacity'] = $capacity;
        $slot['used'] = $used;
        $slot['remaining'] = $remaining;
    }

    /**
     * Filtrar reservas que bloquean días completos.
     */
    private function filter_day_booked_slots($slots, $limit_type, $professionals_enabled) {
        if (($this->service->duration_unit ?? 'minutes') !== 'days') {
            return $slots;
        }

        $filter_professional = ($this->professional_id !== null && $this->professional_id > 0);
        $capacity = max(1, intval($this->form->max_bookings_per_slot ?? 1));

        if ($limit_type === 'per_professional' && $professionals_enabled) {
            // Si hay profesional seleccionado, un servicio de días bloquea/contabiliza al profesional completo,
            // sin importar qué servicio generó la reserva.
            $service_filter = $filter_professional ? null : $this->service->id;
            $professional_filter = $filter_professional ? $this->professional_id : null;
        } else {
            // En modo global, se cuentan reservas del servicio actual.
            $service_filter = $this->service->id;
            $professional_filter = null;
        }

        $reservations = $this->get_day_blocking_reservations(
            $service_filter,
            $professional_filter
        );

        $reservation_count = is_array($reservations) ? count($reservations) : 0;

        foreach ($slots as &$slot) {
            if (!$this->meets_booking_limit_for_slot($this->date, $slot['time'])) {
                $slot['available'] = false;
                $slot['reserved'] = true;
                $this->set_slot_capacity($slot, $capacity, $capacity);
                continue;
            }

            $used = $reservation_count;

            // En modo global puede haber varios cupos por día, pero si se selecciona un profesional
            // específico ese profesional no debe recibir dos reservas por día en la misma fecha.
            if (($limit_type !== 'per_professional' || !$professionals_enabled) && $filter_professional) {
                foreach ($reservations as $reservation) {
                    if ((int) $reservation->professional_id === (int) $this->professional_id) {
                        $slot['available'] = false;
                        $slot['reserved'] = true;
                        $this->set_slot_capacity($slot, $capacity, $capacity);
                        continue 2;
                    }
                }
            }

            $this->set_slot_capacity($slot, $used, $capacity);

            if ($used >= $capacity) {
                $slot['available'] = false;
                $slot['reserved'] = true;
            }
        }

        return $slots;
    }

    /**
     * Filtrar slots ocupados
     */
    private function filter_booked_slots($slots) {
        // Determinar el tipo de límite
        // Si profesionales NO está habilitado, forzar modo global
        $professionals_enabled = $this->form->enable_professionals ?? false;
        $limit_type = $professionals_enabled 
            ? ($this->form->max_bookings_per_slot_type ?? 'per_professional')
            : 'global';

        // Reservas por días: bloquear días completos, no solo la hora exacta.
        if (($this->service->duration_unit ?? 'minutes') === 'days') {
            return $this->filter_day_booked_slots($slots, $limit_type, $professionals_enabled);
        }
        
        // Lógica de filtrado según el tipo
        if ($limit_type === 'per_professional' && $professionals_enabled) {
            // MODO 1: Por profesional (solo si profesionales está habilitado)
            // Solo filtrar por profesional si se especificó uno > 0
            $filter_professional = ($this->professional_id !== null && $this->professional_id > 0);
            
            
            // Si hay profesional seleccionado, bloquear el horario por cualquier servicio (ocupación real del profesional)
            $service_filter = $filter_professional ? null : $this->service->id;
            $reservations = $this->reservation_model->get_by_date(
                $this->form->id,
                $this->date,
                $service_filter,
                $filter_professional ? $this->professional_id : null,
                $this->exclude_reservation_id
            );

            foreach ($slots as &$slot) {
                // Verificar límite de anticipación para este slot específico
                if (!$this->meets_booking_limit_for_slot($this->date, $slot['time'])) {
                    $slot['available'] = false;
                    $slot['reserved'] = true;
                    continue;
                }
                
                // Contar reservas en este horario (filtrado por profesional si aplica)
                $count = $this->reservation_model->count_bookings_in_slot(
                    $this->form->id,
                    $this->date,
                    $slot['time'],
                    $service_filter,
                    $filter_professional ? $this->professional_id : null,
                    $this->exclude_reservation_id
                );

                // Informar cupos disponibles al frontend
                $this->set_slot_capacity($slot, $count, $this->form->max_bookings_per_slot);

                // Si el profesional alcanzó su máximo, marcar no disponible
                if ($count >= $this->form->max_bookings_per_slot) {
                    $slot['available'] = false;
                    $slot['reserved'] = true;
                    continue;
                }

                // Verificar solapamientos
                foreach ($reservations as $reservation) {
                    if ($this->slots_overlap($slot, $reservation)) {
                        $slot['available'] = false;
                        $slot['reserved'] = true;
                        break;
                    }
                }
            }
        } else {
            // MODO 2: Global (sala compartida)
            // Contar TODAS las reservas sin importar profesional
            $reservations = $this->reservation_model->get_by_date(
                $this->form->id,
                $this->date,
                $this->service->id,
                null, // Sin filtrar por profesional
                $this->exclude_reservation_id
            );

            foreach ($slots as &$slot) {
                // Verificar límite de anticipación para este slot específico
                if (!$this->meets_booking_limit_for_slot($this->date, $slot['time'])) {
                    $slot['available'] = false;
                    $slot['reserved'] = true;
                    continue;
                }
                
                // Contar TODAS las reservas del slot (sin filtro de profesional)
                $count = $this->reservation_model->count_bookings_in_slot(
                    $this->form->id,
                    $this->date,
                    $slot['time'],
                    $this->service->id,
                    null, // Sin filtrar por profesional
                    $this->exclude_reservation_id
                );

                // Si se alcanzó el máximo global, no disponible
                if ($count >= $this->form->max_bookings_per_slot) {
                    $slot['available'] = false;
                    $slot['reserved'] = true;
                    continue;
                }
                
                // En modo global puede haber varios cupos por horario, pero un mismo profesional
                // nunca debe recibir dos reservas que se solapen en la misma fecha/hora.
                $overlapping_count = 0;
                foreach ($reservations as $reservation) {
                    if ($this->slots_overlap($slot, $reservation)) {
                        $overlapping_count++;
                        if ($this->professional_id !== null && $this->professional_id > 0 && (int) $reservation->professional_id === (int) $this->professional_id) {
                            $slot['available'] = false;
                            $slot['reserved'] = true;
                            break;
                        }
                    }
                }

                $this->set_slot_capacity($slot, $overlapping_count, $this->form->max_bookings_per_slot);

                if (!$slot['available']) {
                    continue;
                }
                
                // Si ya hay reservas que se solapan con este slot y se alcanzó el máximo global, bloquear.
                if ($overlapping_count >= $this->form->max_bookings_per_slot) {
                    $slot['available'] = false;
                    $slot['reserved'] = true;
                }
            }
        }

        return $slots;
    }

    /**
     * Verificar si dos slots se solapan
     */
    private function slots_overlap($slot, $reservation) {
        $slot_start = $slot['time'];
        $slot_end = $slot['end_time'];
        
        $res_start = $reservation->booking_time;
        $res_end = $reservation->end_time;

        // Convertir a minutos para comparación
        $slot_start_mins = $this->time_to_minutes($slot_start);
        $slot_end_mins = $this->time_to_minutes($slot_end);
        $res_start_mins = $this->time_to_minutes($res_start);
        $res_end_mins = $this->time_to_minutes($res_end);

        // Verificar solapamiento
        return !(
            $slot_end_mins <= $res_start_mins || 
            $slot_start_mins >= $res_end_mins
        );
    }

    /**
     * Convertir tiempo a minutos
     */
    private function time_to_minutes($time) {
        $parts = explode(':', $time);
        return (intval($parts[0]) * 60) + intval($parts[1]);
    }

    /**
     * Filtrar descansos
     */
    private function filter_breaks($slots) {
        $day_of_week = $this->datetime_helper->get_day_of_week($this->date);
        
        $breaks = $this->schedule_model->get_breaks(
            $this->form->id,
            $this->professional_id,
            $this->date,
            $day_of_week
        );

        foreach ($slots as &$slot) {
            foreach ($breaks as $break) {
                // Convertir a minutos
                $slot_start = $this->time_to_minutes($slot['time']);
                $slot_end = $this->time_to_minutes($slot['end_time']);
                $break_start = $this->time_to_minutes($break->start_time);
                $break_end = $this->time_to_minutes($break->end_time);

                // Verificar si el slot está dentro del descanso
                if (!(
                    $slot_end <= $break_start || 
                    $slot_start >= $break_end
                )) {
                    $slot['available'] = false;
                    $slot['is_break'] = true;
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * Filtrar horarios pasados
     */
    private function filter_past_slots($slots) {
        $now = $this->datetime_helper->get_current_datetime($this->form->timezone);
        $current_time = $now->format('H:i');
        $current_minutes = $this->time_to_minutes($current_time);

        foreach ($slots as &$slot) {
            $slot_minutes = $this->time_to_minutes($slot['time']);
            
            if ($slot_minutes <= $current_minutes) {
                $slot['available'] = false;
                $slot['past'] = true;
            }
        }

        return $slots;
    }

    /**
     * Validar si un slot específico está disponible
     */
    public function is_slot_available($form_id, $service_id, $date, $time, $professional_id = null, $exclude_reservation_id = null) {
        $slots = $this->get_available_slots($form_id, $service_id, $date, $professional_id, $exclude_reservation_id);
        
        foreach ($slots as $slot) {
            if ($slot['time'] === $time && $slot['available']) {
                return true;
            }
        }
        
        return false;
    }
}
