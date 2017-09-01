<?php
/**
 * @copyright Copyright (C) 2015-2017 AIZAWA Hina
 * @license https://github.com/fetus-hina/stat.ink/blob/master/LICENSE MIT
 * @author AIZAWA Hina <hina@bouhime.com>
 */

namespace app\controllers;

use Yii;
use app\components\filters\auth\RequestBodyAuth;
use app\models\Battle2;
use app\models\User;
use yii\base\DynamicModel;
use yii\filters\ContentNegotiator;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\helpers\Url;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ApiV2BattleController extends Controller
{
    public $enableCsrfValidation = false;

    public function init()
    {
        Yii::$app->language = 'en-US';
        Yii::$app->timeZone = 'Etc/UTC';
        parent::init();
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
            'authenticator' => [
                'class' => CompositeAuth::class,
                'authMethods' => [
                    HttpBearerAuth::class,
                    ['class' => RequestBodyAuth::class, 'tokenParam' => 'apikey'],
                ],
                'except' => [ 'options' ],
                'optional' => [ 'index', 'view' ],
            ],
        ]);
    }

    protected function verbs()
    {
        return [
            'index'   => ['GET', 'HEAD'],
            'view'    => ['GET', 'HEAD'],
            'create'  => ['POST'],
            'options' => ['OPTIONS'],
        ];
    }

    public function actions()
    {
        $prefix = 'app\actions\api\v2\battle';
        return [
            'create' => [
                'class' => $prefix . '\CreateAction',
            ],
        ];
    }

    public function actionIndex()
    {
        $req = Yii::$app->request;
        $params = [
            'screen_name' => $req->get('screen_name'),
            'newer_than' => $req->get('newer_than'),
            'older_than' => $req->get('older_than'),
            'order' => $req->get('order'),
            'count' => $req->get('count'),
        ];
        $model = DynamicModel::validateData($params, [
            [['screen_name'], 'string'],
            [['screen_name'], 'exist', 'skipOnError' => true,
                'targetClass' => User::class,
                'targetAttribute' => ['screen_name' => 'screen_name'],
            ],
            [['newer_than', 'older_than'], 'integer', 'min' => 1],
            [['order'], 'string'],
            [['order'], 'in', 'range' => ['asc', 'desc']],
            [['count'], 'integer', 'min' => 1, 'max' => 50],
        ]);
        if ($model->hasErrors()) {
            $res = Yii::$app->response;
            $res->format = 'json';
            $res->statusCode = 400;
            return $model->getErrors();
        }
        $query = Battle2::find()
            ->with([
                'agent',
                'agentGameVersion',
                'battleDeathReasons',
                'battleImageGear',
                'battleImageJudge',
                'battleImageResult',
                'battlePlayers',
                'battlePlayers.festTitle',
                'battlePlayers.gender',
                'battlePlayers.rank',
                'battlePlayers.rank.group',
                'battlePlayers.weapon',
                'battlePlayers.weapon.canonical',
                'battlePlayers.weapon.mainReference',
                'battlePlayers.weapon.special',
                'battlePlayers.weapon.subweapon',
                'battlePlayers.weapon.type',
                'battlePlayers.weapon.type.category',
                'env',
                'events',
                'festTitle',
                'festTitleAfter',
                'gender',
                'lobby',
                'map',
                'mode',
                'rank',
                'rank.group',
                'rankAfter',
                'rankAfter.group',
                'rule',
                'splatnetJson',
                'user',
                'user.env',
                'user.userStat',
                'user.userStat2',
                'version',
                'weapon',
                'weapon.special',
                'weapon.subweapon',
                'weapon.type',
                'weapon.type.category',
            ])
            ->orderBy(['id' => SORT_DESC])
            ->limit(10);
        if ($model->screen_name != '') {
            $query
                ->innerJoinWith('user')
                ->andWhere(['{{user}}.[[screen_name]]' => $model->screen_name]);
        }
        if ($model->newer_than != '') {
            $query->andWhere(['>', '{{battle2}}.[[id]]', (int)$model->newer_than]);
        }
        if ($model->older_than != '') {
            $query->andWhere(['<', '{{battle2}}.[[id]]', (int)$model->older_than]);
        }
        if ($model->order != '') {
            $query->orderBy(['id' => ($model->order == 'asc' ? SORT_ASC : SORT_DESC)]);
        }
        if ($model->count != '') {
            $query->limit((int)$model->count);
        }
        $res = Yii::$app->response;
        $res->format = 'compact-json';
        return array_map(
            function ($model) {
                return $model->toJsonArray(['events', 'splatnet_json']);
            },
            $query->all()
        );
    }

    public function actionView($id)
    {
        if (!is_string($id) || !preg_match('/^\d+$/', $id)) {
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
        }
        if (!$model = Battle2::findOne(['id' => $id])) {
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
        }
        $res = Yii::$app->response;
        $res->format = 'compact-json';
        return $model->toJsonArray();
    }

    public function actionOptions($id = null)
    {
        $res = Yii::$app->response;
        if (Yii::$app->request->method !== 'OPTIONS') {
            $res->statusCode = 405;
            return $res;
        }
        $res->statusCode = 200;
        $header = $res->getHeaders();
        $header->set('Allow', implode(
            ', ',
            $id === null
                ? [ 'GET', 'HEAD', 'POST', 'OPTIONS' ]
                : [ 'GET', 'HEAD', /* 'PUT', 'PATCH', 'DELETE', */ 'OPTIONS']
        ));
        $header->set('Access-Control-Allow-Origin', '*');
        $header->set('Access-Control-Allow-Methods', $header->get('Allow'));
        $header->set('Access-Control-Allow-Headers', 'Content-Type, Authenticate');
        $header->set('Access-Control-Max-Age', '86400');
        return $res;
    }
}
