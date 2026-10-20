<?php
/**
 * Helper para manejo de fechas y zonas horarias
 * 
 * @package OV_Booking_System
 */

class OV_DateTime {

    /**
     * Obtener fecha y hora actual en una zona horaria específica
     */
    public function get_current_datetime($timezone = 'UTC') {
        try {
            $tz = new DateTimeZone($timezone);
            return new DateTime('now', $tz);
        } catch (Exception $e) {
            return new DateTime('now', new DateTimeZone('UTC'));
        }
    }

    /**
     * Verificar si una fecha es hoy
     */
    public function is_today($date, $timezone = 'UTC') {
        $now = $this->get_current_datetime($timezone);
        $check_date = new DateTime($date, new DateTimeZone($timezone));
        
        return $now->format('Y-m-d') === $check_date->format('Y-m-d');
    }

    /**
     * Obtener día de la semana (0-6, donde 0 es domingo)
     */
    public function get_day_of_week($date) {
        $dt = new DateTime($date);
        return (int) $dt->format('w');
    }

    /**
     * Formatear fecha para mostrar
     */
    public function format_date($date, $format = 'Y-m-d', $timezone = 'UTC') {
        try {
            $dt = new DateTime($date, new DateTimeZone($timezone));
            return $dt->format($format);
        } catch (Exception $e) {
            return $date;
        }
    }

    /**
     * Formatear fecha localmente (español)
     */
    public function format_date_locale($date, $timezone = 'UTC') {
        try {
            $dt = new DateTime($date, new DateTimeZone($timezone));
            
            $day_names = array(
                'Sunday' => 'Domingo',
                'Monday' => 'Lunes',
                'Tuesday' => 'Martes',
                'Wednesday' => 'Miércoles',
                'Thursday' => 'Jueves',
                'Friday' => 'Viernes',
                'Saturday' => 'Sábado'
            );
            
            $month_names = array(
                'January' => 'Enero',
                'February' => 'Febrero',
                'March' => 'Marzo',
                'April' => 'Abril',
                'May' => 'Mayo',
                'June' => 'Junio',
                'July' => 'Julio',
                'August' => 'Agosto',
                'September' => 'Septiembre',
                'October' => 'Octubre',
                'November' => 'Noviembre',
                'December' => 'Diciembre'
            );
            
            $day = $day_names[$dt->format('l')];
            $date_num = $dt->format('d');
            $month = $month_names[$dt->format('F')];
            $year = $dt->format('Y');
            
            return "$day, $date_num de $month de $year";
        } catch (Exception $e) {
            return $date;
        }
    }

    /**
     * Convertir fecha de un timezone a otro
     */
    public function convert_timezone($datetime, $from_timezone, $to_timezone) {
        try {
            $dt = new DateTime($datetime, new DateTimeZone($from_timezone));
            $dt->setTimezone(new DateTimeZone($to_timezone));
            return $dt;
        } catch (Exception $e) {
            return new DateTime($datetime);
        }
    }

    /**
     * Agregar minutos a una hora
     */
    public function add_minutes($time, $minutes) {
        $parts = explode(':', $time);
        $hours = intval($parts[0]);
        $mins = intval($parts[1]) + intval($minutes);
        
        // Manejar overflow
        while ($mins >= 60) {
            $hours++;
            $mins -= 60;
        }
        
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Calcular diferencia en horas entre dos fechas
     */
    public function diff_in_hours($datetime1, $datetime2, $timezone = 'UTC') {
        try {
            $dt1 = new DateTime($datetime1, new DateTimeZone($timezone));
            $dt2 = new DateTime($datetime2, new DateTimeZone($timezone));
            
            $diff = $dt2->getTimestamp() - $dt1->getTimestamp();
            return $diff / 3600;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Validar formato de fecha
     */
    public function is_valid_date($date, $format = 'Y-m-d') {
        $dt = DateTime::createFromFormat($format, $date);
        return $dt && $dt->format($format) === $date;
    }

    /**
     * Validar formato de hora
     */
    public function is_valid_time($time) {
        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
    }

    /**
     * Obtener lista de zonas horarias
     */
    public function get_timezones() {
        $timezones = DateTimeZone::listIdentifiers();
        $timezone_list = array();
        
        foreach ($timezones as $timezone) {
            $timezone_list[$timezone] = str_replace('_', ' ', $timezone);
        }
        
        return $timezone_list;
    }

    /**
     * Normalizar fecha a formato YYYY-MM-DD
     */
    public function normalize_date($date) {
        // Si ya está en formato correcto
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // Si está en formato DD-MM-YYYY
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $matches)) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }
        
        // Intentar parsear con DateTime
        try {
            $dt = new DateTime($date);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return $date;
        }
    }

    /**
     * Normalizar hora a formato HH:MM
     */
    public function normalize_time($time) {
        // Limpiar AM/PM si existe
        $time = preg_replace('/\s*[aApP][mM].*$/', '', $time);
        
        // Si ya está en formato correcto
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        
        // Si está en formato H:MM
        if (preg_match('/^(\d{1}):(\d{2})$/', $time, $matches)) {
            return sprintf('%02d:%s', $matches[1], $matches[2]);
        }
        
        return $time;
    }
}
