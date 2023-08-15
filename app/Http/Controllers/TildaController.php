<?php

namespace App\Http\Controllers;

use App\Http\Traits\BX;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TildaController extends Controller
{
    use BX;

    private $types_dictionary = [
        'region' => [
            'обл',
            'Обл',
            'область',
            'Область',
        ],
        'edge' => [
            'край',
            'Край',
            'краи',
            'Краи',
        ],
        'republic' => [
            'республика',
            'Республика',
            'респ',
            'Респ',
        ],
        'district' => [
            'АО',
            'авт. округ',
            'автономный округ'
        ],
    ];

    private function determine_type($region_array): mixed
    {
        foreach ($region_array as $key => $array_part) {
            foreach ($this->types_dictionary as $type => $patterns) {
                if (in_array($array_part, $patterns)) {
                    return [
                        'index' => $key,
                        'type' => $type,
                    ];
                }
            }
        }
        return false;
    }

    private function get_value_from_list($prop_id)
    {
        $return = null;
        $params = [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => '16',
            'FIELD_ID' => 'PROPERTY_82',
        ];
        $bx_params = $this->call('lists.field.get', $params);

        if (isset($bx_params['result']) && isset(array_values($bx_params['result'])[0]['DISPLAY_VALUES_FORM'])) {
            $values = array_values($bx_params['result'])[0]['DISPLAY_VALUES_FORM'];

            foreach ($values as $key => $value) {
                if ($key == array_values($prop_id)[0]) {
                    $return = $value;
                }
            }
        }

        return $return;
    }

    public function tilda(Request $request)
    {
        $comment_string = '';
        $other_string = '';
        $url = 'https://nums.hanumi.net/api/get_info';
        $params = $request->all();

        if (isset($request['Phone'])) {
            foreach ($params as $question => $answer) {
                $normalized_string = preg_replace('/_+/', ' ', $question);

                if (preg_match('/([А-я])+/iu', $normalized_string) && (preg_match('/([0-9])+/iu', $normalized_string))) {
                    $comment_string .= $normalized_string . ': ' . $answer . "\r\n";
                } else if (preg_match('/([А-я])+/iu', $normalized_string)) {
                    $other_string .= $normalized_string . ': ' . $answer . "\r\n";
                }
            }

            $regY = '/[^0-9]/';
            $regX = '^([7-8][\- ]?)?(\(?\d{3}\)?[\- ]?)[\d\- ]{7,10}$^';
            $regZ = '^([7-8][\- ]?)(\(?\d{3}\)?[\- ]?)[\d\- ]{7,10}$^';
            $regV = '/^[7-8]{1}/';
            $regC = '/[^a-zA-Zа-яА-Я0-9 -]/ui';

            $normalized_phone = preg_replace($regY, '', $request['Phone']);

            // dd($normalized_phone);

            if ((preg_match($regX, $normalized_phone) && strlen($normalized_phone) == 10)
                || preg_match($regZ, $normalized_phone)
            ) {
                if (!preg_match($regV, $normalized_phone)) {
                    $normalized_phone = '8' . $normalized_phone;
                }

                $search_data = json_decode(Http::get($url, ['phone' => $normalized_phone])->body());
                if (isset($search_data) && !empty($search_data->region)) {
                    $region_name_array = explode(' ', preg_replace($regC, '', $search_data->region));
                    dump($region_name_array);
                    $data = $this->determine_type($region_name_array);

                    if ($data != false) {
                        $desired_key = $data['index'] - 1;
                        if ($data['type'] == 'republic') {
                            $desired_key += 2;
                        }

                        $subject_data = $this->call('lists.element.get', [
                            'IBLOCK_TYPE_ID' => 'lists',
                            'IBLOCK_ID' => '19',
                            'FILTER' => [
                                '%NAME' => $region_name_array[$desired_key],
                            ],
                        ]);

                        // PROPERTY_71 Регион
                        // PROPERTY_78 ООО
                        // PROPERTY_79 Реклама
                        // PROPERTY_82 Вероятность

                        if (isset($subject_data['result'][0]['ID'])) {
                            $sale_dep = $this->call('lists.element.get', [
                                'IBLOCK_TYPE_ID' => 'lists',
                                'IBLOCK_ID' => '16',
                                'FILTER' => [
                                    'PROPERTY_71' => $subject_data['result'][0]['ID'],
                                ],
                            ]);

                            // dd($sale_dep);

                            //TODO РАСПРЕДЕЛЕНИЕ ПО ВЕРОЯТНОСТИ

                            $sum = 0;
                            $rands = [];

                            foreach ($sale_dep['result'] as $key => $dep) {
                                // if ($dep['PROPERTY_79']->first() == 0) continue;
                                if (array_values($dep['PROPERTY_79'])[0] == 0) continue;

                                $sum += $this->get_value_from_list($dep['PROPERTY_82']); // вероятность не то число
                                $rands[$key] = $sum;
                            }

                            $random = random_int(1, $sum);
                            $result = 0;

                            foreach ($rands as $key => $rand) {
                                if ($random <= $rand) {
                                    $result = $sale_dep['result'][$key]['ID'];
                                    break;
                                }
                            }
                            dd($result);
                        }
                    } else {
                        // TODO ХУЕВЫЙ РЕГИОН КИДАЕМ НА КЦ
                    }
                } else {
                    //TODO НЕ НАШЛИ НОМЕР ЧЕРЕЗ СЕРВИС
                }
            } else {
                Log::build([
                    'driver' => 'single',
                    'path' => storage_path('logs/Phone.log')
                ])->info('Bad phone number');
            }
        } else {
            return response([
                'error' => 'Bad phone number'
            ], 200);
        }
    }
}
