<?php

namespace frontend\controllers;

use api\models\ApiLog;
use common\helpers\ErrorLog;
use common\models\Course;
use common\models\InvoiceCourse;
use common\models\Order;
use common\models\Payment;
use common\models\SberCallback;
use common\models\SystemLog;
use frontend\components\sber\OrderStatus;
use Yii;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

class SberController extends Controller
{

    public $enableCsrfValidation = false;

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action)
    {
        $post = \Yii::$app->request->post();
        $log = new SberCallback([
            'callback' => $post ? json_encode(\Yii::$app->request->post()) : file_get_contents("php://input"),
            'created_at' => time(),
        ]);

        $log->save();
        return parent::beforeAction($action);
    }

    /**
     * @brief Акцептовать платеж по callback
     * @return array|string|Response
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function actionIndex()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = \Yii::$app->request;

            $post = $request->post();
            if (!$post) {//Для нового шлюза, то там проходит через контент
                $post = json_decode(file_get_contents("php://input"));
                $mdOrder = $post->mdOrder;
                $operation = $post->operation;
                $orderNumber = $post->orderNumber;
                $status = $post->status;
            } else {//Для старого шлюза через post
                $mdOrder = $request->post('mdOrder');
                $operation = $request->post('operation');
                $orderNumber = $request->post('orderNumber');
                $status = $request->post('status');
            }

            if ($operation != 'deposited') {
                return ['error' => false, 'message' => 'Order no completed'];
            }

            $order = Order::findOne(['orderNumber' => $mdOrder]);
            if (!$order) {
                return ['error' => true, 'message' => 'Order not found'];
            }

            $data = 'mdOrder;' . $mdOrder . ';operation;' . $operation . ';orderNumber;' . $orderNumber . ';status;' . $status . ';';
            $key = null;
            if ($order->is_installment) {
                if (isset(Yii::$app->params['sberbankInstallment']['callback_token'])) {
                    $key = Yii::$app->params['sberbankInstallment']['callback_token'];
                }
            }

            if ($order->is_installment && !$key) {
                ErrorLog::createLog(new \Exception('Не установлен calllback token  сбер рассрочки order_id ' . $order->id));
            }

            if ($key) {
                $hmac = hash_hmac('sha256', $data, $key);

                SystemLog::create((strtoupper($hmac) != $request->post('checksum')) . ' ' . $hmac . ' ' . ' ' . $request->post('checksum'), 'sbercallback');
                if (strtoupper($hmac) != $request->post('checksum')) {
                    ErrorLog::createLog(new \Exception('Не валидные данные callback у ' . ($order->is_installment ? 'сбер рассрочке' : 'сбера')
                        . ' hmac: ' . $hmac
                        . ' data: ' . $data
                        . ' checkSum ' . $request->post('checksum')));
                    throw new BadRequestHttpException('Не валидные данные');
                }
            }

            SystemLog::create($data, 'sberCallback');
            $sberComponent = Payment::sberComponent($order);
            $response = $sberComponent->getOrder($mdOrder);

            if ($response->orderStatus != OrderStatus::ORDER_STATUS_PAID) return $this->render('/finance/failed', ['response' => $response]);


            if ($order->accepted_at) return $this->redirect(['/']);
            $order->email = $response->email;
            $amount = $response->amount / 100;
            if ($order->createPayment((float)$amount)) return ['error' => false, 'message' => 'Payment paid'];

//        $task = new BindCardOrder(['order_id' => $order->id, 'description' => $description, 'amount' => $order->amount]);
//
//        if (!$task->addTaskToList()) {
//            ErrorLog::createLog(new \Exception('Не удалось создать задачу по привязки карты id ' . $this->id . ' ' . $order->id));
//        }

            ErrorLog::createLog(new \Exception('Не удалось акцептовать платеж сбербанка order_id: ' . $order->id));
            throw new BadRequestHttpException('Не удалось акцептовать платеж');
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return ['error' => true, 'message' => 'An error occurred'];
        }
    }
}
