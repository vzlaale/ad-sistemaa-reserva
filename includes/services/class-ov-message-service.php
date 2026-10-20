<?php
/**
 * Servicio para generar mensajes.
 *
 * @package OV_Booking_System
 */

class OV_Message_Service {

    /**
     * Generar mensaje de confirmación.
     *
     * @param array $booking
     * @return string
     */
    public function booking_confirmation($booking) {

        $message = "Hola {$booking['customer_name']} 👋\n\n";

        $message .= "Tu reserva ha sido registrada correctamente.\n\n";

        $message .= "📅 Fecha: {$booking['date']}\n";
        $message .= "🕒 Hora: {$booking['time']}\n";
        $message .= "💼 Servicio: {$booking['service_name']}\n";

        if (!empty($booking['professional_name'])) {
            $message .= "👤 Profesional: {$booking['professional_name']}\n";
        }

        if (!empty($booking['price'])) {
            $message .= "💰 Valor: {$booking['price']}\n";
        }

        $message .= "\nMuchas gracias.";

        return $message;
    }

    /**
     * Generar mensaje de recordatorio.
     *
     * @param array $booking
     * @return string
     */
    public function booking_reminder($booking) {

        $message = "Hola {$booking['customer_name']} 👋\n\n";

        $message .= "Te recordamos que tienes una cita agendada para hoy.\n\n";

        $message .= "📅 Fecha: {$booking['date']}\n";
        $message .= "🕒 Hora: {$booking['time']}\n";
        $message .= "💼 Servicio: {$booking['service_name']}\n";

        if (!empty($booking['professional_name'])) {
            $message .= "👤 Profesional: {$booking['professional_name']}\n";
        }

        $message .= "\n";

        $message .= "Por favor responde a este mensaje para confirmar tu asistencia.\n\n";

        $message .= "Muchas gracias.";

        return $message;
    }

}