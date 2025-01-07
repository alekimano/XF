<?
/**
 * Аякс-обработчик вывода окна авторизации
 * auth_form.php
 *
 * @updated: 17.10.2017
 */


use Taber\Mindbox\EventHandlers as MindboxEventHandlers;

define('STOP_STATISTICS', true);
define('DisableEventsCheck', true);
define('NO_AGENT_CHECK', true);
// !!! тут обязательно нужно NOT_CHECK_PERMISSIONS = true, чтобы не запускался встроенный функционал авторизации в прологе !!!
//define('NOT_CHECK_PERMISSIONS', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (!\Bitrix\Main\Application::getInstance()->getContext()->getRequest()->isAjaxRequest()) {
    exit();
}

$arReturn = array(
    'status' => false,
    'errorMessage' => '',
    'html' => '',
    'redirectURL' => false,
    'needSubmit' => '',
    'popup' => 0
);

$bShowForm = true;
$mAuthResult = array();
$obRequest = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

$_SESSION['BX_SESS_ROOT_SCRIPT'] = [
    'URL' => '/ajax/auth_form.php',
    'NAME' => 'Авторизация'
];

function phoneInSiebel($siebelClient)
{
    $phoneInSiebel = true;
    if (
        empty($siebelClient->getPhones()[0]['PhoneNumber'])
        || (!empty($siebelClient->getPhones()[0]['PhoneNumber']) && empty($siebelClient->getPhones()[0]['PhoneConfirmedDate']))
    ) {
        $phoneInSiebel = false;
    }

    return $phoneInSiebel;
}

if (check_bitrix_sessid() && $obRequest->isPost() && $obRequest->get('AUTH_FORM') === 'Y') {

    $authUserLogin = trim($obRequest->get('USER_LOGIN'));
    $authUserPassword=$obRequest->get('USER_PASSWORD');

    if(empty($authUserLogin)){
        $mAuthResult['MESSAGE']['USER_LOGIN']=true;
        $mAuthResult['TYPE']= 'EMPTY';
    }

    if(empty($authUserPassword)){
        $mAuthResult['MESSAGE']['USER_PASSWORD']=true;
        $mAuthResult['TYPE']= 'EMPTY';
    }

    if(empty($mAuthResult['MESSAGE']))
    {

    /*
     * Авторизация по EMAIL
     * */
    if (preg_match("/^[^@]+@[^@]+\.[a-z]{2,6}$/ui", $authUserLogin)) {

        /*
         * Извлечем пользователей по EMAIL и отсортируем по дате последней авторизации
         * что бы взять актуальный аккаунт (если их несколько)
         * */
        $filter = array("=EMAIL" => $authUserLogin, 'ACTIVE' => 'Y');
        $rsUser = CUser::GetList(($by = "LAST_LOGIN"), ($order = "desc"), $filter);
        $count = 0;
        $users = [];
        while ($user = $rsUser->fetch()){
            if ($user["LOGIN"]){
                $count++;
            }
            $users[] = $user;
        }
        if ($count > 1){
            foreach ($users as $item) {
                if ($item["LOGIN"] == $item["EMAIL"]){
                    $webUser = new \CUser();
                    $webUserFields = [
                        'LOGIN' => $item["LOGIN"] . '.double.' . time(),
                        'EMAIL' => $item["LOGIN"] . '.double.' . time(),
                    ];
                    $webUser->Update($item["ID"], $webUserFields);
                }else{
                    $authUserLogin = $item["LOGIN"];
                }
            }
        }elseif ($count == 1) {
            if ($arUserData = $users[0]) {
                /*
                 * Возьмем только логин что бы дальнейшая авторизация работала штатно
                 * */
                $authUserLogin = $arUserData["LOGIN"];
            }
        }
    }

    if(!strpos($authUserLogin, '@')){
        $authUserLogin = (new \Taber\Siebel\Utils\Phone($authUserLogin))->getPhone();
    }

    //get the User by login to find out existent user
    $userExists = \CUser::GetList(
        ($by = "ID"), ($order = "ASC"),
        array(
            'LOGIN' => $authUserLogin,'ACTIVE' => 'Y'
        ));

    //if user doesn't exist
    if (intval($userExists->SelectedRowsCount())===0) {
        $mAuthResult['MESSAGE']['USER_LOGIN']=true;
        $mAuthResult['TYPE'] = 'ERROR';
    } else {


        //login user
        $mLoginResult = $GLOBALS['USER']->Login(
            $authUserLogin,
            $obRequest->get('USER_PASSWORD'),
            $obRequest->get('USER_REMEMBER')
        );

        if (!empty($mLoginResult['MESSAGE'])){
            $mAuthResult['MESSAGE']['USER_PASSWORD']=true;
            $mAuthResult['TYPE'] = 'ERROR';
        } else {

            $needSiebelConfirm = !\Taber\User\Utils::CheckUserGroupsByCode(['ADMINISTRATORS', 'ESHOP_OPERATORS']); //Синхронизация с зибелем только для обычных пользователей

            $user = new CUser();
            $webUserData = $user->GetById($user->GetID())->fetch();
            $siebelClient = new \Taber\Siebel\Utils\SiebelClient($webUserData["UF_SIEBEL_ID"]);

            if ($mLoginResult === true && $needSiebelConfirm) {
                /**
                * проверяем есть ли связь с siebel у юзера,
                * запрашиваем телефон на подтверждение
                */
                if (!$webUserData["UF_SIEBEL_ID"] || !phoneInSiebel($siebelClient)) {
                    /*
                    * юзер еще не синхронизирован с siebel или в siebel нет номера телефона
                    */
                    $userPhone = new \Taber\Siebel\Utils\Phone($webUserData["PERSONAL_PHONE"]);
                    ob_start();
                        $GLOBALS['APPLICATION']->IncludeComponent(
                        'taber:phone.confirm',
                        'phone.confirm',
                        array(
                            "WebId" => $webUserData["ID"],
                            "phone" => $userPhone->getPhone(),
                        ),
                        null,
                        array(
                            'HIDE_ICONS' => 'Y'
                        )
                    );
                    $arReturn['html'] = ob_get_clean();
                    $arReturn["popup"] = 1;
                    $bShowForm = false;
                    $USER->Logout();
                } else {
                    /*
                     * сценарий существующего юзера в siebel
                     * 18.12 отменено требование проверки телефона юзера при не пустом UF_SIEBEL_ID
                     * телефон перезаписывается в ЛК на тот, что указан у юзера в siebel
                     * и считается сразу подтвержденным
                     * */

                    $webUser = new \Taber\Siebel\Utils\WebClient($user->GetID());
                    $clientRegistration = new \Taber\Siebel\Application\ClientRegistration($webUser);
                    $phone = new \Taber\Siebel\Utils\Phone($webUser->getPersonalPhone());
                    $clientRegistration->activateWebUser($webUser->getSiebelId(), $phone);
                    $arReturn['email'] = $obRequest->get('USER_LOGIN');
                    \Girlfriend\Models\User\Utils::flushFastRegistrationFields();
                    $arReturn['redirectURL'] = $obRequest->get('redirectURL');
                    $arReturn['userCard'] = 0;
                    $rsCheckCard = CUser::GetList(($by = "id"), ($order = "desc"), array("ID" => $GLOBALS['USER']->GetID()), array("SELECT" => array("UF_DISCOUNT_CARD")));
                    if ($arCard = $rsCheckCard->Fetch()) {
                        if (!empty($arCard["UF_DISCOUNT_CARD"])) {
                            $arReturn['userCard'] = 1;
                            $arReturn['cardNum'] = $arCard["UF_DISCOUNT_CARD"];
                            $arReturn['rrApiBirthdate'] = $arCard["PERSONAL_BIRTHDAY"];
                            $arReturn['rrApiGender'] = $arCard["PERSONAL_GENDER"];
                            $arReturn['rrApiName'] = $arCard['NAME'];
                            $arReturn['rrApiLastName'] = $arCard['LAST_NAME'];
                            $arReturn['rrApiSecondName'] = $arCard['SECOND_NAME'];
                            $arReturn['rrEmail'] = $arCard["EMAIL"];
                        }
                        $city = '';
                        if (CModule::IncludeModule("sale")) {
                            $arOrderProps = \Bitrix\Sale\OrderUserProperties::getList(
                                array(
                                    'order' => array(
                                        'DATE_UPDATE' => 'DESC'
                                    ),
                                    'filter' => array(
                                        'USER_ID' => $GLOBALS['USER']->GetID()
                                    ),
                                    'select' => array('*'),
                                    'limit' => 1
                                )
                            )->fetch();

                            $arrayTmp = array();
                            $orderPropertiesListGroup = CSaleOrderPropsGroup::GetList(
                                array("SORT" => "ASC", "NAME" => "ASC"),
                                array("PERSON_TYPE_ID" => $arOrderProps["PERSON_TYPE_ID"]),
                                false,
                                false,
                                array("ID", "PERSON_TYPE_ID", "NAME", "SORT")
                            );
                            while ($orderPropertyGroup = $orderPropertiesListGroup->GetNext()) {
                                $arrayTmp[$orderPropertyGroup["ID"]] = $orderPropertyGroup;
                                $orderPropertiesList = CSaleOrderProps::GetList(
                                    array("SORT" => "ASC", "NAME" => "ASC"),
                                    array(
                                        "PERSON_TYPE_ID" => $arOrderProps["PERSON_TYPE_ID"],
                                        "PROPS_GROUP_ID" => $orderPropertyGroup["ID"],
                                        "USER_PROPS" => "Y", "ACTIVE" => "Y", "UTIL" => "N"
                                    ),
                                    false,
                                    false,
                                    array("ID", "PERSON_TYPE_ID", "NAME", "TYPE", "REQUIED", "DEFAULT_VALUE", "SORT", "USER_PROPS",
                                        "IS_LOCATION")
                                );
                                while ($orderProperty = $orderPropertiesList->GetNext()) {
                                    if ($orderProperty["TYPE"] == "LOCATION")
                                        $arrayTmp[$orderPropertyGroup["ID"]]["PROPS"][] = $orderProperty;
                                }

                                $propertiesValueList = Array();

                                $resultUserProperties = CSaleOrderUserPropsValue::GetList(
                                    array("SORT" => "ASC"),
                                    array("USER_PROPS_ID" => $arOrderProps["ID"], "ORDER_PROPS_ID" => 1),
                                    false,
                                    false,
                                    array("ID", "ORDER_PROPS_ID", "VALUE", "SORT", "USER_PROPS_ID")
                                );
                                while ($userProperty = $resultUserProperties->GetNext()) {
                                    $propertiesValueList = $userProperty["VALUE"];
                                    $arLocs = CSaleLocation::GetByID($userProperty["VALUE"], LANGUAGE_ID);
                                    if (!empty($arLocs["CITY_NAME"]))
                                        $city = $arLocs["CITY_NAME"];
                                }
                            }
                        }
                        $arReturn['rrApiCity'] = $city;
                    }
                }
            }
            elseif ($mLoginResult === true && !$needSiebelConfirm) //авторизация для админов и кол центра
            {
                $arReturn['redirectURL'] = $obRequest->get('redirectURL');
            }
        }
     }

   }

}



if ($arReturn['needSubmit'] || $arReturn['redirectURL'] !== false) {
    $bShowForm = false;
}

if ($bShowForm) {
    if($USER->isAuthorized()) {
        $USER->Logout();
    }

    $redirectUrl = $obRequest->get('redirectURL');
    if (!$redirectUrl) {
        $redirectUrl = $obRequest->getHeader('referer');
        $redirectUrl = str_replace($obRequest->getHeader('origin'), '', $redirectUrl);
    }

    //новая логика WEB-3059
    $oldOrderUrls = [
        '/order/' ,
        '/order/delivery/' ,
        '/order/auth_order/'
    ];

    if(in_array($redirectUrl, $oldOrderUrls)){
        $redirectUrl = '/order/delivery/';
    }else{
        $redirectUrl = '/order/';
    }

    ob_start();
    $GLOBALS['APPLICATION']->IncludeComponent(
        'taber:system.auth.authorize',
        $obRequest->get('IS_TABER') == 'Y' ? 'taber.modal' : 'gf.17.0.modal',
        array(
            'AUTH_RESULT' => $mAuthResult,
        ),
        null,
        array(
            'HIDE_ICONS' => 'Y'
        )
    );
    $arReturn['html'] = ob_get_clean();
}

//Если это компонент внутри оформления заказа, выводить не в модальное окно, а в html
if ($bShowForm) {
   $arReturn['isOrderAuth'] = true;
}

$arReturn['status'] = true;
$arReturn['check'] = $mAuthResult;

if (!$arReturn['status'] && !$arReturn['errorMessage']) {
    $arReturn['errorMessage'] = 'Неизвестная ошибка';
}

if (!$arReturn['errorMessage'] && empty($mAuthResult)) {
    global $USER;
    if ($USER->IsAuthorized()) {
        MindboxEventHandlers::OnAfterUserAuthorizeHandler($USER->GetID());
    }
}



$GLOBALS['APPLICATION']->RestartBuffer();
header('Content-Type: application/json');
echo json_encode($arReturn);

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
//ГГПК
