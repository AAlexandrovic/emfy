<?php

require "vendor/autoload.php";

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\UsersCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Helpers\EntityTypesInterface;
use League\OAuth2\Client\Token\AccessToken;
use AmoCRM\Models\LeadModel;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Models\Rights\RightModel;
use AmoCRM\Models\UserModel;

class ApiService
{

    private const TOKENS_FILE = './tokens.json';

    /** @var string Базовый домен авторизации. */
    private const TARGET_DOMAIN = 'kommo.com';

    /** @var array $integration_config конфиг  интеграции amoCRM */
    public array $integration_config;

    /** @var AmoCRMApiClient AmoCRM клиент. */
    private AmoCRMApiClient $apiClient;


    public function __construct()
    {
        $this->integration_config = include 'integration.php';
        $this->apiClient = new AmoCRMApiClient(
            $this->integration_config['integrationId'],
            $this->integration_config['integrationSecretKey'],
            $this->integration_config['integrationRedirectUri']
        );
    }

    /**
     * Авторизация.
     *
     * @return string
     */
    public function auth(): string
    {
        session_start();

        if (isset($_GET['name'])) {
            $_SESSION['name'] = $_GET['name'];
        }

        if (isset($_GET['referer'])) {
            $this
                ->apiClient
                ->setAccountBaseDomain($_GET['referer'])
                ->getOAuthClient()
                ->setBaseDomain($_GET['referer']);
        }

        try {
            if (!isset($_GET['code'])) {
                $state = bin2hex(random_bytes(16));
                $_SESSION['oauth2state'] = $state;
                if (isset($_GET['button'])) {
                    echo $this
                        ->apiClient
                        ->getOAuthClient()
                        ->setBaseDomain(self::TARGET_DOMAIN)
                        ->getOAuthButton([
                            'title' => 'Установить интеграцию',
                            'compact' => true,
                            'class_name' => 'className',
                            'color' => 'default',
                            'error_callback' => 'handleOauthError',
                            'state' => $state,
                        ]);
                } else {
                    $authorizationUrl = $this
                        ->apiClient
                        ->getOAuthClient()
                        ->setBaseDomain(self::TARGET_DOMAIN)
                        ->getAuthorizeUrl([
                            'state' => $state,
                            'mode' => 'post_message',
                        ]);
                    header('Location: ' . $authorizationUrl);
                }
                die;
            } elseif (
                empty($_GET['state']) ||
                empty($_SESSION['oauth2state']) ||
                ($_GET['state'] !== $_SESSION['oauth2state'])
            ) {
                unset($_SESSION['oauth2state']);
                exit('Invalid state');
            }
        } catch (Exception $e) {
            die($e->getMessage());
        }

        try {
            $accessToken = $this
                ->apiClient
                ->getOAuthClient()
                ->setBaseDomain($_GET['referer'])
                ->getAccessTokenByCode($_GET['code']);

            if (!$accessToken->hasExpired()) {
                $this->saveToken([
                    'access_token' => $accessToken->getToken(),
                    'refresh_token' => $accessToken->getRefreshToken(),
                    'expires' => $accessToken->getExpires(),
                    'base_domain' => $this->apiClient->getAccountBaseDomain(),
                ]);
            }
        } catch (Exception $e) {
            die($e->getMessage());
        }

        return $_SESSION['name'];
    }

    /**
     * Сохранение токена авторизации по имени аккаунта в бд или файл.
     *
     * @param array $token
     * @return void
     */
    private function saveToken(array $token): void
    {
        $tokens = file_exists(self::TOKENS_FILE)
            ? json_decode(file_get_contents(self::TOKENS_FILE), true)
            : [];
        $tokens[$_SESSION['name']] = $token;
        file_put_contents(self::TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    }

    /**
     * Получение токена из файла.
     *
     * @param string $accountName
     * @return AccessToken
     */
    public function readToken(string $accountName): AccessToken
    {
        return new AccessToken(json_decode(file_get_contents(self::TOKENS_FILE), true)[$accountName]);
    }

    /**
     * Получить сведения о продуктах в сделке
     *
     * @param string $name
     * @param string $lead
     * @return void
     */
    public function getContacts(string $name, string $lead): void
    {
        $apiClient = $this->apiClient;

        $token = $this->readToken($name);
        $test = json_encode($token);
        $test = json_decode($test, true);
        $accessToken = new AccessToken($test);
        $apiClient->setAccessToken($accessToken);
        $apiClient->setAccountBaseDomain($test['base_domain']);

        try {
            $leads = $apiClient->leads()->getOne($lead, [LeadModel::CATALOG_ELEMENTS])->toArray();
            if (empty($leads['catalog_elements'])) {
                exit('в сделке нет продуктов');
            }
            $ids = [];
            foreach ($leads['catalog_elements'] as $item) {
                $ids[] = [
                    $item['catalog_id'],
                    $item['id'],
                    $item['metadata']['quantity']
                ];
            }

            echo "<table>
           <tr>
            <td> наименование </td>
            <td> SKU </td>
            <td> Цена в $ </td>
            <td> Кол-во </td>
            </tr>";

            foreach ($ids as $value) {
                $catalogElementsService = $apiClient
                    ->catalogElements($value[0])
                    ->getOne($value[1])
                    ->toArray();
                echo "<tr>";
                echo "<td>" . $catalogElementsService['name'] . '</td>';
                echo "<td>" . $catalogElementsService['custom_fields_values'][0]['values'][0]['value'] . '</td>';
                echo "<td>" . $catalogElementsService['custom_fields_values'][2]['values'][0]['value'] . '</td>';
                echo "<td>" . $value[2] . '</td>';
                echo "</tr>";
            }
            echo "</table>";
        } catch (AmoCRMMissedTokenException|AmoCRMoAuthApiException $e) {
            echo 'ошибка токена перейдите по ссылке чтобы обновить токен';
            echo "<a href='http://6.rowing123.ru/newToken.php?name=" . $name . "'>Пересоздать токен </a>";
        } catch (AmoCRMApiException $e) {
            echo "произошла ошибка обратитесь к разработчику виджета";
        }
    }

    /**
     * Добавить пользователя
     *
     * @throws AmoCRMMissedTokenException
     */
    public function addUser(string $name): void
    {
        $apiClient = $this->apiClient;

        $token = $this->readToken($name);
        $test = json_encode($token);
        $test = json_decode($test, true);
        $accessToken = new AccessToken($test);
        $apiClient->setAccessToken($accessToken);
        $apiClient->setAccountBaseDomain($test['base_domain']);

        $usersService = $apiClient->users();
        $usersCollection = new UsersCollection();
        $userModel = new UserModel();
        $userModel
            ->setName('emfy')
            ->setEmail('test@emfy.com')
            ->setPassword('1234')
            ->setLang('en')
            ->setRights(
                (new RightModel())
                    ->setLeadsRights([
                        RightModel::ACTION_ADD => RightModel::RIGHTS_DENIED,
                        RightModel::ACTION_VIEW => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_DELETE => RightModel::RIGHTS_FULL,
                        RightModel::ACTION_EXPORT => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_EDIT => RightModel::RIGHTS_FULL,
                    ])
                    ->setCompaniesRights([
                        RightModel::ACTION_ADD => RightModel::RIGHTS_DENIED,
                        RightModel::ACTION_VIEW => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_DELETE => RightModel::RIGHTS_FULL,
                        RightModel::ACTION_EXPORT => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_EDIT => RightModel::RIGHTS_FULL,
                    ])
                    ->setContactsRights([
                        RightModel::ACTION_ADD => RightModel::RIGHTS_DENIED,
                        RightModel::ACTION_VIEW => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_DELETE => RightModel::RIGHTS_FULL,
                        RightModel::ACTION_EXPORT => RightModel::RIGHTS_ONLY_RESPONSIBLE,
                        RightModel::ACTION_EDIT => RightModel::RIGHTS_FULL,
                    ])
                    ->setTasksRights([
                        RightModel::ACTION_DELETE => RightModel::RIGHTS_FULL,
                        RightModel::ACTION_EDIT => RightModel::RIGHTS_FULL,
                    ])
                    ->setMailAccess(false)
                    ->setCatalogAccess(true)
                    ->setIsAdmin(true)

            );
        $usersCollection->add($userModel);

        try {
            $usersCollection = $usersService->add($usersCollection);
        } catch (AmoCRMApiException $e) {
            printError($e);
            die;
        }
    }
}
