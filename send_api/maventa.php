<?php
/**
 * Maventa REST API client
 *
 * PHP version 8
 *
 * Copyright (C) Ere Maijala 2018-2026
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category MLInvoice
 * @package  MLInvoice\Base
 * @author   Ere Maijala <ere@labs.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://labs.fi/mlinvoice.eng.php
 */
require_once 'sqlfuncs.php';
require_once 'miscfuncs.php';
require_once 'crypt.php';
require_once 'settings.php';

/**
 * Client for Maventa REST API
 *
 * @category MLInvoice
 * @package  MLInvoice\Base
 * @author   Ere Maijala <ere@labs.fi>
 * @license  https://opensource.org/licenses/GPL-2.0 GNU Public License 2.0
 * @link     http://github.com/emaijala/MLInvoice
 */
class Maventa
{
    /**
     * API config
     *
     * @var array
     */
    protected $apiConfig;

    /**
     * Initialization
     *
     * @param array $apiConfig API config
     *
     * @return void
     */
    public function init($apiConfig)
    {
        $this->apiConfig = $apiConfig;
    }

    /**
     * Send a printout
     *
     * @param array $invoice  Invoice data
     * @param array $template Print template
     *
     * @return array Keyed array with results (success, message)
     */
    public function send($invoice, $template)
    {
        $credentials = $this->getCredentials();

        if (empty($credentials['client_id'])) {
            return [
                'success' => false,
                'message' => 'Maventa client_id (Company UUID) missing from settings'
            ];
        }
        if (empty($credentials['client_secret'])) {
            return [
                'success' => false,
                'message' => 'Maventa client_secret (User API key) missing from settings'
            ];
        }
        if (empty($credentials['vendor_api_key'])) {
            return [
                'success' => false,
                'message' => 'Maventa vendor_api_key missing from settings'
            ];
        }

        $printer = getInvoicePrinter($template['filename']);
        if (!$printer instanceof InvoicePrinterFinvoice) {
            return [
                'success' => false,
                'message' => 'Maventa send requires a Finvoice XML print template'
            ];
        }

        $printer->init(
            $invoice['id'],
            $template['parameters'],
            $template['output_filename'],
            0,
            $template['id'],
            true
        );
        $printout = $printer->createPrintout();

        $httpClient = $this->createHttpClient();
        if (!$httpClient) {
            return [
                'success' => false,
                'message' => 'HTTP client not available (Guzzle missing)'
            ];
        }
        $filename = $printout['filename'] ? basename($printout['filename']) : 'invoice.xml';

        $environment = $this->getEnvironmentConfig();

        $tokenResult = $this->requestToken(
            $httpClient,
            $environment['url'],
            $credentials['client_id'],
            $credentials['client_secret'],
            $credentials['vendor_api_key']
        );
        if (!$tokenResult['success']) {
            return [
                'success' => false,
                'message' => 'Maventa auth failed in ' . $environment['name'] . ' environment: '
                    . $tokenResult['message']
            ];
        }

        return $this->sendInvoice(
            $httpClient,
            $environment['url'],
            $tokenResult['token'],
            $printout['data'],
            $filename
        );
    }

    /**
     * Request OAuth token
     *
    * @param object $httpClient   HTTP client
    * @param string $baseUrl      API base URL
    * @param string $clientId     Client ID
    * @param string $clientSecret Client secret
    * @param string $vendorApiKey Vendor API key
     *
     * @return array
     */
    protected function requestToken($httpClient, $baseUrl, $clientId, $clientSecret, $vendorApiKey)
    {
        try {
            $response = $httpClient->post(
                $baseUrl . '/oauth2/token',
                [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'vendor_api_key' => $vendorApiKey,
                    ],
                ]
            );
        } catch (Exception $e) {
            [$statusCode, $body] = $this->extractHttpError($e);
            return [
                'success' => false,
                'auth_error' => in_array($statusCode, [400, 401, 403]),
                'message' => 'Token request failed: ' . ($statusCode ? $statusCode . ' ' : '') . $body,
            ];
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success' => false,
                'auth_error' => in_array($statusCode, [400, 401, 403]),
                'message' => 'Token request failed: ' . $statusCode . ' ' . $body,
            ];
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            return [
                'success' => false,
                'auth_error' => false,
                'message' => 'Token request failed: access_token missing from response',
            ];
        }

        return [
            'success' => true,
            'token' => $data['access_token'],
        ];
    }

    /**
     * Send invoice to Maventa
     *
    * @param object $httpClient HTTP client
    * @param string $baseUrl    API base URL
    * @param string $token      OAuth token
    * @param string $data       Invoice XML
    * @param string $filename   Filename
     *
     * @return array
     */
    protected function sendInvoice($httpClient, $baseUrl, $token, $data, $filename)
    {
        try {
            $response = $httpClient->post(
                $baseUrl . '/v1/invoices',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => $data,
                            'filename' => $filename,
                            'headers' => [
                                'Content-Type' => 'application/xml',
                            ],
                        ],
                        [
                            'name' => 'format',
                            'contents' => 'FINVOICE30',
                        ],
                    ],
                ]
            );
        } catch (Exception $e) {
            [$statusCode, $body] = $this->extractHttpError($e);
            return [
                'success' => false,
                'auth_error' => in_array($statusCode, [401, 403]),
                'message' => 'Invoice was not sent. Error: '
                    . ($statusCode ? $statusCode . ' ' : '') . $body,
            ];
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success' => false,
                'auth_error' => in_array($statusCode, [401, 403]),
                'message' => 'Invoice was not sent. Error: ' . $statusCode . ' ' . $body,
            ];
        }

        $responseData = json_decode($body, true);
        $message = 'Invoice was accepted by Maventa API';
        if (!empty($responseData['id'])) {
            $message .= ' (ID: ' . $responseData['id'] . ')';
        }

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * Extract status code and response body from an exception
     *
     * @param Exception $e Exception
     *
     * @return array
     */
    protected function extractHttpError($e)
    {
        $statusCode = 0;
        $body = $e->getMessage();

        if (method_exists($e, 'hasResponse')
            && method_exists($e, 'getResponse')
            && $e->hasResponse()
        ) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
        }

        return [$statusCode, $body];
    }

    /**
     * Create HTTP client instance
     *
     * @return object|null
     */
    protected function createHttpClient()
    {
        $clientClass = 'GuzzleHttp\\Client';
        if (!class_exists($clientClass)) {
            return null;
        }
        return new $clientClass($GLOBALS['mlinvoice_http_config'] ?? []);
    }

    /**
     * Resolve credentials from send_api_config first and settings table as fallback
     *
     * @return array
     */
    protected function getCredentials()
    {
        $clientId = trim((string)($this->apiConfig['username'] ?? ''));
        $password = trim((string)($this->apiConfig['password'] ?? ''));
        $vendorApiKey = trim((string)($this->apiConfig['reference'] ?? ''));

        $environment = $this->getEnvironmentConfig()['name'];
        $suffix = 'production' === $environment ? 'production' : 'staging';

        if (!$clientId) {
            $clientId = trim((string)getSetting('maventa_company_id_' . $suffix, ''));
            if (!$clientId) {
                $clientId = trim((string)getSetting('maventa_company_id', ''));
            }
        }
        if (!$password) {
            $password = trim((string)getSetting('maventa_company_api_key_' . $suffix . '_encrypted', ''));
            if (!$password) {
                $password = trim((string)getSetting('maventa_company_api_key_encrypted', ''));
            }
            if (!$password) {
                $password = trim((string)getSetting('maventa_company_api_key', ''));
            }
        }
        if (!$vendorApiKey) {
            $vendorApiKey = trim((string)getSetting('maventa_vendor_api_key_' . $suffix . '_encrypted', ''));
            if (!$vendorApiKey) {
                $vendorApiKey = trim((string)getSetting('maventa_vendor_api_key_encrypted', ''));
            }
            if (!$vendorApiKey) {
                $vendorApiKey = trim((string)getSetting('maventa_vendor_api_key', ''));
            }
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $this->decodeSecret($password),
            'vendor_api_key' => $vendorApiKey,
        ];
    }

    /**
     * Decode secret from encrypted value if possible, otherwise keep as-is
     *
     * @param string $secret Secret
     *
     * @return string
     */
    protected function decodeSecret($secret)
    {
        if (!$secret) {
            return '';
        }
        $crypt = new Crypt();
        try {
            return $crypt->decrypt($secret);
        } catch (Exception $e) {
            return $secret;
        }
    }

    /**
     * Resolve selected Maventa environment to API base URL
     *
     * @return array
     */
    protected function getEnvironmentConfig()
    {
        $selected = getSetting('maventa_environment', 'stage');
        if ('production' === $selected) {
            return [
                'name' => 'production',
                'url' => 'https://ax.maventa.com',
            ];
        }
        return [
            'name' => 'stage',
            'url' => 'https://ax-stage.maventa.com',
        ];
    }
}