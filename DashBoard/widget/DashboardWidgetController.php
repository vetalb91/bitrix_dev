<?php

namespace frontend\controllers\widget;

use common\models\ServiceAuth;
use common\models\WidgetDashboardData;
use common\models\WidgetDashboardTableColumn;
use frontend\models\ReportCall;
use lib\bitrix24\CRest;
use Yii;
use yii\base\BaseObject;
use yii\filters\VerbFilter;
use yii\web\Controller;
use common\models\WidgetDashboard;
use common\models\WidgetDashboardEventType;
use common\models\WebhookData;

class DashboardWidjetController extends Controller
{
    protected $data;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'index' => ['GET', 'POST'],
                    'ajax' => ['GET'],
                    'webhook' => ['GET','POST'],

                ],
            ],
        ];
    }

    public function beforeAction($action)
    {

        $actions = [
            'index',
            'webhook',
        ];

        if ( in_array($action->id, $actions) ) {
            Yii::$app->request->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    public function actionIndex()
    {

        $this->layout = 'dashboard';


        $get = \Yii::$app->request->get();

        if (!isset($get['subdomain'])) {
            return 'Not account';
        }


        $dashboardData = WidgetDashboardData::find()
            ->where(['subdomain' => $get['subdomain']])
            ->one();



        if (!empty($dashboardData)) {

            $data = json_decode($dashboardData->data, 1);

        } else {
            return 'Account not data';
        }

        if ($data === null) {
            return 'data is corrupted';
        }


        return $this->render('index', [
            'data' => $data
        ]);

    }



    public function actionWebhook()
    {
        $queue_name = 'queue_dashboard';

        //При переходе, создании в статусе

        $_POST = \Yii::$app->request->post();
        $_GET = \Yii::$app->request->get();

        $element = '';
        $account = [];
        $event_name = '';
        $element_event_data = [];
        $element_event_type = '';


        if( isset($_POST['auth']['domain']) ){//предположительно битрикс

            $queue_name = 'queue_dashboard_call'; // определяем очередь для RabbitMQ

            $account['subdomain'] = $_POST['auth']['domain'];
            if( isset($_GET['event_type']) && $_GET['event_type'] == 'deal_change_status' ){//смена статуса

                $event_name = 'deal_status';
                $status = explode(':', $_GET['status']);


                if( count($status) === 1 ){
                    $element_event_data['status_id'] = $status[0];
                    $element_event_data['pipeline_id'] = 0;
                }else{
                    $element_event_data['status_id'] = $status[1];
                    $element_event_data['pipeline_id'] = $status[0];
                }


                $element_event_data['id'] = $_GET['deal_id'];

                if( isset($_GET['modified_user_id']) ){
                    $element_event_data['modified_user_id'] = substr($_GET['modified_user_id'], 5);
                }

            }

            if( isset($_POST['event']) && $_POST['event'] == 'ONVOXIMPLANTCALLEND' ){


                $event_name = 'bx_call';
                $element_event_data = [
                    'note_type' => $_POST['data']['CALL_TYPE'] == 2 ? 10 : 11, // входящие и исходящие звонки
                    'created_by' => $_POST['data']['PORTAL_USER_ID'],
                    'element_id' => 0,
                    'call_id' => $_POST['data']['CALL_ID'],
                    'params' => [
                        'PHONE' => $_POST['data']['PHONE_NUMBER'],
                        'DURATION' => $_POST['data']['CALL_DURATION']
                    ],
                    'date_create' => (new \DateTime($_POST['data']['CALL_START_DATE']))->format('Y-m-d H:i:d'),
                    'platform' => 'bx24'
                ];
            }else{
                $element_event_data['platform'] = 'bx24';
            }

        }
        if( empty($account['subdomain']) ){
            return;
        }


        $this->addEventCallCrm($element_event_data,$account['subdomain']);
        $this->addHistoryEventLeadCrm($event_name,$account['subdomain'],$element_event_data);


        \Yii::$app->{$queue_name}->push(new \frontend\jobs\widget\DashboardWebhook([
            'data' => [
                'account' => $account,
                'element' => $element,
                'event_name' => $event_name,
                'element_event_type' => $element_event_type,
                'element_event_data' => $element_event_data,
                'post_q' => $_POST
            ]
        ]));




    }

    public function addHistoryEventLeadCrm($event_name,$account,$element_event_data){
        if ($event_name == 'lead_status' || $event_name ==  'deal_status' ||  $event_name == 'lead_add') {

            $webHook = new WebhookData();

            $webHook->subdomain = $account;
            $webHook->data = json_encode($element_event_data);
            $webHook->created_at = date('Y-m-d h:i:s');
            $webHook->updated_at = date('Y-m-d h:i:s');

            if (!$webHook->save()) {
                return;
            }

        }
    }

    public function addEventCallCrm($event,$account){
        if (isset($event) && in_array($event, [10, 11]) ){



            if (empty($call)) {
                $webHook = new WebhookData();
                $webHook->subdomain = $account;
                $webHook->data = $event;
                $webHook->created_at = $event['date_create'];
                $webHook->updated_at = $event['created_by'];

                if (!$webHook->save()) {
                    return;
                }


            }
        }
    }

    public function actionTest()
    {

        $widget_dashboard_data = [];
        $widget_dashboard_table_items = [];
        $arResult = [];

        $widget_dashboard = WidgetDashboard::find()
            ->where(['subdomain' => 'reamonn2008'])
            ->one();

        $widget_dashboard_data = $widget_dashboard->toArray();

        foreach ($widget_dashboard->tables as $key => $table) {

            //TODO: написать проверку наличия данных
            $arResult[$table->id]['user_list'] = json_decode($table->user_list, 1);
            $arResult[$table->id]['title'] = $table->title;

            $table_column_sort = [];
            $table_column = [];

            foreach ($table->columns as $column) {
                $table_columns[] = $column->toArray();
                $table_column_sort[] = $column->sort_order;
            }

            try {
                array_multisort($table_column_sort, SORT_ASC, $table_columns);
            } catch (\Exception $e) {
                echo $e->getMessage() . "\n";
            }


        }


    }

    public function actionAjax()
    {

        $this->layout = false;
        Yii::$app->response->headers->add('Access-Control-Allow-Origin', '*');
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        Yii::$app->response->getHeaders()->set('Content-Type', 'application/json; charset=utf-8');
        Yii::$app->response->statusCode = 200;
        Yii::$app->request->enableCsrfValidation = false;


        $get = \Yii::$app->request->get();

        if (!isset($get['subdomain'])) {
            return 'Not account';
        }

        $dashboardData = WidgetDashboardData::find()
            ->where(['subdomain' => $get['subdomain']])
            ->one();

        if (!empty($dashboardData)) {
            $data = $dashboardData->data;
        } else {
            Yii::$app->response->content = 'Account not data';
        }

        if ($dashboardData === null) {
            Yii::$app->response->content = 'data is corrupted';
        }

        Yii::$app->response->content = $data;



    }

    public function actionDataSort($dateFrom = false, $dateTo = null){

        // TODO

    }




}

