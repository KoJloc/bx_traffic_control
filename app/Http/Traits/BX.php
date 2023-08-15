<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Log;

/**
 * @version 1.36
 *  define:
 *      C_REST_WEB_HOOK_URL = 'https://rest-api.bitrix24.com/rest/1/doutwqkjxgc3mgc1/'  //url on creat Webhook
 *      or
 *      C_REST_CLIENT_ID = 'local.5c8bb1b0891cf2.87252039' //Application ID
 *      C_REST_CLIENT_SECRET = 'SakeVG5mbRdcQet45UUrt6q72AMTo7fkwXSO7Y5LYFYNCRsA6f'//Application key
 *
 *        C_REST_CURRENT_ENCODING = 'windows-1251'//set current encoding site if encoding unequal UTF-8 to use iconv()
 *      C_REST_BLOCK_LOG = true //turn off default logs
 *      C_REST_LOGS_DIR = __DIR__ .'/logs/' //directory path to save the log
 *      C_REST_LOG_TYPE_DUMP = true //logs save var_export for viewing convenience
 *      static::$C_REST_IGNORE_SSL = true //turn off validate ssl by curl
 */
trait BX
{
    static string $VERSION = 'chat bot';
    static int $BATCH_COUNT = 50;//count batch 1 query
    static string $TYPE_TRANSPORT = 'json';// json or xml

    // static $C_REST_CURRENT_ENCODING = 'unicode'; //set current encoding site if encoding unequal UTF-8 to use iconv()
    static bool $C_REST_BLOCK_LOG = true; //turn off default logs
//    static $C_REST_LOGS_DIR = __DIR__ .'/logs/'; //directory path to save the log
    static bool $C_REST_LOG_TYPE_DUMP = true; //logs save var_export for viewing convenience
    static bool $C_REST_IGNORE_SSL = false; //turn off validate ssl by curl

    static string $C_REST_WEB_HOOK_URL = '';  //url on creat Webhook

    //VREZERV
    static string $C_REST_CLIENT_ID = 'local.64d4badd779dc0.35926391'; //Application ID
    static string $C_REST_CLIENT_SECRET = 'drIpdcsmoTM08ozOxz4DNLfqs8YJUVgDWoHVfVw2YMn073rF51';//Application key
    
    protected static array $dataExt = [
        'access_token' => '',
        'domain' => '',
        'refresh_token' => '',
        'application_token' => '',
        'client_endpoint' => ''
    ];

    /**
     * call where install application even url
     * only for rest application, not webhook
     */

    public static function installApp(): array
    {
        $result = [
            'rest_only' => true,
            'install' => false
        ];

        if (isset($_REQUEST['event']) && $_REQUEST['event'] == 'ONAPPINSTALL' && !empty($_REQUEST['auth'])) {
            $result['install'] = static::setAppSettings($_REQUEST['auth'], true);
        } elseif ($_REQUEST['PLACEMENT'] == 'DEFAULT') {
            $result['rest_only'] = false;
            $result['install'] = static::setAppSettings(
                [
                    'access_token' => htmlspecialchars($_REQUEST['AUTH_ID']),
                    'expires_in' => htmlspecialchars($_REQUEST['AUTH_EXPIRES']),
                    'application_token' => htmlspecialchars($_REQUEST['APP_SID']),
                    'refresh_token' => htmlspecialchars($_REQUEST['REFRESH_ID']),
                    'domain' => htmlspecialchars($_REQUEST['DOMAIN']),
                    'client_endpoint' => 'https://' . htmlspecialchars($_REQUEST['DOMAIN']) . '/rest/',
                ],
                true
            );
        }

        static::setLog(
            [
                'request' => $_REQUEST,
                'result' => $result
            ],
            'installApp'
        );
        return $result;
    }

    /**
     * @return mixed array|string|boolean curl-return or error
     *
     * @var $arParams array
     * $arParams = [
     *      'method'    => 'some rest method',
     *      'params'    => []//array params of method
     * ];
     */
    protected static function callCurl(array $arParams): mixed
    {
        if (!function_exists('curl_init')) {
            return [
                'error' => 'error_php_lib_curl',
                'error_information' => 'need install curl lib'
            ];
        }
        $arSettings = static::getAppSettings();

        if ($arSettings !== false) {
            if (isset($arParams['this_auth']) && $arParams['this_auth'] == 'Y') {
                $url = 'https://oauth.bitrix.info/oauth/token/';
            } else {
                $url = $arSettings["client_endpoint"] . $arParams['method'] . '.' . static::$TYPE_TRANSPORT;
                if (empty($arSettings['is_web_hook']) || $arSettings['is_web_hook'] != 'Y') {
                    $arParams['params']['auth'] = $arSettings['access_token'];
                }
            }
            $sPostFields = http_build_query($arParams['params']);
            try {
                $obCurl = curl_init();
                curl_setopt($obCurl, CURLOPT_URL, $url);
                curl_setopt($obCurl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($obCurl, CURLOPT_POSTREDIR, 10);
                curl_setopt($obCurl, CURLOPT_TIMEOUT, 600);
                curl_setopt($obCurl, CURLOPT_CONNECTTIMEOUT, 600);
                curl_setopt($obCurl, CURLOPT_USERAGENT, 'Bitrix24 CRest PHP ' . static::$VERSION);
                if ($sPostFields) {
                    curl_setopt($obCurl, CURLOPT_POST, true);
                    curl_setopt($obCurl, CURLOPT_POSTFIELDS, $sPostFields);
                }
                curl_setopt(
                    $obCurl, CURLOPT_FOLLOWLOCATION, (isset($arParams['followlocation']))
                    ? $arParams['followlocation'] : 1
                );
                if (defined("C_REST_IGNORE_SSL") && static::$C_REST_IGNORE_SSL === true) {
                    curl_setopt($obCurl, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($obCurl, CURLOPT_SSL_VERIFYHOST, false);
                }

                $out = curl_exec($obCurl);
                $info = curl_getinfo($obCurl);

                if (curl_errno($obCurl)) {
                    $info['curl_error'] = curl_error($obCurl);
                }
                if (static::$TYPE_TRANSPORT == 'xml' && (!isset($arParams['this_auth']) || $arParams['this_auth'] != 'Y'))//auth only json support
                {
                    $result = $out;
                } else {
                    $result = static::expandData($out);
                }
                curl_close($obCurl);

                if (!empty($result['error'])) {
                    if ($result['error'] == 'expired_token' && empty($arParams['this_auth'])) {
                        $result = static::GetNewAuth($arParams);
                    } else {
                        $arErrorInform = [
                            'expired_token' => 'expired token, cant get new auth? Check access oauth server.',
                            'invalid_token' => 'invalid token, need reinstall application',
                            'invalid_grant' => 'invalid grant, check out define C_REST_CLIENT_SECRET or C_REST_CLIENT_ID',
                            'invalid_client' => 'invalid client, check out define C_REST_CLIENT_SECRET or C_REST_CLIENT_ID',
                            'QUERY_LIMIT_EXCEEDED' => 'Too many requests, maximum 2 query by second',
                            'ERROR_METHOD_NOT_FOUND' => 'Method not found! You can see the permissions of the application: CRest::call(\'scope\')',
                            'NO_AUTH_FOUND' => 'Some setup error b24, check in table "b_module_to_module" event "OnRestCheckAuth"',
                            'INTERNAL_SERVER_ERROR' => 'Server down, try later'
                        ];
                        if (!empty($arErrorInform[$result['error']])) {
                            $result['error_information'] = $arErrorInform[$result['error']];
                        }
                    }
                }
                if (!empty($info['curl_error'])) {
                    $result['error'] = 'curl_error';
                    $result['error_information'] = $info['curl_error'];
                }

                static::setLog(
                    [
                        'url' => $url,
                        'info' => $info,
                        'params' => $arParams,
                        'result' => $result
                    ],
                    'callCurl'
                );

                return $result;
            } catch (\Exception $e) {
                static::setLog(
                    [
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'trace' => $e->getTrace(),
                        'params' => $arParams
                    ],
                    'exceptionCurl'
                );

                return [
                    'error' => 'exception',
                    'error_exception_code' => $e->getCode(),
                    'error_information' => $e->getMessage(),
                ];
            }
        } else {
            static::setLog(
                [
                    'params' => $arParams
                ],
                'emptySetting'
            );
        }

        return [
            'error' => 'no_install_app',
            'error_information' => 'error install app, pls install local application '
        ];
    }

    /**
     * Generate a request for callCurl()
     *
     * @return mixed array|string|boolean curl-return or error
     * @var $params array
     * @var $method string
     */

    public static function call(string $method, array $params = []): mixed
    {
        $arPost = [
            'method' => $method,
            'params' => $params
        ];

        if (isset(static::$C_REST_CURRENT_ENCODING)) {
            $arPost['params'] = static::changeEncoding($arPost['params']);
        }

        return static::callCurl($arPost);
    }

    /**
     * @return array
     *
     * @var $arData array
     * @var $halt   integer 0 or 1 stop batch on error
     * @example $arData:
     * $arData = [
     *      'find_contact' => [
     *          'method' => 'crm.duplicate.findbycomm',
     *          'params' => [ "entity_type" => "CONTACT",  "type" => "EMAIL", "values" => array("info@bitrix24.com") ]
     *      ],
     *      'get_contact' => [
     *          'method' => 'crm.contact.get',
     *          'params' => [ "id" => '$result[find_contact][CONTACT][0]' ]
     *      ],
     *      'get_company' => [
     *          'method' => 'crm.company.get',
     *          'params' => [ "id" => '$result[get_contact][COMPANY_ID]', "select" => ["*"],]
     *      ]
     * ];
     *
     */

    public static function callBatch($arData, $halt = 0): array
    {
        $arResult = [];
        if (is_array($arData)) {
            if (isset(static::$C_REST_CURRENT_ENCODING)) {
                $arData = static::changeEncoding($arData);
            }
            $arDataRest = [];
            $i = 0;
            foreach ($arData as $key => $data) {
                if (!empty($data['method'])) {
                    $i++;
                    if (static::$BATCH_COUNT >= $i) {
                        $arDataRest['cmd'][$key] = $data['method'];
                        if (!empty($data['params'])) {
                            $arDataRest['cmd'][$key] .= '?' . http_build_query($data['params']);
                        }
                    }
                } else {
                    Log::build([
                        'driver' => 'single',
                        'path' => storage_path('logs/CRest.log')
                    ])->info('callBatch (!empty($data[method]) error: ', [$data]);
                }
            }
            if (!empty($arDataRest)) {
                $arDataRest['halt'] = $halt;
                $arPost = [
                    'method' => 'batch',
                    'params' => $arDataRest
                ];
                $arResult = static::callCurl($arPost);
            } else {
                Log::build([
                    'driver' => 'single',
                    'path' => storage_path('logs/CRest.log')
                ])->info('callBatch (!empty($arDataRest) error: ', [$arDataRest]);
            }
        }
        return $arResult;
    }

    public static function firstBatch($method, $params = [], $array_values = false, $show = true): array
    {
        $start = 0;
        $arParams = [];
        $return_data = [];

        $review_request_first = static::call($method, $params);

        if (array_key_exists('total', $review_request_first) and $review_request_first['total'] >= 50) {
            if ($show && $method != 'department.get') Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/CRest.log')
            ])->info($method . " Всего: " . $review_request_first['total']);

            if ($review_request_first['total'] > 2500) {
                for ($mass = 0; $mass < ceil($review_request_first['total'] / 2500); $mass++) {
                    for ($i = 0; $i < ceil(($review_request_first['total'] - $mass * 2500) / 50); $i++) {
                        if ($i >= 50) break;

                        $arParams['statistic_' . ($i + 1)] = [
                            'method' => $method,
                            'params' => array_merge($params, ['start' => $start])
                        ];

                        $start += 50;
                    }

                    if ($show) Log::build([
                        'driver' => 'single',
                        'path' => storage_path('logs/CRest.log')
                    ])->info($start);

                    $review_request = static::callBatch($arParams);

                    if (is_array($review_request) and array_key_exists('result', $review_request) and array_key_exists('result', $review_request['result'])) {
                        foreach ($review_request['result']['result'] as $stats) {
                            if ($method == 'crm.stagehistory.list') {
                                foreach ($stats['items'] as $stat) {
                                    $return_data[$stat['ID']] = $stat;
                                }
                            } elseif ($method == 'tasks.task.list') {
                                foreach ($stats['tasks'] as $stat) {
                                    $return_data[$stat['id']] = $stat;
                                }
                            } else {
                                foreach ($stats as $stat) {
                                    if (array_key_exists('ID', $stat)) {
                                        $return_data[$stat['ID']] = $stat;
                                    } elseif (array_key_exists('ANCHOR_ID', $stat)) {
                                        $return_data[$stat['ANCHOR_ID'] . '_' . $stat['TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID']] = $stat;
                                    } else {
                                        //Вывод в логи если отсутствует ID или ANCHOR ID
                                        Log::build([
                                            'driver' => 'single',
                                            'path' => storage_path('logs/CRest.log')
                                        ])->info('$review_request error', [$stat]);
                                    };
                                }
                            }
                        }
                    } else {
                        if ($method != 'department.get') {
                            Log::build([
                                'driver' => 'single',
                                'path' => storage_path('logs/CRest.log')
                            ])->info($review_request);
                        }
                    }
                }
            } else {
                for ($i = 0; $i < ceil($review_request_first['total'] / 50); $i++) {
                    $arParams['statistic_' . ($i + 1)] = [
                        'method' => $method,
                        'params' => array_merge($params, ['start' => $start])
                    ];

                    $start += 50;
                }

                if ($show && $method != 'department.get') Log::build([
                    'driver' => 'single',
                    'path' => storage_path('logs/CRest.log')
                ])->info($start);

                $review_request = static::callBatch($arParams);

                if (is_array($review_request) and array_key_exists('result', $review_request) and array_key_exists('result', $review_request['result'])) {
                    foreach ($review_request['result']['result'] as $stats) {
                        if ($method == 'crm.stagehistory.list') {
                            foreach ($stats['items'] as $stat) {
                                $return_data[$stat['ID']] = $stat;
                            }
                        } elseif ($method == 'tasks.task.list') {
                            foreach ($stats['tasks'] as $stat) {
                                $return_data[$stat['id']] = $stat;
                            }
                        } else {
                            foreach ($stats as $stat) {
                                if (array_key_exists('ID', $stat)) {
                                    $return_data[$stat['ID']] = $stat;
                                } elseif (array_key_exists('ANCHOR_ID', $stat)) {
                                    $return_data[$stat['ANCHOR_ID'] . '_' . $stat['TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID']] = $stat;
                                };
                            }
                        }
                    }
                } else {
                    if ($method != 'department.get') {
                        Log::build([
                            'driver' => 'single',
                            'path' => storage_path('logs/CRest.log')
                        ])->info($review_request);
                    };
                }
            };
        } else {
            if ($show && $method != 'department.get') Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/CRest.log')
            ])->info("Всего: " . count($review_request_first['result']));

            if ($method == 'tasks.task.list') {
                if (!empty ($review_request_first['result']['tasks'])) {
                    $review_request_first = $review_request_first['result']['tasks'];
                } else {
                    return $review_request_first['result'];
                }
            } else {
                return $review_request_first['result'];
            }
            foreach ($review_request_first as $stat) {
                if (array_key_exists('ID', $stat)) {
                    $return_data[$stat['ID']] = $stat;
                } elseif (array_key_exists('id', $stat)) {
                    $return_data[$stat['id']] = $stat;
                } elseif (array_key_exists('ANCHOR_ID', $stat)) {
                    $return_data[$stat['ANCHOR_ID'] . '_' . $stat['TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID'] . '_' . $stat['ENTITY_TYPE_ID']] = $stat;
                };
            }
        };
        return $array_values ? array_values($return_data) : $return_data;
    }

    private static function GetNewAuth($arParams)
    {
        $result = [];
        $arSettings = static::getAppSettings();

        if ($arSettings !== false) {
            $arParamsAuth = [
                'this_auth' => 'Y',
                'params' =>
                    [
                        'client_id' => $arSettings['C_REST_CLIENT_ID'],
                        'grant_type' => 'refresh_token',
                        'client_secret' => $arSettings['C_REST_CLIENT_SECRET'],
                        'refresh_token' => $arSettings["refresh_token"],
                    ]
            ];

            $newData = static::callCurl($arParamsAuth);
            if (isset($newData['C_REST_CLIENT_ID'])) {
                unset($newData['C_REST_CLIENT_ID']);
            }
            if (isset($newData['C_REST_CLIENT_SECRET'])) {
                unset($newData['C_REST_CLIENT_SECRET']);
            }
            if (isset($newData['error'])) {
                unset($newData['error']);
            }
            if (static::setAppSettings($newData)) {
                $arParams['this_auth'] = 'N';
                $result = static::callCurl($arParams);
            }
        }
        return $result;
    }

    /**
     * @return boolean
     * @var $isInstall  boolean true if install app by installApp()
     * @var $arSettings array settings application
     */

    private static function setAppSettings($arSettings, $isInstall = false)
    {
        $return = false;
        if (is_array($arSettings)) {
            $oldData = static::getAppSettings();
            if ($isInstall != true && !empty($oldData) && is_array($oldData)) {
                $arSettings = array_merge($oldData, $arSettings);
            }
            $return = static::setSettingData($arSettings);
        }
        return $return;
    }

    /**
     * @return mixed setting application for query
     */

    private static function getAppSettings()
    {
        if (isset(static::$C_REST_WEB_HOOK_URL) && !empty(static::$C_REST_WEB_HOOK_URL)) {
            $arData = [
                'client_endpoint' => static::$C_REST_WEB_HOOK_URL,
                'is_web_hook' => 'Y'
            ];
            $isCurrData = true;
        } else {
            $arData = static::getSettingData();

            $isCurrData = false;
            if (
                !empty($arData['access_token']) &&
                !empty($arData['domain']) &&
                !empty($arData['refresh_token']) &&
                !empty($arData['application_token']) &&
                !empty($arData['client_endpoint'])
            ) {
                $isCurrData = true;
            }
        }

        return ($isCurrData) ? $arData : false;
    }

    /**
     * Can overridden this method to change the data storage location.
     *
     * @return array setting for getAppSettings()
     */

    protected static function getSettingData()
    {
        $return = [];
        if (!empty(static::$dataExt['access_token'])) {
            $return = [
                'access_token' => static::$dataExt['access_token'],
                'domain' => static::$dataExt['domain'],
                'client_endpoint' => static::$dataExt['client_endpoint'],
                'refresh_token' => static::$dataExt['refresh_token'],
                'application_token' => static::$dataExt['application_token']
            ];
        } else if (file_exists(__DIR__ . '/settings.json')) {
            $return = static::expandData(file_get_contents(__DIR__ . '/settings.json'));

            if (isset(static::$C_REST_CLIENT_ID) && !empty(static::$C_REST_CLIENT_ID)) {
                $return['C_REST_CLIENT_ID'] = static::$C_REST_CLIENT_ID;
            }
            if (isset(static::$C_REST_CLIENT_SECRET) && !empty(static::$C_REST_CLIENT_SECRET)) {
                $return['C_REST_CLIENT_SECRET'] = static::$C_REST_CLIENT_SECRET;
            }
        }
        return $return;
    }

    /**
     * @return string json_encode with encoding
     * @var $encoding boolean true - encoding to utf8, false - decoding
     *
     * @var $data mixed
     */
    protected static function changeEncoding($data, $encoding = true)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $k => $item) {
                $k = static::changeEncoding($k, $encoding);
                $result[$k] = static::changeEncoding($item, $encoding);
            }
        } else {
            if ($encoding) {
                $result = iconv(static::$C_REST_CURRENT_ENCODING, "UTF-8//TRANSLIT", $data);
            } else {
                $result = iconv("UTF-8", static::$C_REST_CURRENT_ENCODING, $data);
            }
        }

        return $result;
    }

    /**
     * @return string json_encode with encoding
     * @var $debag boolean
     *
     * @var $data mixed
     */
    protected static function wrapData($data, $debag = false)
    {
        if (isset(static::$C_REST_CURRENT_ENCODING)) {
            $data = static::changeEncoding($data, true);
        }
        $return = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($debag) {
            $e = json_last_error();
            if ($e != JSON_ERROR_NONE) {
                if ($e == JSON_ERROR_UTF8) {
                    return 'Failed encoding! Recommended \'UTF - 8\' or set define C_REST_CURRENT_ENCODING = current site encoding for function iconv()';
                }
            }
        }

        return $return;
    }

    /**
     * @return string json_decode with encoding
     * @var $debag boolean
     *
     * @var $data mixed
     */
    protected static function expandData($data)
    {
        $return = json_decode($data, true);

        if (isset(static::$C_REST_CURRENT_ENCODING)) {
            $return = static::changeEncoding($return, false);
        }
        return $return;
    }

    /**
     * Can overridden this method to change the data storage location.
     *
     * @return boolean is successes save data for setSettingData()
     * @var $arSettings array settings application
     */

    protected static function setSettingData($arSettings)
    {
        return (boolean)file_put_contents(__DIR__ . '/settings.json', static::wrapData($arSettings));
    }

    /**
     * Can overridden this method to change the log data storage location.
     *
     * @return boolean is successes save log data
     * @var $type   string to more identification log data
     * @var $arData array of logs data
     */

    public static function setLog($arData, $type = '')
    {
        $return = false;
        if (!isset(static::$C_REST_BLOCK_LOG) || static::$C_REST_BLOCK_LOG !== true) {
            if (isset(static::$C_REST_LOGS_DIR)) {
                $path = static::$C_REST_LOGS_DIR;
            } else {
                $path = storage_path('logs/bitrix/');
            }

            $path .= date("Y-m-d/H") . '/';

            if (!file_exists($path)) {
                @mkdir($path, 0775, true);
            }

            $path .= time() . '_' . $type . '_' . rand(1, 9999999) . 'log';

            if (!isset(static::$C_REST_LOG_TYPE_DUMP) || static::$C_REST_LOG_TYPE_DUMP !== true) {
                $jsonLog = static::wrapData($arData);
                if ($jsonLog === false) {
                    $return = file_put_contents($path . '_backup.txt', var_export($arData, true));
                } else {
                    $return = file_put_contents($path . '.json', $jsonLog);
                }
            } else {
                $return = file_put_contents($path . '.txt', var_export($arData, true));
            }
        }
        return $return;
    }

    /**
     * check minimal settings server to work CRest
     * @return array of errors
     * @var $print boolean
     */
    public static function checkServer($print = true)
    {
        $return = [];

        //check curl lib install
        if (!function_exists('curl_init')) {
            $return['curl_error'] = 'Need install curl lib.';
        }

        //creat setting file
        file_put_contents(__DIR__ . '/settings_check.json', static::wrapData(['test' => 'data']));
        if (!file_exists(__DIR__ . '/settings_check.json')) {
            $return['setting_creat_error'] = 'Check permission! Recommended: folders: 775, files: 664';
        }
        unlink(__DIR__ . '/settings_check.json');
        //creat logs folder and files
        $path = __DIR__ . '/logs/' . date("Y-m-d/H") . '/';
        if (!mkdir($path, 0775, true) && !file_exists($path)) {
            $return['logs_folder_creat_error'] = 'Check permission! Recommended: folders: 775, files: 664';
        } else {
            file_put_contents($path . 'test.txt', var_export(['test' => 'data'], true));
            if (!file_exists($path . 'test.txt')) {
                $return['logs_file_creat_error'] = 'check permission! recommended: folders: 775, files: 664';
            }
            unlink($path . 'test.txt');
        }

        if ($print === true) {
            if (empty($return)) {
                $return['success'] = 'Success!';
            }
            echo '<pre>';
            print_r($return);
            echo '</pre>';

        }

        return $return;
    }

    public static function setDataExt($request)
    {
        static::$dataExt = [
            'access_token' => $request->get("auth")["ACCESS_TOKEN"],
            'domain' => $request->get("auth")["DOMAIN"],
            'refresh_token' => $request->get("auth")["REFRESH_TOKEN"],
            'application_token' => $request->get("sid")["APP_SID"],
            'client_endpoint' => 'https://' . htmlspecialchars($request->get("auth")["DOMAIN"]) . '/rest/'
        ];
    }

    public static function setDataE($request)
    {
        static::$dataExt = [
            // 'access_token' => htmlspecialchars($_REQUEST['AUTH_ID']),
            // 'expires_in' => htmlspecialchars($_REQUEST['AUTH_EXPIRES']),
            // 'application_token' => htmlspecialchars($_REQUEST['APP_SID']),
            // 'refresh_token' => htmlspecialchars($_REQUEST['REFRESH_ID']),
            // 'domain' => htmlspecialchars($_REQUEST['DOMAIN']),
            // 'client_endpoint' => 'https://' . htmlspecialchars($_REQUEST['DOMAIN']) . '/rest/',

            'access_token' => $request["AUTH_ID"],
            'domain' => $request["DOMAIN"],
            'refresh_token' => $request["REFRESH_ID"],
            'application_token' => $request["APP_SID"],
            'client_endpoint' => 'https://' . htmlspecialchars($request["DOMAIN"]) . '/rest/'
        ];
    }
}
