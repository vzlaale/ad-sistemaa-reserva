<?php
/**
 * Clase para interactuar con la base de datos
 * 
 * @package OV_Booking_System
 */

class OV_Database {

    /**
     * Instancia global de wpdb
     */
    private $wpdb;

    /**
     * Prefijo de las tablas
     */
    private $prefix;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'ov_booking_';
    }

    /**
     * Obtener nombre completo de tabla
     */
    public function get_table_name($table) {
        if (!is_string($table) || !preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid OV Booking table identifier.');
        }
        return $this->prefix . $table;
    }

    /**
     * Insertar registro
     */
    public function insert($table, $data) {
        $table_name = $this->get_table_name($table);
        $result = $this->wpdb->insert($table_name, $data);
        
        if ($result === false) {
            // No registrar la consulta: puede contener nombre, email, teléfono o notas.
            error_log('OV Booking DB insert failed for table ' . $table_name);
            return false;
        }
        
        return $this->wpdb->insert_id;
    }

    /**
     * Actualizar registro
     */
    public function update($table, $data, $where) {
        $table_name = $this->get_table_name($table);
        return $this->wpdb->update($table_name, $data, $where);
    }

    /**
     * Eliminar registro
     */
    public function delete($table, $where) {
        $table_name = $this->get_table_name($table);
        return $this->wpdb->delete($table_name, $where);
    }

    /**
     * Obtener un registro
     */
    public function get_row($table, $where = array(), $output = OBJECT) {
        $table_name = $this->get_table_name($table);
        
        if (empty($where)) {
            return $this->wpdb->get_row("SELECT * FROM $table_name", $output);
        }
        
        $where_clause = $this->build_where_clause($where);
        return $this->wpdb->get_row("SELECT * FROM $table_name WHERE $where_clause", $output);
    }

    /**
     * Obtener múltiples registros
     */
    public function get_results($table, $where = array(), $order_by = '', $output = OBJECT) {
        $table_name = $this->get_table_name($table);
        
        $sql = "SELECT * FROM $table_name";
        
        if (!empty($where)) {
            $where_clause = $this->build_where_clause($where);
            $sql .= " WHERE $where_clause";
        }
        
        if (!empty($order_by) && preg_match('/^[a-zA-Z0-9_]+\s+(ASC|DESC)(\s*,\s*[a-zA-Z0-9_]+\s+(ASC|DESC))*$/i', $order_by)) {
            $sql .= " ORDER BY $order_by";
        }
        
        return $this->wpdb->get_results($sql, $output);
    }

    /**
     * Contar registros
     */
    public function count($table, $where = array()) {
        $table_name = $this->get_table_name($table);
        
        $sql = "SELECT COUNT(*) FROM $table_name";
        
        if (!empty($where)) {
            $where_clause = $this->build_where_clause($where);
            $sql .= " WHERE $where_clause";
        }
        
        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Construir cláusula WHERE
     */
    private function build_where_clause($where) {
        $conditions = array();
        
        foreach ($where as $column => $value) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new InvalidArgumentException('Invalid OV Booking column identifier.');
            }
            if (is_null($value)) {
                $conditions[] = "`$column` IS NULL";
            } elseif (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '%s'));
                $conditions[] = $this->wpdb->prepare("`$column` IN ($placeholders)", $value);
            } else {
                $conditions[] = $this->wpdb->prepare("`$column` = %s", $value);
            }
        }
        
        return implode(' AND ', $conditions);
    }

}
