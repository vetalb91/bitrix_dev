<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 11.03.2021
 * Time: 20:13
 */
namespace frontend\controllers\settings;

use common\models\AmocrmWidgetData;
use common\models\Bitrix24WebhookAuth;
use common\models\settings\DashboardColumn;
use common\models\settings\DashboardTable;
use common\models\settings\DashboardWidget;
use common\models\settings\DashboardWidgetAuth;
use common\models\WidgetDashboardTable;
use common\models\WidgetDashboardTableColumn;
use common\models\WidgetDashboardSearch;
use lib\AmoAjax;
use Yii;
use yii\base\DynamicModel;
use yii\filters\AccessControl;
use yii\web\Controller;
use common\models\WidgetDashboard;
use lib\bitrix24\CRest;
use common\models\ServiceAuth;
use yii\data\ActiveDataProvider;


class DashboardController extends Controller
{

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        //'actions' => ['*'],
                        'roles' => ['@'],
                        'allow' => true
                    ],
                ],
            ],
        ];
    }

    //список аккаунтов
    public function actionIndex(){

        $searchModel = new WidgetDashboardSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);

    }

    //обновление аккаунтов
    public function actionUpdate($id){

        $model = $this->findModel($id);
        $auth = DashboardWidgetAuth::findOne([
            'subdomain' => $model->subdomain,
            'platform' => $model->platform
        ]);

        if ( $model->load(Yii::$app->request->post()) && $auth->load(Yii::$app->request->post()) ) {
            $auth->subdomain = $model->subdomain;
            $auth->platform = $model->platform;
            if( $model->save() && $auth->save() ){
                return $this->redirect(['update', 'id' => $model->id]);
            }
        }

        return $this->render('update', [
            'model' => $model,
            'auth' => $auth,
        ]);

    }

    public function actionCreate(){

        $model = new WidgetDashboard();
        $auth = new DashboardWidgetAuth;

        if ( $model->load(Yii::$app->request->post()) && $auth->load(Yii::$app->request->post()) ) {
            $model->widget_code = 'dashboard';
            $auth->subdomain = $model->subdomain;
            $auth->platform = $model->platform;
            if( $model->save() && $auth->save() ){
                return $this->redirect(['table-list', 'id' => $model->id]);
            }
        }

        return $this->render('create', [
            'model' => $model,
            'auth' => $auth,
        ]);

    }

    //удаление аккаунтов
    public function actionDelete($id){

        //Todo: удалить все связанные с аккаунтом данные

        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    public function actionTableList($id){

        // test
        $dashboard = WidgetDashboard::findOne($id);

        $account_info = $this->getAccount($dashboard->subdomain, $dashboard->platform);

        $dataProvider = new ActiveDataProvider([
            'query' => WidgetDashboardTable::find()->where(['widget_dashboard_id' => $id]),
            'pagination' => [
                'pageSize' => 20,
            ],
            'sort' => [
                'defaultOrder' => [
                    'id' => SORT_ASC,
                ]
            ],
        ]);

        return $this->render('table/index', [
            'dataProvider' => $dataProvider,
            'account_info' => $account_info,
            'widget' => $dashboard
        ]);

    }

    public function actionTableUpdate($id){

        $model = WidgetDashboardTable::findOne($id);
        $account_info = $this->getAccount($model->widget->subdomain, $model->widget->platform);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['table-list', 'id' => $model->widget->id]);
        }

        return $this->render('table/update', [
            'model' => $model,
            'account_info' => $account_info,
            'widget' => $model->widget
        ]);

    }

    public function actionTableCreate($widget_id){

        $model = new WidgetDashboardTable();
        $widget = WidgetDashboard::findOne($widget_id);
        $model->setAttribute('widget_dashboard_id', $widget_id);

        $account_info = $this->getAccount($widget->subdomain, $widget->platform);

        if ($model->load(Yii::$app->request->post()) ) {
            if( $model->save() ){
                return $this->redirect(['table-list', 'id' => $widget->id]);
            }else{
                print_r($model->getErrors());
                return;
            }
        }

        return $this->render('table/create', [
            'model' => $model,
            'account_info' => $account_info,
            'widget' => $widget
        ]);

    }

    public function actionTableDelete($id){

        //Todo: удалить все связанные с аккаунтом данные
        $widget = WidgetDashboardTable::findOne($id);
        $widget_id = $widget->widget->id;
        $widget->delete();

        return $this->redirect(['table-list','id' => $widget_id]);
    }

    public function actionTableColumns($id){

        $table = WidgetDashboardTable::findOne($id);
        $dashboard = $table->widget;
        $account_info = $this->getAccount($dashboard->subdomain, $dashboard->platform);

        $dataProvider = new ActiveDataProvider([
            'query' => WidgetDashboardTableColumn::find()->where(['widget_dashboard_table_id' => $id]),
            'pagination' => [
                'pageSize' => 20,
            ],
            'sort' => [
                'defaultOrder' => [
                    'id' => SORT_ASC,
                ]
            ],
        ]);

        return $this->render('columns/index', [
            'dataProvider' => $dataProvider,
            'table' => $table,
            'account_info' => $account_info,
            'widget' => $dashboard
        ]);

    }

    public function actionTableColumnCreate($table_id){

        $model = new DashboardColumn();
        $table = WidgetDashboardTable::findOne($table_id);
        $dashboard = $table->widget;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {



            if( $model->save() ){
                return $this->redirect(['table-columns', 'id' => $table_id]);
            }
        }

        $account_info = $this->getAccount($dashboard->subdomain, $dashboard->platform);
        $statuses = [];

        foreach ( $account_info['pipelines'] as $pipeline_id => $pipeline ){
            $key = $pipeline['name'];
            foreach ( $pipeline['statuses'] as $status_id => $status_name ){
                $option_key = $pipeline_id . '_' . $status_id;
                $statuses[$key][$option_key] = $status_name;
            }
        }

        return $this->render('columns/create', [
            'widget' => $dashboard,
            'table' => $table,
            'model' => $model,
            'account_info' => $account_info,
            'statuses' => $statuses,
        ]);

    }

    public function actionTableColumnUpdate($id){

        $model = DashboardColumn::findOne($id);
        $table = WidgetDashboardTable::findOne($model->table_id);
        $dashboard = $table->widget;



        if (Yii::$app->request->post() ) {
            $column = DashboardColumn::findOne($id);
            $table_id = $column->table_id;


           if($model->load(Yii::$app->request->post())){
               if($model->save()) {
                   return $this->redirect(['table-columns', 'id' => $model->table_id]);
               }
             }
        }

        $account_info = $this->getAccount($dashboard->subdomain, $dashboard->platform);
        $statuses = [];

        foreach ( $account_info['pipelines'] as $pipeline_id => $pipeline ){
            $key = $pipeline['name'];
            foreach ( $pipeline['statuses'] as $status_id => $status_name ){
                $option_key = $pipeline_id . '_' . $status_id;
                $statuses[$key][$option_key] = $status_name;
            }
        }

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }



        return $this->render('columns/update', [
            'widget' => $dashboard,
            'table' => $table,
            'model' => $model,
            'account_info' => $account_info,
            'statuses' => $statuses,
        ]);

    }

    public function actionTableColumnDelete($id){

        $column = DashboardColumn::findOne($id);
        $table_id = $column->table_id;
        $column->delete($id);

        return $this->redirect(['table-columns','id' => $table_id]);

    }


    public function getAccount($subdomain, $platform){


        switch ( $platform ){
            case 'AmoCRM': {
                AmoAjax::$subdomain = $subdomain;
                AmoAjax::$widget = 'dashboard';

                $result = AmoAjax::get('api/v2/account',[
                    'with' => 'users,pipelines'
                ]);

                $users = [];
                foreach ( $result['_embedded']['users'] as $user ){
                    $users[$user['id']] = $user['name'];
                }

                $pipelines = [];
                foreach ( $result['_embedded']['pipelines'] as $pipeline ){
                    $pipelines[$pipeline['id']]['name'] = $pipeline['name'];
                    foreach ( $pipeline['statuses'] as $status ){
                        $pipelines[$pipeline['id']]['statuses'][$status['id']] = $status['name'];
                    }
                }
                break;
            }
            case 'Bitrix24': {
                //если задание для битрикса, то получаем данные для авторизации
                if (!defined("C_REST_WEB_HOOK_URL")) {
                    $auth = ServiceAuth::getAuth('b24', $subdomain);
                    if( empty($auth) ){
                        $users = [];
                        $pipelines = [];
                    }else{
                        define('C_REST_WEB_HOOK_URL', $auth['webhook_url']);

                        //todo: получаем список пользователей
                        //todo: получаем список воронок
                        $users = [];
                        $user_get_page = 0;
                        do{
                            $request = CRest::call('user.get', ['start' => $user_get_page * 50 ]);

                            if( !empty($request['result']) ){
                                foreach ( $request['result'] as $user ){
                                    if( !empty($user['NAME']) && !empty($user['LAST_NAME']) ){
                                        $users[$user['ID']] = $user['NAME'] . ' ' . $user['LAST_NAME'];
                                    }
                                }
                            }else{
                                //$users = [];
                                break;
                            }
                            $user_get_page++;
                        }while( count($request['result']) % 50 === 0 && count($request['result']) !== 0 );


                        $pipeline_result = [];

                        $temp_request = $request = CRest::call('crm.dealcategory.default.get', []);

                        $pipeline_result[] = $temp_request['result'];

                        $temp_request = CRest::call('crm.dealcategory.list', []);

                        foreach ( $temp_request['result'] as $pipeline ){
                            $pipeline_result[] = $pipeline;
                        }

                        if( !empty($pipeline_result) ){
                            foreach ( $pipeline_result as $pipeline ){
                                $pipelines[$pipeline['ID']]['name'] = $pipeline['NAME'];
                                $request = CRest::call('crm.dealcategory.stage.list', [
                                    'ID' => $pipeline['ID']
                                ]);
                                foreach ( $request['result'] as $status ){
                                    $pipelines[$pipeline['ID']]['statuses'][$status['STATUS_ID']] = $status['NAME'];
                                }
                            }
                        }else{
                            $pipelines = [];
                        }
                    }
                }
                break;
            }
        }

        if( empty($users) || empty($pipelines) ){
            return false;
        }

        $result = [
            'users' => $users,
            'pipelines' => $pipelines
        ];

        return $result;
    }

    protected function findModel($id)
    {
        if (($model = WidgetDashboard::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }



}