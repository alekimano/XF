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
use frontend\components\tbank\OrderStatus;
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
     * @return array|Response
     * @throws BadRequestHttpException
     * @throws Exception
     */
    public function actionIndex()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $request = \Yii::$app->request;

            $post = $request->post();
            if (!$post) {
                $post = json_decode(file_get_contents("php://input"), true);
                $orderId = $post['OrderId'];
                $status = $post['Status'];
                $paymentId = $post['PaymentId'];
            } else {
                $orderId = $request->post('OrderId');
                $status = $request->post('Status');
                $paymentId = $request->post('PaymentId');
            }

            if ($status !== 'CONFIRMED') {
                return ['error' => false, 'message' => 'Order not completed'];
            }

            $order = Order::findOne(['orderNumber' => $orderId]);
            if (!$order) {
                return ['error' => true, 'message' => 'Order not found'];
            }

            // Проверяем подпись
            $data = $post;
            SystemLog::create(json_encode($data), 'tinkoffCallback');
            $key = Yii::$app->params['tinkoff']['password'];
            $token = $data['Token'];
            unset($data['Token']);
            ksort($data);

            $values = '';
            foreach ($data as $value) {
                if (!empty($value)) {
                    $values .= $value;
                }
            }

            $expectedToken = hash('sha256', $key . $values);
            if ($token !== $expectedToken) {
                ErrorLog::createLog(new \Exception('Invalid Tinkoff signature for order_id: ' . $order->id));
                throw new BadRequestHttpException('Invalid signature');
            }


            if ($order->accepted_at) return ['error' => false, 'message' => 'Payment already accepted'];

            $amount = floatval($post['Amount'] / 100); // конвертируем копейки в рубли
            if ($order->createPayment($amount)) {
                return ['error' => false, 'message' => 'OK'];
            }

            ErrorLog::createLog(new \Exception('Failed to accept Tinkoff payment, order_id: ' . $order->id));
            throw new BadRequestHttpException('Failed to accept payment');
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return ['error' => true, 'message' => 'An error occurred'];
        }
    }
}
