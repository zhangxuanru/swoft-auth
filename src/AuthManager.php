<?php
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://doc.swoft.org
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace Swoft\Auth;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Swoft\App;
use Swoft\Auth\Bean\AuthResult;
use Swoft\Auth\Bean\AuthSession;
use Swoft\Auth\Constants\AuthConstants;
use Swoft\Auth\Exception\AuthException;
use Swoft\Auth\Helper\ErrorCode;
use Swoft\Auth\Mapping\AccountTypeInterface;
use Swoft\Auth\Mapping\AuthManagerInterface;
use Swoft\Auth\Mapping\TokenParserInterface;
use Swoft\Auth\Parser\JWTTokenParser;
use Swoft\Bean\Annotation\Value;
use Swoft\Core\RequestContext;
use Swoft\Exception\RuntimeException;

/**
 * Class AuthManager
 * @package Swoft\Auth
 */
class AuthManager implements AuthManagerInterface
{
    /**
     * @var string
     */
    protected $prefix = 'swoft.token.';

    /**
     * @var int
     */
    protected $sessionDuration = 86400;

    /**
     * @var bool
     */
    protected $cacheEnable = false;


    protected $cacheClass;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @Value("${config.auth.tokenParser}")
     * @var string
     */
    protected $tokenParserClass = JWTTokenParser::class;
    /**
     * @var TokenParserInterface
     */
    protected $tokenParser;

    public function getSessionDuration()
    {
        return $this->sessionDuration;
    }

    public function setSessionDuration($time)
    {
        $this->sessionDuration = $time;
    }

    /**
     * @return AuthSession;
     */
    public function getSession()
    {
        return RequestContext::getContextDataByKey(AuthConstants::AUTH_SESSION);
    }

    public function setSession(AuthSession $session)
    {
        RequestContext::setContextData([AuthConstants::AUTH_SESSION => $session]);
    }

    /**
     * @return bool
     *
     * Check if a user is currently logged in
     */
    public function loggedIn()
    {
        return $this->getSession() instanceof AuthSession;
    }

    /**
     * @param $accountTypeName
     * @param array $data
     * @return AuthSession
     */
    public function login(string $accountTypeName, array $data):AuthSession
    {
        if (!$account = $this->getAccountType($accountTypeName)) {
            throw new AuthException(ErrorCode::AUTH_INVALID_ACCOUNT_TYPE);
        }
        $result = $account->login($data);
        if (!$result instanceof AuthResult || $result->getIdentity() == '') {
            throw new AuthException(ErrorCode::AUTH_LOGIN_FAILED);
        }
        $session = $this->generateSession($accountTypeName, $result->getIdentity(), $result->getExtendedData());
        $this->setSession($session);
        if ($this->cacheEnable === true) {
            try {
                $this->getCacheClient()->set(
                    $this->getCacheKey($result->getIdentity()),
                    $session->getToken(),
                    $session->getExpirationTime()
                );
            } catch (InvalidArgumentException $e) {
                $err = sprintf('%s 参数无效,message : %s', $session->getIdentity(),$e->getMessage());
                throw new AuthException(ErrorCode::POST_DATA_NOT_PROVIDED, $err);
            }
        }
        return $session;
    }

    protected function getCacheKey($identity)
    {
        return $this->prefix . $identity;
    }

    /**
     * @param string $accountTypeName
     * @param string $identity
     * @param array $data
     * @return AuthSession
     */
    public function generateSession(string $accountTypeName, string $identity, array $data = [])
    {
        $startTime = time();
        $exp = $startTime + (int)$this->sessionDuration;
        $session = new AuthSession();
        $session
            ->setExtendedData($data)
            ->setExpirationTime($exp)
            ->setCreateTime($startTime)
            ->setIdentity($identity)
            ->setAccountTypeName($accountTypeName);
        $session->setExtendedData($data);
        $token = $this->getTokenParser()->getToken($session);
        $session->setToken($token);
        return $session;
    }

    /**
     * @param $name
     * @return AccountTypeInterface|null
     */
    public function getAccountType($name)
    {
        if (!App::hasBean($name)) {
            return null;
        }
        $account = App::getBean($name);
        if (!$account instanceof AccountTypeInterface) {
            return null;
        }
        return $account;
    }

    public function getTokenParser(): TokenParserInterface
    {
        if($this->tokenParser instanceof TokenParserInterface){
            return $this->tokenParser;
        }
        if(!App::hasBean($this->tokenParserClass)){
            throw new RuntimeException("can`t find %s",$this->tokenParserClass);
        }
        $tokenParser = App::getBean($this->tokenParserClass);
        if(!$tokenParser instanceof TokenParserInterface){
            throw new RuntimeException("%s need implements TokenParserInterface ",$this->tokenParserClass);
        }
        $this->tokenParser = $tokenParser;
        return $this->tokenParser;
    }

    public function getCacheClient(){
        if(!$this->cache instanceof CacheInterface){
            throw new RuntimeException(" AuthManager need cache client");
        }
        return $this->cache;
    }

    /**
     * @param $token
     * @return bool
     * @throws AuthException
     */
    public function authenticateToken(string $token):bool
    {
        try {
            /** @var AuthSession $session */
            $session = $this->getTokenParser()->getSession($token);
        } catch (\Exception $e) {
            throw new AuthException(ErrorCode::AUTH_TOKEN_INVALID);
        }

        if (!$session) {
            return false;
        }

        if ($session->getExpirationTime() < time()) {
            throw new AuthException(ErrorCode::AUTH_SESSION_EXPIRED);
        }

        if (!$account = $this->getAccountType($session->getAccountTypeName())) {
            throw new AuthException(ErrorCode::AUTH_SESSION_INVALID);
        }

        if (!$account->authenticate($session->getIdentity())) {
            throw new AuthException(ErrorCode::AUTH_TOKEN_INVALID);
        }

        if ($this->cacheEnable === true) {
            try {
                $cache = $this->getCacheClient()->get($this->getCacheKey($session->getIdentity()));
                if (!$cache || $cache !== $token) {
                    throw new AuthException(ErrorCode::AUTH_TOKEN_INVALID);
                }
            } catch (InvalidArgumentException $e) {
                $err = sprintf('%s 参数无效,message : %s', $session->getIdentity(),$e->getMessage());
                throw new AuthException(ErrorCode::POST_DATA_NOT_PROVIDED, $err);
            }
        }

        $this->setSession($session);
        return true;
    }
}
