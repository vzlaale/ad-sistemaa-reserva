<?php
if (PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
require $argv[1];
if (!defined('OV_CLINICAL_TEST_DATABASE') || !OV_CLINICAL_TEST_DATABASE || wp_parse_url(home_url('/'), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Only disposable local WordPress test databases');
}
function license_expect($condition, $case) {
    if (!$condition) { throw new RuntimeException($case); }
    echo "OK $case\n";
}
$option = OV_Module_Manager::ENTITLEMENTS_OPTION;
$original = get_option($option);
$timezone = get_option('timezone_string');
wp_set_current_user(get_user_by('login', 'stage-admin')->ID);
try {
    if (OV_Module_Manager::license_mode() === 'local') {
        update_option('timezone_string', 'America/Santiago');
        license_expect(OV_Module_Manager::activate_local('clinical_records', '2027-01-15') === true, 'Activación local con zona America/Santiago');
        $entitlement = OV_Module_Manager::get_entitlement('clinical_records');
        license_expect($entitlement['expires_at'] === '2027-01-16 02:59:59', 'Vencimiento al final del día local guardado en UTC');
        foreach (array('site_url' => 'https://other.example.test/', 'instance_id' => 'other-instance', 'provider' => 'api') as $field => $wrong) {
            $values = get_option($option); $values['clinical_records'][$field] = $wrong; update_option($option, $values, false);
            license_expect(!OV_Module_Manager::is_enabled('clinical_records') && !OV_Module_Manager::is_readable('clinical_records'), 'Instalación incorrecta rechazada: ' . $field);
            $values['clinical_records'] = $entitlement; update_option($option, $values, false);
        }
        foreach (array('failed' => false, 'throwing' => true) as $kind => $throws) {
            $key = 'license_' . $kind . '_fixture';
            OV_Module_Manager::register(array('key' => $key, 'name' => 'Fixture', 'version' => '1.0', 'readonly_on_expiry' => true,
                'on_enable' => static function () use ($throws) { if ($throws) { throw new RuntimeException('Fixture'); } return new WP_Error('fixture', 'Fixture'); }));
            $before = get_option($option);
            license_expect(is_wp_error(OV_Module_Manager::activate_local($key, '2099-12-31')) && get_option($option) === $before
                && !OV_Module_Manager::is_readable($key), 'Instalación fallida no concede ni conserva licencia parcial: ' . $kind);
        }
    } else {
        $calls = 0;
        add_filter('ov_booking_license_api_url', static function () { return 'https://license-fixture.example.test/validate'; });
        add_filter('pre_http_request', static function ($pre, $request, $url) use (&$calls) {
            if ($url !== 'https://license-fixture.example.test/validate') { return $pre; }
            ++$calls;
            $data = json_decode($request['body'], true);
            license_expect(array_keys($data) === array('action','license_key','module','site_url','instance_id','plugin_version')
                && $data['action'] === 'activate' && $data['module'] === 'clinical_records', 'Contrato API JSON existente conservado');
            return array('response' => array('code' => 200), 'body' => wp_json_encode(array('valid' => true, 'expires_at' => '2099-12-31 23:59:59')));
        }, 10, 3);
        if (wp_get_environment_type() === 'production') {
            license_expect(is_wp_error(OV_Module_Manager::activate('clinical_records', 'TEST-CLINICAL-2026')) && $calls === 0,
                'Clave TEST rechazada en producción antes de contactar API');
            $values = $original; $values['clinical_records'] = array('status' => 'active', 'license_key' => 'TEST-CLINICAL-2026',
                'expires_at' => '2099-12-31 23:59:59', 'activated_once' => true); update_option($option, $values, false);
            license_expect(!OV_Module_Manager::is_enabled('clinical_records') && !OV_Module_Manager::is_readable('clinical_records'),
                'Cache de prueba no concede escritura ni lectura en producción');
        }
        license_expect(OV_Module_Manager::activate('clinical_records', 'fixture-api-valid') === true && OV_Module_Manager::is_enabled('clinical_records'),
            'Activación API anterior sigue disponible');
        $values = get_option($option); unset($values['clinical_records']['provider']); update_option($option, $values, false);
        license_expect(OV_Module_Manager::is_enabled('clinical_records'), 'Entitlement API heredado conserva compatibilidad');
    }
} finally {
    update_option($option, $original, false);
    update_option('timezone_string', $timezone);
}
echo "LICENSE CHECKS COMPLETE\n";
