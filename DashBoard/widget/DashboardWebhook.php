<?php

namespace frontend\jobs\widget;

use common\models\WidgetDashboardData;
use common\models\WidgetDashboardEventType;
use common\models\WidgetDashboardPeriodData;
use yii\base\BaseObject;
use common\models\WidgetDashboard;
use common\models\WidgetDashboardTable;
use common\models\WidgetDashboardTableColumn;
use frontend\models\ReportCall;
use common\models\ServiceAuth;
use lib\bitrix24\CRest;
use common\models\WebhookData;
use Yii;

class DashboardWebhook extends \frontend\jobs\BaseJob implements \yii\queue\Job
{
    public $data;

    public function execute($queue)
    {


        try {

            //Работа с событием call
            ini_set("memory_limit", "1024M");

            $this->addEventCallCrm($this->data['element_event_data']['note_type']);


            // Проверяем входит ли событие в список тех, с которыми работаем

            $event_types = WidgetDashboardEventType::find()
                ->where(['subdomain' => $this->data['account']['subdomain']])
                ->andWhere(['event_type' => $this->data['event_name']])
                ->asArray()
                ->all();

            $this->matchingEventDashboard($event_types);


            //TODO: проверить активность дашборда
            //TODO: проверить активность столбца


        } catch (\Exception $e) {

           $this->lg('error',[
                'subdomain' => $this->data['account']['subdomain'] ?? '',
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'message' => $e->getMessage(),
                'data' => $this->data,
            ], true);

        }

    }



    //Добавялем данные сырого запроса из amo

    public function addHistoryEventLeadCrm($event)
    {
        if (in_array($event, ['lead_status', 'deal_status', 'lead_add'])) {

              $webHook = new WebhookData();

              $webHook->subdomain = $this->data['account']['subdomain'];
              $webHook->data = json_encode($this->data['element_event_data']);
              $webHook->created_at = date('Y-m-d h:i:s');
              $webHook->updated_at = date('Y-m-d h:i:s');

              if (!$webHook->save()) {
                  return;
              }

        }

    }


    //Работа с событием звонка

    public function addEventCallCrm($event)
    {
        if (isset($event) && in_array($event, [10, 11]) ){


            // Если событие звонок, записывем в базу и передаем данные для типа processed
            $call = ReportCall::find()
                ->where(['element_id' => $this->data['element_event_data']['element_id']])
                ->andWhere(['call_type' => $this->data['element_event_data']['note_type']])
                ->andWhere(['duration' => $this->data['element_event_data']['params']['DURATION'] ?? 0])
                ->andWhere(['time' => $this->data['element_event_data']['date_create']])
                ->andWhere(['responsible_user_id' => $this->data['element_event_data']['created_by']])
                ->andWhere(['subdomain' => $this->data['account']['subdomain']])
                ->asArray()
                ->one();

            if (empty($call)) {
                $call = new ReportCall();
                $call->attributes = [
                    'element_id' => $this->data['element_event_data']['element_id'],
                    'call_type' => $this->data['element_event_data']['note_type'],
                    'duration' => $this->data['element_event_data']['params']['DURATION'] ?? 0,
                    'time' => str_replace('\'', '', $this->data['element_event_data']['date_create']),
                    'responsible_user_id' => $this->data['element_event_data']['created_by'],
                    'subdomain' => $this->data['account']['subdomain'],
                    'call_data' => json_encode($this->data['element_event_data'])
                ];
                $call->save();

                $calls_today = ReportCall::find()
                    ->where(['element_id' => $this->data['element_event_data']['element_id']])
                    ->andWhere(['LIKE', 'time', date('Y-m-d', strtotime($this->data['element_event_data']['date_create'])) . '%', false])
                    ->andWhere(['responsible_user_id' => $this->data['element_event_data']['created_by']])
                    ->andWhere(['subdomain' => $this->data['account']['subdomain']])
                    ->asArray()
                    ->all();
                $this->data['processed'] = count($calls_today);

            } else {
                // Если это повторный вебхук выходим из скрипта
                echo 'exit ' . __LINE__ . "\n";
                return;
            }
        }

    }

    public function matchingEventDashboard($event_types)
    {


        // Перебираем события для которых подходят текущие данные
        foreach ($event_types as $event_type_item) {

            $event_type_item['options'] = json_decode($event_type_item['options'], 1);

            if (in_array($this->data['event_name'], ['lead_status', 'deal_status', 'lead_add']) ) {

                // Если условие для определенного типа равно false переходим к следующей итерации
                if (!empty($event_type_item['options']['statuses'])) { // есть ли вовсе настройки для статусов

                    $status_is = false;

                    foreach ($event_type_item['options']['statuses'] as $status_option) {//проверям есть ли статус в опциях

                        // Старый формат
                        if (
                            $status_option['status_id'] ==  $this->data['element_event_data']['status_id']
                            &&
                            $status_option['pipeline_id'] == $this->data['element_event_data']['pipeline_id']
                        ){
                            $status_is = true;
                            break;
                        }
                        // Новый формат(из интерфейса)

                        $t_item_data = [
                            'status_id' => $this->data['element_event_data']['pipeline_id'] . ':' . $this->data['element_event_data']['status_id'],
                            'pipeline_id' => (int)preg_replace('/(\D)/m', '', $this->data['element_event_data']['pipeline_id'])
                        ];

                        if (
                            $status_option['status_id'] ==  $t_item_data['status_id']
                            &&
                            $status_option['pipeline_id'] == $t_item_data['pipeline_id']
                        ){
                            $this->data['element_event_data']['status_id'] = $t_item_data['status_id'];
                            $this->data['element_event_data']['pipeline_id'] = $t_item_data['pipeline_id'];
                            $status_is = true;
                            break;
                        }

                    }

                    if( $status_is !== true ){
                        continue;
                    }

                }else{
                    continue;
                }

            }

            //TODO: проверить работу добавления статуса
            $this->data['event_type_data'] = $event_type_item;

            if ($this->data['account']['subdomain'] == 'b24-y6wfj3.bitrix24.ru') {
                echo "\nАккаунт: " . $this->data['account']['subdomain'];
                echo "\n" . 'calculate';
            }


            // сумируем события и обновляем в бд таблицу widget_dashboard_data
            $this->calculate();

        }

    }


    public function calculate()
    {


        $wdtc = WidgetDashboardTableColumn::findOne($this->data['event_type_data']['column_id']);
        $dashboardData = WidgetDashboardData::find()
            ->where(['subdomain' => $this->data['account']['subdomain']])
            ->one();



        if( empty($wdtc->table) ){
            return;
        }

        $user_list = json_decode($wdtc->table->user_list, 1);


        if (!empty($dashboardData)) {
            $data = json_decode($dashboardData->data,1);

        } else {
            return;
        }



        //если задание для битрикса, то получаем данные для авторизации
        if (isset($this->data['element_event_data']['platform']) && $this->data['element_event_data']['platform'] == 'bx24') {
            if (!defined("C_REST_WEB_HOOK_URL")) {
                $auth = ServiceAuth::getAuth('b24', $this->data['account']['subdomain']);
                if( empty($auth) ){
                    $this->lg('Не доступов для аккаунта bitrix24 ' . $this->data['account']['subdomain'], '', true);
                }
                define('C_REST_WEB_HOOK_URL', $auth['webhook_url']);
            }
        }

        $column_id = $this->data['event_type_data']['column_id'];
        $value = 0;

        //обработчики:
        //переход в статус по умолчанию в зачет тому, кто перевел, без учета уникальности сделки за текущий день
        //TODO: проверять была ли сделка в этом статусе за сегодня/месяц

        if (in_array($this->data['event_name'], ['lead_add', 'lead_status', 'deal_status'])) {

            if (in_array($this->data['event_type_data']['type'], ['lead_сhange_status', 'lead_change_status', 'deal_change_status'])) {

                $user_id = $this->data['element_event_data']['modified_user_id'];

                if ($custom_type = isset($this->data['event_type_data']['options']['custom_type']) ? $this->data['event_type_data']['options']['custom_type'] : false) {

                    if ($custom_type == 'price') {

                        if (isset($this->data['element_event_data']['price'])) {
                            $value = $this->data['element_event_data']['price'];
                        } else {
                            //получаем бюджет сделки
                            //TODO: нужно написать условие для битрикса и других систем
                            //Для AmoCRM
                            $dsh = new \lib\Dashboard($this->data['account']['subdomain'], $this->data['element_event_data']['platform']);

                            $value = $dsh->get_lead_price($this->data['element_event_data']['id']);
                        }

                    }

                } else {

                    // Проверяем нужно ли выводить данные за день/месяц
                    if (isset($this->data['event_type_data']['options']['period'])) {
                        if( !is_array($this->data['event_type_data']['options']['period']) ){
                            $this->data['event_type_data']['options']['period'] = json_decode($this->data['event_type_data']['options']['period'], 1);
                        }

                        if (count($this->data['event_type_data']['options']['period']) === 1) {//Если в периоде указан только один параметр day, week, month
                            $value = 1;
                        } else {//Если в периоде указано несколько параметров
                            $value = [];
                            foreach ($this->data['event_type_data']['options']['period'] as $k => $v) {
                                $value[$k] = 1;
                            }
                        }
                    } else {
                        // вывод данных за день
                        $value = 1;
                    }
                }

                // блокируем сбор данных по пользователям, которых нет в настройках
                if (!in_array($user_id, $user_list)) {
                    return;
                }


            }

        }

        // Обработка событий с примичениями
        if (in_array($this->data['event_name'], ['lead_note', 'contact_note', 'company_note', 'customer_note', 'bx_call'])) {

            if (isset($this->data['element_event_data']['text']) && empty($this->data['element_event_data']['params'])) {
                $this->data['element_event_data']['params'] = json_decode($this->data['element_event_data']['text'], 1);
            }

            if (isset($this->data['element_event_data']['platform']) && $this->data['element_event_data']['platform'] == 'bx24') {

                $call = CRest::call('voximplant.statistic.get', ['FILTER' => ['CALL_ID' => $this->data['element_event_data']['call_id']]]);
                //print_r($call);
                $call = $call['result'][0];

                $this->data['element_event_data']['params']['DURATION'] = $call['CALL_DURATION'];
                $this->data['element_event_data']['element_id'] = $call['CRM_ENTITY_ID'];
                $this->data['element_event_data']['date_create'] = date('Y-m-d H:i:s', strtotime($call['CALL_START_DATE']));
            }

            if (in_array($this->data['event_type_data']['type'], [
                'call_count',
                'call_minutes',
                'call_talk_count',
                'call_in_talk_count',
                'call_out_talk_count',
                'call_out_minutes',
                'call_in_minutes',
                'call_out_count',
                'processed'
            ])) {
                $user_id = $this->data['element_event_data']['created_by'];
                // Звонки добавляем в базу
            }


            // Считаем кол-во звонков
            if ($this->data['event_type_data']['type'] === 'call_count') {
                $value = 1;
            }

            if ($this->data['event_type_data']['type'] === 'call_out_count') {

                if ((int)$this->data['element_event_data']['note_type'] === 11) {
                    $value = 1;
                }
            }

            if ($this->data['event_type_data']['type'] === 'call_minutes') {
                $value = $this->data['element_event_data']['params']['DURATION'];
            }

            if ($this->data['event_type_data']['type'] === 'call_out_minutes') {
                if ((int)$this->data['element_event_data']['note_type'] === 11) {
                    $value = $this->data['element_event_data']['params']['DURATION'];
                }
            }
            if ($this->data['event_type_data']['type'] === 'call_in_minutes') {
                if ((int)$this->data['element_event_data']['note_type'] === 10) {
                    $value = $this->data['element_event_data']['params']['DURATION'];
                }
            }

            if ($this->data['event_type_data']['type'] === 'call_talk_count') {



                if (isset($this->data['event_type_data']['options']['talk_duration']) && (int)$this->data['element_event_data']['params']['DURATION'] >= (int)$this->data['event_type_data']['options']['talk_duration']) {
                    $value = 1;
                }else{
                    print_r('Не указан value');
                }
            }

            if ($this->data['event_type_data']['type'] === 'call_in_talk_count') {
                if (isset($this->data['event_type_data']['options']['talk_duration']) && (int)$this->data['element_event_data']['note_type'] === 10) {
                    if ((int)$this->data['element_event_data']['params']['DURATION'] >= (int)$this->data['event_type_data']['options']['talk_duration']) {
                        $value = 1;
                    }
                }
            }

            if ($this->data['event_type_data']['type'] === 'call_out_talk_count') {
                if (isset($this->data['event_type_data']['options']['talk_duration']) && (int)$this->data['element_event_data']['note_type'] === 11) {
                    if ((int)$this->data['element_event_data']['params']['DURATION'] >= (int)$this->data['event_type_data']['options']['talk_duration']) {
                        $value = 1;
                    }
                }

            }

            if ($this->data['event_type_data']['type'] === 'processed') {

                if ( $this->data['processed'] === 1 ) {
                    $value = 1;
              }

            }

        }

        if ( empty($user_id) && ( isset($user_id) && $user_id !== 0 ) ) {
            $this->lg('$user_id не определен', $this->data, true);
            return;
        }

        try {
            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id] = $data[$wdtc->widget_dashboard_table_id]['data'][$user_id] ?? [];
        } catch( \Exception $e ){
            $this->lg('Отсутствует ид дашборда ' . $e->getMessage(), [
                'subdomain' => $this->data['account']['subdomain'],
                '$wdtc->widget_dashboard_table_id' => $wdtc->widget_dashboard_table_id,
            ], true);
            return;
        }


        //  Заполняем дефолтными данными
        if( !isset($data[$wdtc->widget_dashboard_table_id]['data'][$user_id]) ){
            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id] = [];
            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] = '';
        }


        if (is_array($value)) {
            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] = $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] ?? [];
            foreach ($value as $key => $val) {
                $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id][$key] = $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id][$key] ?? 0;
                $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id][$key] += $val;
            }
        } else {
            if( !isset($data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id])){
                $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] = 0;
            }

            $value = (int)$value + (int)$data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id];

            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] = $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] ?? 0;
            $data[$wdtc->widget_dashboard_table_id]['data'][$user_id][$column_id] = $value;
        }

        foreach ($data as $table_id => $table_data) {
            foreach ($table_data['data'] as $user_id => $user_data) {
                if (!empty($user_data) && is_array($user_data)) {
                    try {
                        ksort($data[$table_id]['data'][$user_id]);
                    } catch (\Exception $e) {
                        $this->lg('Некорректно проведена сортировка данных' . $e->getMessage(), [
                            'subdomain' => $this->data['account']['subdomain'],
                            '$table_id' => $table_id,
                            '$user_id' => $user_id
                        ], true);
                        exit;
                    }
                }
            }
        }

        // echo "\n\n";
        foreach ($data as $key => $value) {
            if (!isset($value['title'])) {
                unset($data[$key]);
            }
        }



        // сохраняем изменения в базу

        $result =  $this->saveDashboardData($dashboardData,$data,$this->data['account']['subdomain']);

        if($result){
            $cache =  Yii::$app->cache;
            $dataCache = json_encode($data);
            // Сохраняем значение $data в кэше.
            $cache->delete($this->data['account']['subdomain']);
            $cache->set($this->data['account']['subdomain'], $dataCache);
        }

    }

    public function saveDashboardData($dashboardObject,$data,$subdomain)
    {
        $dashboardObject->data = json_encode($data);
        $dashboardObject->update_at = date('Y-m-d');

        return $dashboardObject->save();


    }
}