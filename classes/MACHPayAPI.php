<?php
/*
 * Disclaimer:
 * Estas solicitudes se podrían hacer con Guzzle, pero según leí la versión que viene por defecto con PrestaShop 1.7 es una bastante desactualizada. Ergo, cURL
 */
class MACHPayAPI {
    public static function makePOSTRequest(string $endpoint, array $request_data) {
        $headers[] = 'Content-type: application/json';
        $headers[] = 'Authorization: Bearer ' . MACHPay::getConfiguration()['machpay_api_key'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, MACHPay::getConfiguration()['machpay_api_url'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop MACHPay/' . MACHPAY_VERSION);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));

        return MACHPayAPI::processcURLResponse($ch);
    }

    public static function makeGETRequest(string $endpoint) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . MACHPay::getConfiguration()['machpay_api_key']]);
        curl_setopt($ch, CURLOPT_URL, MACHPay::getConfiguration()['machpay_api_url'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop MACHPay/' . MACHPAY_VERSION);

        return MACHPayAPI::processcURLResponse($ch);
    }

    private static function processcURLResponse($curl_handle) {
        $response = curl_exec($curl_handle);

        $curl_error_code = curl_errno($curl_handle);
        $http_status_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $http_error = in_array(floor($http_status_code / 100), array(
            4,
            5
        ));

        $error = ! ($curl_error_code === 0) || $http_error;

        if ($error) {
            PrestaShopLogger::addLog('MACH Pay: error en llamada a la API: código de respuesta [' . $http_status_code . '] - cuerpo de la respuesta ['
                . print_r($response, true) . ']',
                3);

            return false;
        } else {
            return $response;
        }
    }
}