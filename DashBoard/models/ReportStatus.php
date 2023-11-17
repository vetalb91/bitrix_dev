<?php

namespace frontend\models;

use common\models\DashboardSettings;
use common\models\DashboardTasks;
use Yii;
use common\models\Account;
use common\models\ReportStatusControlSettings;
use lib\bitrix24\Crest;

/**
 * This is the model class for table "report_status_control".
 *
 * @property int $id
 * @property int $lead_id
 * @property int $status_id
 * @property int $old_status_id
 * @property int $pipeline_id
 * @property int $old_pipeline_id
 * @property int $responsible_user_id
 * @property string $time
 * @property string $subdomain
 */
class ReportStatus extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'report_status_control';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['lead_id', 'old_status_id', 'pipeline_id', 'old_pipeline_id', 'created_user_id', 'responsible_user_id',
                'subdomain'], 'required'],
            [['lead_id', 'old_status_id', 'pipeline_id', 'old_pipeline_id', 'created_user_id',
                'responsible_user_id'], 'integer'],
            [['time'], 'safe'],
            [['subdomain'], 'string', 'max' => 255],
            [['status_id'], 'string', 'max' => 30]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'lead_id' => 'Lead ID',
            'status_id' => 'Status ID',
            'old_status_id' => 'Old Status ID',
            'pipeline_id' => 'Pipline ID',
            'old_pipeline_id' => 'Old Pipline ID',
            'created_user_id' => 'Created User ID',
            'responsible_user_id' => 'Responsible User ID',
            'time' => 'Time',
            'subdomain' => 'Subdomain',
        ];
    }


    public static function dashboardChartsData($params)
    {

        $r = Yii::$app->request->get();

        $r = isset( $r['test'] ) ? true : false;

        $get = $arResult = [];

        $get['subdomain'] = $subdomain = $params['subdomain'];

        $get['type'] = isset( $params['type'] ) ? $params['type'] : 1;

        $dashboardSettings = DashboardSettings::find()
            ->select( 'settings' )
            ->where( ['subdomain' => $subdomain] )
            ->asArray()
            ->one();

        if (isset( $dashboardSettings['settings'] )) {
            $settings = json_decode( $dashboardSettings['settings'], 1 );
        } else {
            return false;
        }

        $arResult['option']['dashboards'] = array_keys( $settings );

        if (isset( $params['type'] )) {
            if ((int)$params['type'] >= 1 && isset( $settings[$params['type'] - 1] )) {
                $settings = $settings[$params['type'] - 1];
            } else {
                $settings = null;
            }
        } else {
            $settings = $settings[0];
        }

        if (isset( $settings['week_norm'] )) {
            $arResult['option']['week_norm'] = $settings['week_norm'];
        }

        if (!isset( $settings['talk_duration'] )) {
            $settings['talk_duration'] = 40;
        }

        if (!isset( $settings['month_start_day'] )) {
            $settings['month_start_day'] = 1;
        }

        $month_start_obj = (new \DateTime( date( 'Y-m' ) . '-' . $settings['month_start_day'] . ' 00:00:00' ));
        if (date( 'd' ) < $settings['month_start_day']) {
            $month_start_obj = $month_start_obj->modify( '-1 month' );
        }

        //TODO: заменить значения в скрипте, чтобы не плодить переменные

        if (empty( $settings['datasort'] )) {
            return false;
        }

        foreach ($settings['datasort'] as $key => $item) {
            $arResult['datasort'][] = $item;
        }

        $settings['datasort'] = $arResult['datasort'];

        $user_list = $settings['user_list'];
        $datatype = $settings['datatype'];
        $getDataSettings = $settings['getDataStatus'];
        $get['datetime'] = time();

        $arResult['data'] = [];

        if (!isset( $settings['platform'] )) {
            $account_info = Account::find()
                ->select( ['user_login', 'user_hash', 'subdomain'] )
                ->where( ['subdomain' => $get['subdomain']] )
                ->asArray()
                ->one();

            $amo = Yii::$app->amocrm;
            $amo->subdomain = $account_info['subdomain'];
            $amo->login = $account_info['user_login'];
            $amo->hash = $account_info['user_hash'];

            $amo->getClient();
            $account = $amo->account->apiCurrent();
        } else {

            if ($settings['platform'] === 'bx24') {

                define( 'C_REST_WEB_HOOK_URL', 'https://pinschercrm.bitrix24.ru/rest/34/kc8w6kla0iee1suk/' );

                $account['users'] = CRest::call( 'user.get', [
                    'ID' => $settings['user_list']
                ] )['result'];

                foreach ($account['users'] as $index => $item) {
                    $account['users'][$index] = array_change_key_case( $item );
                    $account['users'][$index]['name'] =
                        $account['users'][$index]['name'] . ' ' . $account['users'][$index]['last_name'];
                }

            }

        }

        $user_name_to_id = [];

        foreach ($account['users'] as $user) {
            $arResult['users'][$user['id']] = $user['name'];
            $user_name_to_id[$user['name']] = $user['id'];
        }

        //проверяем наличие настроек в базе, которая указывает на необходимость подключении типа данных ^week%
        //выбираем статусы за всю неделю
        //Получаем данные по сделкам из амо
        //Перебираем и получаем данные по ответственному
        //Перебираем и получаем данные по полю

        $table_type_items = [];
        $is_field_item = false;
        foreach ($settings['datasort'] as $item) {
            if (is_array( $item[0] )) continue;
            if (!is_array( $item[0] ) || strpos( $item[0], 'week_' ) === 0) {

                $t_item = substr( $item[0], strpos( $item[0], '_' ) + 1 );
                $t_item_arr = explode( '_', $t_item );

                switch ($t_item_arr[0]) {
                    case 'resp':
                        {
                            $table_type_items[] = [
                                'item' => $item,
                                'status_id' => $t_item_arr[1],
                            ];
                            break;
                        }
                    case 'field':
                        {
                            $table_type_items[] = [
                                'item' => $item,
                                'status_id' => $t_item_arr[2],
                                'field_id' => $t_item_arr[1]
                            ];
                            $is_field_item = true;
                            break;
                        }
                }
            }
        }

        //выбираем данные за неделю для колбаски
        if (!empty( $table_type_items )) {

            $table_type_result = [];
            $statuses = [];

            foreach ($table_type_items as $item) {
                if (!in_array( (int)$item['status_id'], $statuses, true )) {
                    $statuses[] = (int)$item['status_id'];
                }
            }

            $datetime =
                new \DateTime( '-' . date( 'N' ) . ' days -' . date( 'H' ) . ' hours -' . date( 'i' ) . ' minutes -' .
                    date( 's' ) . ' seconds' );
            $weektime['start'] = $datetime->format( 'Y-m-d H:i:s' );
            $weektime['end'] = $datetime->modify( '+7 days 23 hours 59 minutes 59 seconds' )->format( 'Y-m-d H:i:s' );
            $week_statuses = \frontend\models\ReportStatus::find()
                ->select( ['status_id', 'lead_id', 'time', 'responsible_user_id'] )
                ->where( ['subdomain' => $params['subdomain']] )
                ->andWhere( ['IN', 'status_id', $statuses] )
                ->andWhere( ['subdomain' => $params['subdomain']] )
                ->andWhere( ['>=', 'time', $weektime['start']] )
                ->andWhere( ['<=', 'time', $weektime['end']] )
                ->asArray()
                ->all();


            $query_ids = [];
            $lead_in_day = [];
            foreach ($week_statuses as $item) {
                $query_ids[] = $item['lead_id'];
                $day_key = date( 'N', strtotime( $item['time'] ) );

                foreach ($table_type_items as $ttitem) {
                    if (!isset( $ttitem['field_id'] )) {
                        $m = $item['responsible_user_id'];
                        $t = $ttitem['item'][0];
                        $d = $day_key;
                        if (isset( $table_type_result[$m][$t][$d] )) {
                            $table_type_result[$m][$t][$d]++;
                        } else {
                            $table_type_result[$m][$t][$d] = 1;
                        }
                    }
                }

                $lead_in_day[$item['lead_id']] = $day_key;

            }

            if ($is_field_item === true) {
                $leads = $amo->lead->apiList( ['id' => $query_ids] );

                $f_option = [];
                foreach ($table_type_items as $ttitem) {
                    if (isset( $ttitem['field_id'] )) {
                        if (!in_array( (int)$ttitem['field_id'], $f_option, true )) {
                            $f_option[] = (int)$ttitem['field_id'];
                        }
                    }
                }

                foreach ($leads as $lead) {

                    $t_fields = [];
                    foreach ($lead['custom_fields'] as $field) {
                        $t_fields[$field['id']] = $field['values'][0]['value'];
                    }

                    foreach ($table_type_items as $ttitem) {
                        if (isset( $ttitem['field_id'] ) && isset( $t_fields[$ttitem['field_id']] )) {
                            $m = $user_name_to_id[$t_fields[$ttitem['field_id']]];
                            $t = $ttitem['item'][0];
                            $d = $lead_in_day[$lead['id']];
                            if (isset( $table_type_result[$m][$t][$d] )) {
                                $table_type_result[$m][$t][$d]++;
                            } else {
                                $table_type_result[$m][$t][$d] = 1;
                            }
                        }
                    }
                }
            }
            foreach ($table_type_result as $user_id => $ttritem) {
                foreach ($ttritem as $type => $daydata) {

                    if (isset( $daydata[6] ) || isset( $daydata[7] )) {
                        if (!isset( $daydata[1] )) {
                            $daydata[1] = 0;
                        }
                        if (isset( $daydata[6] )) {
                            $daydata[1] += $daydata[6];
                        }
                        if (isset( $daydata[7] )) {
                            $daydata[1] += $daydata[7];
                        }
                        unset( $daydata[6], $daydata[7] );
                    }

                    $arResult['data'][$user_id][$type] = $daydata;
                }
            }
        }

        //собираем данные по звонкам
        $callsd = \frontend\models\ReportCall::find()
            ->select( ['responsible_user_id', 'duration', 'element_id'] )
            ->where( ['subdomain' => $get['subdomain']] )
            ->andWhere( ['>=', 'time', date( 'Y-m-d', $get['datetime'] ) . ' 00:00:00'] )
            ->andWhere( ['<=', 'time', date( 'Y-m-d', $get['datetime'] ) . ' 23:59:59'] )
            ->andWhere( ['IN', 'responsible_user_id', $user_list] )
            ->orderBy( 'time' )
            ->asArray()
            ->all();

        foreach ($callsd as $call) {
            if (!isset( $arResult['data'][$call['responsible_user_id']]['calls'] )) {
                $arResult['data'][$call['responsible_user_id']]['calls'] = 0;
            }
            if (!isset( $arResult['data'][$call['responsible_user_id']]['call_minut'] )) {
                $arResult['data'][$call['responsible_user_id']]['call_minut'] = 0;
            }
            $arResult['data'][$call['responsible_user_id']]['calls']++;
            $arResult['data'][$call['responsible_user_id']]['call_minut'] += round( $call['duration'] / 60 * 100 ) /
                100;

            if (!isset( $arResult['data'][$call['responsible_user_id']]['processed'] )) {
                $arResult['data'][$call['responsible_user_id']]['processed'] = [];
            }
            if (!is_array( $arResult['data'][$call['responsible_user_id']]['processed'] )) {
                $arResult['data'][$call['responsible_user_id']]['processed'] = [];
            }
            if (!in_array( (int)$call['element_id'], $arResult['data'][$call['responsible_user_id']]['processed'],
                true )) {
                $arResult['data'][$call['responsible_user_id']]['processed'][] = (int)$call['element_id'];
            }
        }

        foreach ($arResult['data'] as $user_id => $user_data) {
            if (!isset( $arResult['data'][$user_id]['call_minut'] )) {
                $arResult['data'][$user_id]['call_minut'] = 0;
            } else {
                $arResult['data'][$user_id]['call_minut'] = round( $user_data['call_minut'] );
            }
        }

        $select_arr = ['responsible_user_id', 'duration', 'element_id'];

        //Если в настройках установлено "Исходящие разговоры", то добавляем тип звонка в выборку
        if (in_array( 'call_items_out', $settings['datatype'], false ) ||
            in_array( 'call_speak_out', $settings['datatype'], false )) {
            $select_arr[] = 'call_type';
        }

        $talkd = ReportCall::find()
            ->select( $select_arr )
            ->where( ['subdomain' => $get['subdomain']] )
            ->andWhere( ['>=', 'time', date( 'Y-m-d', $get['datetime'] ) . ' 00:00:00'] )
            ->andWhere( ['<=', 'time', date( 'Y-m-d', $get['datetime'] ) . ' 23:59:59'] )
            ->andWhere( ['IN', 'responsible_user_id', $user_list] )
            ->andWhere( ['>=', 'duration', $settings['talk_duration']] )
            ->orderBy( 'time' )
            ->asArray()
            ->all();

        foreach ($talkd as $call) {

            if (!isset( $arResult['data'][$call['responsible_user_id']]['talks'] )) {
                $arResult['data'][$call['responsible_user_id']]['talks'] = 0;
            }
            if (!isset( $arResult['data'][$call['responsible_user_id']]['talks_time'] )) {
                $arResult['data'][$call['responsible_user_id']]['talks_time'] = 0;
            }
            if (!isset( $arResult['data'][$call['responsible_user_id']]['call_items_out'] )) {
                $arResult['data'][$call['responsible_user_id']]['call_items_out'] = [];
            }
            if (!isset( $arResult['data'][$call['responsible_user_id']]['call_speak_out'] )) {
                $arResult['data'][$call['responsible_user_id']]['call_speak_out'] = [];
            }

            if (in_array( 'call_items_out', $settings['datatype'], true )) {
                if ((int)$call['call_type'] === 11 && $call['duration'] >= $settings['talk_duration'] &&
                    !in_array( $call['element_id'], $arResult['data'][$call['responsible_user_id']]['call_items_out'],
                        false )) {
                    $arResult['data'][$call['responsible_user_id']]['call_items_out'][] = $call['element_id'];
                }
            }

            if (in_array( 'call_speak_out', $settings['datatype'], true )) {
                if ((int)$call['call_type'] === 11 && $call['duration'] >= $settings['talk_duration']) {
                    if ($r && 3317005 === (int)$call['responsible_user_id']) {
                        print_r( $call );
                        exit;
                    }
                    $arResult['data'][$call['responsible_user_id']]['call_speak_out'][] = $call['element_id'];
                }
            }


            $arResult['data'][$call['responsible_user_id']]['talks']++;
            $arResult['data'][$call['responsible_user_id']]['talks_time'] += round( $call['duration'] / 60 * 100 ) /
                100;
        }

        foreach ($arResult['data'] as $user_id => $user_data) {
            if (!isset( $user_data['talks_time'] )) {
                $user_data['talks_time'] = 0;
            }
            if (!isset( $user_data['processed'] )) {
                $user_data['processed'] = [];
            }
            if (!isset( $user_data['call_items_out'] )) {
                $user_data['call_items_out'] = [];
            }
            if (!isset( $user_data['call_speak_out'] )) {
                $user_data['call_speak_out'] = [];
            }
            $arResult['data'][$user_id]['talks_time'] = round( $user_data['talks_time'] );
            $arResult['data'][$user_id]['processed'] = count( $user_data['processed'] );
            $arResult['data'][$user_id]['call_items_out'] = count( $user_data['call_items_out'] );
            $arResult['data'][$user_id]['call_speak_out'] = count( $user_data['call_speak_out'] );
        }

        if (isset( $getDataSettings['statuses'] )) {


            //echo '<pre>';

            //выбираем данные по статусам

            $timestamp_for_status = false;

            if (isset( $settings['option'] )) {
                foreach ($settings['option']['custom_data'] as $data_type) {
                    //выбираем минимальную отметку времени
                    if (in_array( 'month', $data_type, true )) {
                        $timestamp_for_status = [
                            'from' => $month_start_obj->getTimestamp(),
                            'to' => strtotime( date( 'Y-m-d' ) . ' 23:59:59' ),
                        ];
                    } elseif (in_array( 'week', $data_type, true )) {

                        $tdate = new \DateTime();
                        $the_date = $tdate->getTimestamp();
                        $last_friday_timestamp = strtotime( date( 'Y-m-d',
                                $tdate->modify( '-' . ($tdate->format( 'N' ) + 2) . ' days' )->getTimestamp() ) .
                            ' 00:00:00' );

                        if (!isset( $timestamp_for_status['to'] ) ||
                            $timestamp_for_status['to'] < $last_friday_timestamp) {
                            $timestamp_for_status = [
                                'from' => $last_friday_timestamp,
                                'to' => $the_date,
                            ];
                        }
                    }
                }
            }

            if ($timestamp_for_status === false) {
                $getStatusTimestamp = $get['datetime'];
            } else {
                $getStatusTimestamp = $timestamp_for_status;
            }

            $statuses = self::status_info( [
                'subdomain' => $get['subdomain'],
                'users_list' => $user_list,
                'statuses' => $getDataSettings['statuses'],
                'datetime' => $get['datetime'],
                'time' => $getStatusTimestamp !== false ? $getStatusTimestamp : null
            ] );

            $all_status_data = [];
            //id сделок для выборки данных из амо, с последующим определением пользователя по полю
            $field_data_id_list = [];
            $lead_id_relation_status_id = [];
            foreach ($statuses as $status) {

                $is_field_item = false;

                /*заполняем данные по умолчанию*/
                if (!isset( $arResult['data'][$status['created_user_id']][$status['status_id']] )) {
                    $arResult['data'][$status['created_user_id']][$status['status_id']] = [];
                }
                if (!isset( $all_status_data[$status['created_user_id']] )) {
                    $all_status_data[$status['created_user_id']] = [];
                }
                if (!isset( $all_status_data[$status['created_user_id']][$status['pipeline_id']] )) {
                    $all_status_data[$status['created_user_id']][$status['pipeline_id']] = [];
                }
                if (!isset( $all_status_data[$status['created_user_id']][$status['pipeline_id']][$status['status_id']] )) {
                    $all_status_data[$status['created_user_id']][$status['pipeline_id']][$status['status_id']] = [];
                }

                //Проверяем входят ли данные в кастомный тип
                //заполняем массив, согласно полученным данным

                if (!in_array( (int)$status['lead_id'],
                    $arResult['data'][$status['created_user_id']][$status['status_id']], true )) {

                    foreach ($settings['datasort'] as $index => $data) {
                        $explode_data = explode( '_', $data[0] );
                        if (isset( $explode_data[2] ) && $explode_data[2] == $status['status_id']) {
                            $is_field_item = true;
                            break;
                        }
                    }

                    if (isset( $settings['option']['custom_data'][$status['status_id']] ) &&
                        in_array( 'day', $settings['option']['custom_data'][$status['status_id']], false )) {
                        if (!isset( $arResult['data'][$status['created_user_id']][$status['status_id']]['day'] )) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']]['day'] = [];
                        }
                        if (strtotime( $status['time'] ) > strtotime( date( 'Y-m-d' ) . ' 00:00:00' )) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']]['day'][] =
                                (int)$status['lead_id'];
                        }
                    }

                    if (isset( $settings['option']['custom_data'][$status['status_id']] ) &&
                        in_array( 'week', $settings['option']['custom_data'][$status['status_id']], false )) {
                        if (!isset( $arResult['data'][$status['created_user_id']][$status['status_id']] )) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']] = [];
                        }
                        if (strtotime( $status['time'] ) > $last_friday_timestamp) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']][] =
                                (int)$status['lead_id'];
                        }
                    }

                    //TODO: написать проверку на то, что данные входят в выбранный диапазон времени.
                    if (isset( $settings['option']['custom_data'][$status['status_id']] ) &&
                        in_array( 'month', $settings['option']['custom_data'][$status['status_id']], false )) {
                        if (!isset( $arResult['data'][$status['created_user_id']][$status['status_id']]['month'] )) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']]['month'] = [];
                        }
                        $arResult['data'][$status['created_user_id']][$status['status_id']]['month'][] =
                            (int)$status['lead_id'];
                    }

                    if (!isset( $settings['option']['custom_data'][$status['status_id']] )) {
                        if (strtotime( $status['time'] ) > strtotime( date( 'Y-m-d' ) . ' 00:00:00' )) {
                            $arResult['data'][$status['created_user_id']][$status['status_id']][] =
                                (int)$status['lead_id'];
                        }
                    }

                    if ($is_field_item) {
                        $field_data_id_list[] = (int)$status['lead_id'];
                        $lead_id_relation_status_id[$status['lead_id']][] = $status['status_id'];
                    }

                }

                $all_status_data[$status['created_user_id']][$status['pipeline_id']][$status['status_id']][] =
                    (int)$status['lead_id'];

            }

            //выборка данных по полю<<<BEGIN

            if (!empty( $field_data_id_list )) {
                $field_data_leads = [];

                $array_slice_offset = 0;
                $array_slice_item_count = 30;
                do {
                    $array_slice_items =
                        array_slice( $field_data_id_list, $array_slice_offset, $array_slice_item_count );

                    $tleads = $amo->lead->apiList( ['id' => $array_slice_items] );
                    if (!empty( $tleads )) {
                        foreach ($tleads as $item) {
                            $field_data_leads[] = $item;
                        }
                    }
                    //запрашиваем данные по выборке в амо
                    $array_slice_offset += $array_slice_item_count;
                } while (count( $array_slice_items ) === $array_slice_item_count);

                //нужно добавить связь между id сделки и тем статусов, для которого он был выбран

                $field_type_data = [];

                foreach ($settings['datasort'] as $index => $data) {
                    $explode_data = explode( '_', $data[0] );
                    if ($explode_data[0] === 'field' && isset( $explode_data[2] )) {
                        $field_type_data[] = [
                            'field_id' => (int)$explode_data[1],
                            'status_id' => (int)$explode_data[2]
                        ];
                    }
                }

                foreach ($field_data_leads as $field_data_lead) {
                    unset( $user_id );
                    //$lead_id_relation_status_id
                    foreach ($field_data_lead['custom_fields'] as $field) {
                        foreach ($field_type_data as $field_type_data_item) {
                            if ((int)$field['id'] === $field_type_data_item['field_id']) {
                                $user_id = $user_name_to_id[$field['values'][0]['value']];
                                $field_id = $field_type_data_item['field_id'];
                                $status_id = $field_type_data_item['status_id'];
                                break;
                            }
                        }
                    }

                    $field_keys = [];

                    if (isset( $user_id ) && isset( $status_id ) && isset( $arResult['data'][$user_id] )) {
                        $temp_key = 'field_' . $field_id . '_' . $status_id;
                        if (!isset( $arResult['data'][$user_id][$temp_key] )) {
                            $arResult['data'][$user_id][$temp_key] = [];
                        }
                        $arResult['data'][$user_id][$temp_key][] = $field_data_lead['id'];
                        if (!in_array( $temp_key, $field_keys, false )) {
                            $field_keys[] = $temp_key;
                        }
                    }

                }

                foreach ($field_keys as $tf_key) {
                    foreach ($arResult['data'] as $user_id => $user_data) {
                        if (empty( $user_data[$tf_key] )) {
                            $arResult['data'][$user_id][$tf_key] = 0;
                        } else {
                            $arResult['data'][$user_id][$tf_key] = count( $user_data[$tf_key] );
                        }
                    }
                }

            }


            //выборка данных по полю<<<END

            //3454095
            foreach ($getDataSettings['statuses'] as $status_id) {
                foreach ($user_list as $user_id) {
                    $default_key = null;
                    if (is_array( $status_id )) {
                        $temp_status = $status_id;
                        $status_id = implode( '&', $status_id );
                        $default_key = 'default_' . $status_id;
                    }

                    $is_day_month = isset( $settings['option']['custom_data'][$status_id] ) &&
                        in_array( 'day', $settings['option']['custom_data'][$status_id], false ) &&
                        in_array( 'month', $settings['option']['custom_data'][$status_id], false );

                    //if ($r) {
                    if ($is_day_month) {
                        if (!isset( $arResult['data'][$user_id][$status_id]['day'] )) {
                            $arResult['data'][$user_id][$status_id]['day'] = [];
                        }
                        if (!isset( $arResult['data'][$user_id][$status_id]['month'] )) {
                            $arResult['data'][$user_id][$status_id]['month'] = [];
                        }
                    }
                    //}

                    if (!empty( $arResult['data'][$user_id][$status_id] )) {
                        //if ($r) {
                        if ($is_day_month) {
                            $arResult['data'][$user_id][$status_id] =
                                count( $arResult['data'][$user_id][$status_id]['day'] ) . '/' .
                                count( $arResult['data'][$user_id][$status_id]['month'] );
                        } else {
                            $arResult['data'][$user_id][$status_id] = count( $arResult['data'][$user_id][$status_id] );
                        }
//                        } else {
//                            $arResult['data'][$user_id][$status_id] = count($arResult['data'][$user_id][$status_id]);
//                        }
                    } else {
                        $arResult['data'][$user_id][$status_id] = 0;
                    }

                    if (!empty( $default_key ) && in_array( $default_key, $settings['datatype'], false )) {
                        if (!empty( $all_status_data[$user_id][$temp_status[1]][$temp_status[0]] )) {
                            $arResult['data'][$user_id][$default_key] =
                                count( $all_status_data[$user_id][$temp_status[1]][$temp_status[0]] );
                        } else {
                            $arResult['data'][$user_id][$default_key] = 0;
                        }
                    }

                    unset( $default_key, $temp_status );
                }
            }

        }

        if (isset( $getDataSettings['price'] ) && count( $getDataSettings['price'] ) > 0) {

            if (isset( $settings['month_start_day'] )) {
                $datetime = new \DateTime( '-' . date( 'd' ) . ' days -' . date( 'H' ) . ' hours -' . date( 'i' ) .
                    ' minutes -' . date( 's' ) . ' seconds' );
                if (date( 'd' ) < $settings['month_start_day']) {
                    $datetime->modify( '-1 month' );
                }
                $getDataArr['time']['from'] =
                    $datetime->modify( '+' . ($settings['month_start_day'] - 1) . ' days' )->getTimestamp();

                $datetime = new \DateTime( date( 'Y-m-t', $get['datetime'] ) . ' 23:59:59' );
                if (date( 'd' ) < $settings['month_start_day']) {
                    $datetime->modify( '-1 month' );
                }
                $getDataArr['time']['to'] =
                    $datetime->modify( '+' . ($settings['month_start_day'] - 1) . ' days' )->getTimestamp();

            }

            $dataArr = [
                'subdomain' => $get['subdomain'],
                'users_list' => $user_list,
                'statuses' => $getDataSettings['price']
            ];

            if (isset( $getDataArr['time'] )) {
                $dataArr['time'] = $getDataArr['time'];
            }


            $statuses = self::status_info( $dataArr );


            if (!empty( $statuses )) {
                $sum_lead_id = [];
                foreach ($statuses as $status) {
                    if (!in_array( (int)$status['lead_id'], $sum_lead_id, true )) {
                        $sum_lead_id[] = (int)$status['lead_id'];
                    }
                }

                //TODO: прописать при условии большого объема

                $tleads = $amo->lead->apiList( ['id' => $sum_lead_id] );
                $leads = [];

                foreach ($tleads as $lead) {
                    if (in_array( (int)$lead['responsible_user_id'], $user_list, true )) {
                        $key = $lead['status_id'] . '&' . $lead['pipeline_id'];
                        if (!in_array( $key, $settings['datatype'], false )) {
                            $key = $lead['status_id'];
                            if (!in_array( $key, $settings['datatype'], false )) {
                                continue;
                            }
                        }
                        if (!isset( $arResult['data'][$lead['responsible_user_id']][$key] )) {
                            $arResult['data'][$lead['responsible_user_id']][$key] = 0;
                        }
                        $arResult['data'][$lead['responsible_user_id']][$key] += (int)$lead['price'];
                    }
                }
            }
        }

//        //TODO: дописать обработку суммы бюджета по сделкам за один день
//        echo '<pre>' . print_r($settings,1) . '</pre>';


//        if( $r ){
//            echo '<pre>' . print_r($arResult,1) . '</pre>';
//        }

        foreach ($settings['datasort'] as $item) {

            if (!is_array( $item[0] ) && strpos( $item[0], 'price_today' ) === 0) {

                $leads_id = substr( $item[0], strrpos( $item[0], '_' ) + 1 );

                $statuses = self::status_info( [
                    'subdomain' => $get['subdomain'],
                    'users_list' => $user_list,
                    'statuses' => [$leads_id],
                    'time' => [
                        'from' => strtotime( date( 'Y-m-d', $get['datetime'] ) . ' 00:00:00' ),
                        'to' => strtotime( date( 'Y-m-d', $get['datetime'] ) . ' 23:59:59' )
                    ],
                ] );

                $sum_lead_id = [];
                $sum_result = 0;
                $re_sum_lead_id = [];

                foreach ($statuses as $status) {
                    $sum_lead_id[] = $status['lead_id'];
                    $re_sum_lead_id[$status['lead_id']] = $status['created_user_id'];
                }

                if (!empty( $sum_lead_id )) {
                    $leads = $amo->lead->apiList( [
                        'id' => $sum_lead_id
                    ] );
                    foreach ($leads as $lead) {
                        if (!isset( $re_sum_lead_id[$lead['id']] )) continue;
                        if (!isset( $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] )) {
                            $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] = 0;
                        }
                        $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] += (int)$lead['price'];
                    }
                }
            }

            if (!is_array( $item[0] ) && strpos( $item[0], 'field_value' ) === 0) {

                $item_data = explode( '_', substr( $item[0], strlen( 'field_value' ) + 1 ) );
                $leads_field_id = $item_data[1];
                $leads_id = $item_data[0];

                $statuses = self::status_info( [
                    'subdomain' => $get['subdomain'],
                    'users_list' => $user_list,
                    'statuses' => [$leads_id],
                    'time' => [
                        'from' => strtotime( date( 'Y-m-d', $get['datetime'] ) . ' 00:00:00' ),
                        'to' => strtotime( date( 'Y-m-d', $get['datetime'] ) . ' 23:59:59' )
                    ],
                ] );

                $sum_lead_id = [];
                $re_sum_lead_id = [];

                foreach ($statuses as $status) {
                    $sum_lead_id[] = $status['lead_id'];
                    $re_sum_lead_id[$status['lead_id']] = $status['created_user_id'];
                }

                if (!empty( $sum_lead_id )) {
                    $leads = $amo->lead->apiList( [
                        'id' => $sum_lead_id
                    ] );

                    foreach ($leads as $lead) {
                        if (!isset( $re_sum_lead_id[$lead['id']] )) continue;
                        foreach ($lead['custom_fields'] as $field) {
                            if ((int)$field['id'] === (int)$leads_field_id) {
                                if (!isset( $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] )) {
                                    $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] = 0;
                                }
                                $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] += (int)$field['values'][0]['value'];
                            }
                        }
                    }
                    /*foreach ( $leads as $lead ){
                        if( !isset($re_sum_lead_id[$lead['id']]) ) continue;
                        if( !isset( $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] ) ){
                            $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] = 0;
                        }
                        $arResult['data'][$re_sum_lead_id[$lead['id']]][$item[0]] += (int)$lead['price'];
                    }*/
                }

            }

        }

        if (isset( $getDataSettings['personal_price_procent'] )) {

            $statuses = self::status_info( [
                'subdomain' => $get['subdomain'],
                'users_list' => $user_list,
                'statuses' => $getDataSettings['personal_price_procent'],
                'time' => [
                    'from' => strtotime( date( 'Y-m', $get['datetime'] ) . '-01 00:00:00' ),
                    'to' => strtotime( date( 'Y-m-t', $get['datetime'] ) . ' 23:59:59' )
                ],
            ] );

            $sum_lead_id = [];
            foreach ($statuses as $status) {
                if (!in_array( (int)$status['lead_id'], $sum_lead_id, true )) {
                    $sum_lead_id[] = (int)$status['lead_id'];
                }
            }

            $tleads = $amo->lead->apiList( ['id' => $sum_lead_id] );

//            echo '<pre>' . print_r(count($sum_lead_id),1) . '</pre>';
//            echo '<pre>' . print_r($tleads,1) . '</pre>';
//            exit;


            $tResult = [];

            foreach ($tleads as $lead) {
                if ((int)$lead['status_id'] === 142 &&
                    in_array( (int)$lead['responsible_user_id'], $user_list, true )) {
                    if (!isset( $tResult[$lead['responsible_user_id']] )) {
                        $tResult[$lead['responsible_user_id']] = 0;
                    }
                    $tResult[$lead['responsible_user_id']] += (int)$lead['price'];
                }
            }

            //$tResult['3360775'] = 10000;

            foreach ($settings['plans']['personal_price_procent'] as $user_id => $plan_sum) {
                if (isset( $tResult[$user_id] )) {
                    $arResult['data'][$user_id]['personal_price_procent'] =
                        round( $tResult[$user_id] / $plan_sum * 100, 1 ) . '%';
                    $arResult['data'][$user_id]['personal_price_procent_in_progres'] =
                        round( $plan_sum - $tResult[$user_id], 1 );
                    if ($arResult['data'][$user_id]['personal_price_procent_in_progres'] < 0) {
                        $arResult['data'][$user_id]['personal_price_procent_in_progres'] = 0;
                    }
                } else {
                    $arResult['data'][$user_id]['personal_price_procent_in_progres'] = $plan_sum;
                    $arResult['data'][$user_id]['personal_price_procent'] = 0 . '%';
                }

            }

        }

        //задачи, план/факт
        if (in_array( 'tasks', $settings['datatype'], false )) {

            $dtasks = DashboardTasks::find()
                ->select( ['*'] )
                ->where( ['IN', 'responsible_user_id', $settings['user_list']] )
                ->andWhere( ['subdomain' => $account_info['subdomain']] )
                ->asArray()
                ->all();

            foreach ($dtasks as $task) {
                if (!isset( $arResult['data'][$task['responsible_user_id']]['tasks'] )) {
                    $arResult['data'][$task['responsible_user_id']]['tasks'] = [
                        'norm' => 0,
                        'fact' => 0
                    ];
                }
                $arResult['data'][$task['responsible_user_id']]['tasks']['norm']++;
                if ((int)$task['is_completed'] === 1) {
                    $arResult['data'][$task['responsible_user_id']]['tasks']['fact']++;
                }
            }
        }
        
        foreach ($datatype as $type) {
            foreach ($user_list as $user) {
                if (strpos( $type, '&' )) {
                    $arr = explode( '&', $type );
                    foreach ($arr as $item) {//$item - статус сделки
                        if (isset( $arResult['data'][$user][$item] )) {
                            //если данных нет, ставим 0
                            if (!isset( $arResult['data'][$user][$type] )) {
                                if (isset( $settings['option']['custom_data'][$type] ) &&
                                    in_array( 'day', $settings['option']['custom_data'][$type], false ) &&
                                    in_array( 'month', $settings['option']['custom_data'][$type], false )) {
                                    $arResult['data'][$user][$type] = '0/0';
                                } else {
                                    $arResult['data'][$user][$type] = 0;
                                }
                                //continue;
                            }
                            if (is_array( $arResult['data'][$user][$item] )) {
                                $arResult['data'][$user][$item] = count( $arResult['data'][$user][$item] );
                            }
                            $arResult['data'][$user][$type] += $arResult['data'][$user][$item];
                        }
                    }
                }

                if (empty( $arResult['data'][$user][$type] )) {
                    if (isset( $settings['option']['custom_data'][$type] ) &&
                        in_array( 'day', $settings['option']['custom_data'][$type], false ) &&
                        in_array( 'month', $settings['option']['custom_data'][$type], false )) {
                        $arResult['data'][$user][$type] = '0/0';
                    } else {
                        $arResult['data'][$user][$type] = 0;
                    }

                }
                if (is_array( $arResult['data'][$user][$type] )) {
                    if (!isset( $arResult['data'][$user][$type]['fact'] )) {
                        if (empty( $arResult['data'][$user][$type] )) {
                            $arResult['data'][$user][$type]['fact'] = 0;
                        } else {
                            $arResult['data'][$user][$type]['fact'] = count( $arResult['data'][$user][$type] );
                        }
                    }
                }
                if ($type == 'tasks' && !is_array( $arResult['data'][$user][$type] )) {
                    $arResult['data'][$user][$type] = ['norm' => 1, 'fact' => 0];
                }
            }
        }
        foreach ($arResult['data'] as $user_id => $data) {
            if (!isset( $arResult['users'][$user_id] )) {
                unset( $arResult['data'][$user_id] );
            }
        }

        foreach ($arResult['data'] as $user_id => $data) {
            if (!in_array( (int)$user_id, $user_list, true )) {
                unset( $arResult['data'][$user_id] );
            }
        }

        return $arResult;
    }

    /*
     * $params['datetime'] = @int time();
     * $params['statuses'] = @array status_ids
     * $params['subdomain'] = @string amocrm subdomain
     * $params['users_list'] = @array amocrm ids_user
     */

    private static function status_info($params)
    {

        if (isset( $params['time']['from'] ) && isset( $params['time']['to'] )) {
            $timefrom = $params['time']['from'];
            $timeto = $params['time']['to'];
        } else {
            if (isset( $params['datetime'] )) {
                $timefrom = $timeto = $params['datetime'];
            } else {
                $timefrom = $timeto = time();
            }
        }

        $datefrom = date( 'Y-m-d', $timefrom ) . ' 00:00:00';
        $dateto = date( 'Y-m-d', $timeto ) . ' 23:59:59';

        $reportStatus = new ReportStatus();

        $statusFindSql = [];

        foreach ($params['statuses'] as $status) {

            if (is_array( $status )) {
                if (isset( $status['sum'] )) {
                    $tempSql = $reportStatus
                        ->find()
                        ->select( 'id' )
                        ->where( ['IN', 'status_id', $status['sum']] )
                        ->createCommand()
                        ->getRawSql();
                } else {
                    $tempSql = $reportStatus
                        ->find()
                        ->select( 'id' )
                        ->where( ['status_id' => $status[0]] )
                        ->andWhere( ['pipeline_id' => $status[1]] )
                        ->createCommand()
                        ->getRawSql();
                }

            } else {
                $tempSql = $reportStatus
                    ->find()
                    ->select( 'id' )
                    ->where( ['status_id' => $status] )
                    ->createCommand()
                    ->getRawSql();
            }

            $statusFindSql[] = '(' .
                $reportStatus
                    ->find()
                    ->select( 'id' )
                    ->where( '`id` IN (' . $tempSql . ')' )
                    ->andWhere( ['subdomain' => $params['subdomain']] )
                    ->andWhere( ['>=', 'time', $datefrom] )
                    ->andWhere( ['<=', 'time', $dateto] )
                    //->andWhere(['IN','responsible_user_id', $params['users_list']])
                    ->createCommand()
                    ->getRawSql() . ')';
        }

        $sql = '';

        foreach ($statusFindSql as $tsql) {
            if (!empty( $sql )) {
                $sql .= ' OR ';
            }
            $sql .= '`id` IN ' . $tsql;
        }

        $status_list = $reportStatus
            ->find()
            ->select( ['lead_id', 'status_id', 'pipeline_id', 'created_user_id', 'responsible_user_id', 'time'] )
            ->where( $sql )
            ->asArray()
            ->all();

        //echo '<pre>' . print_r($sql,1) . '</pre>';

        return $status_list;

    }

}
