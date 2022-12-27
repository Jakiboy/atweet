<?php

namespace Atweet;

use GuzzleHttp\Client as HttpClient;
use Symfony\Component\Yaml\Yaml as Config;

/**
 * Auto-tweet using Twitter OAuth 2.0 API V2.
 * 
 * @see https://developer.twitter.com/en/docs/authentication/guides/v2-authentication-mapping
 * 
 * - App permissions: Read and write
 * - Type of App: Native App
 * - Callback URI / Redirect URL: {https://example.com}/twitter/callback/
 */
abstract class AbstractTwitter
{
	/**
	 * @access private
	 * @var string ENDPOINT
	 * @var string AUTHORIZE_URL
	 * @var string TOKEN_ACTION
	 * @var string USER_ACTION
	 * @var string TWEET_ACTION
	 */
	private const ENDPOINT = 'https://api.twitter.com';
	private const AUTHORIZE_URL = 'https://twitter.com/i/oauth2/authorize?';
	private const TOKEN_ACTION = '/2/oauth2/token';
	private const USER_ACTION = '/2/users/me';
	private const TWEET_ACTION = '/2/tweets';

	/**
	 * @access protected
	 * @var string $dir, Working directory
	 * @var string $redirectUrl
	 * @var string $shortener
	 * @var array $scopes
	 * @var bool $sslVerify
	 * @var int $retry, Refresh token request retry
	 */
	protected $dir;
	protected $redirectUrl;
	protected $shortener;
	protected $scopes = [
		'tweet.read',
		'tweet.write',
		'users.read',
		'offline.access'
	];
	protected $sslVerify = true;
	protected $retry = 0;

	/**
	 * @param string $website, Redirect URL website
	 */
	public function __construct(string $website = '')
	{
		$this->setRedirectUrl($website);
		$this->setClientId();
		$this->startSession();
	}

	/**
	 * Update payload.
	 * 
	 * @access protected
	 * @param void
	 * @return void
	 */
	abstract protected function updatePayload();

	/**
	 * Get refresh token.
	 * 
	 * @access protected
	 * @param void
	 * @return string
	 */
	abstract protected function getRefershToken() : string;

	/**
	 * Get access token (Bearer).
	 * 
	 * @access protected
	 * @param void
	 * @return string
	 */
	abstract protected function getAccessToken() : string;

	/**
	 * Get authentication URL.
	 * @see https://developer.twitter.com/en/docs/authentication/oauth-2-0/user-access-token
	 * 
	 * @access public
	 * @param void
	 * @return string
	 */
	public function getAuthenticationUrl() : string
	{
		$args = [
			'response_type'         => 'code',
			'client_id'             => $this->clientId,
			'redirect_uri'          => $this->redirectUrl,
			'scope'                 => implode(' ',$this->scopes),
			'state'                 => $_SESSION['state'] ?? '',
			'code_challenge'        => $_SESSION['challenge'] ?? '',
			'code_challenge_method' => 'plain'
		];

		return self::AUTHORIZE_URL . http_build_query($args);
	}

	/**
	 * Twitter callback.
	 * 
	 * @access public
	 * @param void
	 * @return void
	 */
	public function callback()
	{
		if ( $this->isValidCallback() ) {

			try {
				$response = $this->getHttpClient()
				->request('POST', self::TOKEN_ACTION, [
				    'form_params' => [
				        'grant_type'    => 'authorization_code',
				        'code'          => $_REQUEST['code'],
				        'client_id'     => $this->clientId,
				        'redirect_uri'  => $this->redirectUrl,
				        'code_verifier' => $_SESSION['challenge'] ?? ''
				    ]
				]);

				$this->setPayload(
					json_decode($response->getBody(),true)
				);

			} catch (Exception $e) {
				$this->log($e->getMessage());

			} catch (\GuzzleHttp\Exception\ClientException $e) {
				$this->log($e->getMessage());
			}

		} elseif ( $this->isCanceledCallback() ) {
			$this->log('Access denied (Canceled)');

		} else {
			$this->log('Invalid callback (Parameters)');
		}
	}

	/**
	 * Publish tweet.
	 * 
	 * @access public
	 * @param string $text
	 * @return mixed
	 */
	public function tweet(string $text = '')
	{
		$this->shortener = false;

	    try {

	        $response = $this->getHttpClient()
	        ->request('POST', self::TWEET_ACTION, [
	            'body'    => json_encode(['text' => $text]),
	            'headers' => [
	                'Content-Type'  => 'application/json',
	                'Authorization' => "Bearer {$this->getAccessToken()}"
	            ]
	        ]);
	        $response = json_decode($response->getBody(),true);
	        $text = $response['data']['text'] ?? '';
			preg_match('/https?\:\/\/[^\",]+/i', $text, $matches);
			$this->shortener = $matches[0] ?? false;
			return $response;

	    } catch (Exception $e) {

	    	if ( $e->getCode() == 401 ) {
	    		
	    		if ( $this->refreshToken() ) {
	    			$this->tweet($text);
	    		}

	    	} else {
	    		$this->log($e->getMessage());
	    	}

	    } catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->log($e->getMessage());
		}
	    
	    return false;
	}

	/**
	 * Get link shortener.
	 * 
	 * @access public
	 * @param void
	 * @return mixed
	 */
	public function getShortener()
	{
	    return $this->shortener;
	}

	/**
	 * Disable SSL verify.
	 * 
	 * @access public
	 * @param void
	 * @return void
	 */
	public function disableSSL()
	{
	    $this->sslVerify = false;
	}

	/**
	 * Set scopes.
	 * 
	 * @access public
	 * @param array $scopes
	 * @return void
	 */
	public function setScopes(array $scopes)
	{
	    $this->scopes = $scopes;
	}

	/**
	 * Set directory.
	 * 
	 * @access public
	 * @param string $scopes
	 * @return void
	 */
	public function setDir(string $dir)
	{
	    $this->dir = $dir;
	}

	/**
	 * Refresh access token (Bearer).
	 * 
	 * @access public
	 * @param void
	 * @return bool
	 */
	public function refreshToken() : bool
	{
		if ( $this->retry < 1 ) {
			$this->retry++;
			$this->log('Refresh token requested');
			try {
				$response = $this->getHttpClient()
				->request('POST', self::TOKEN_ACTION, [
				    'form_params' => [
				        'grant_type'    => 'refresh_token',
				        'refresh_token' => $this->getRefershToken(),
				        'client_id'     => $this->clientId
				    ]
				]);
				$this->setPayload(
					json_decode($response->getBody(),true)
				);
				return true;

			} catch (Exception $e) {
				$this->log($e->getMessage());

			} catch (\GuzzleHttp\Exception\ClientException $e) {
				$this->log($e->getMessage());
			}
		}
		return false;
	}
	
	/**
	 * Get current user.
	 * 
	 * @access protected
	 * @param void
	 * @return mixed
	 */
	protected function getUser()
	{
	    try {

	    	$access = $this->payload['access'] ?? '';
	        $response = $this->getHttpClient()
	        ->request('GET', self::USER_ACTION, [
	            'headers' => [
	                'Content-Type'  => 'application/json',
	                'Authorization' => "Bearer {$access}"
	            ]
	        ]);
	        return json_decode($response->getBody(),true);

	    } catch (Exception $e) {
	    	$this->log($e->getMessage());

	    } catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->log($e->getMessage());
		}
	    
	    return false;
	}

	/**
	 * Start session.
	 * 
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function startSession()
	{
		session_start();
		if ( !isset($_SESSION['state']) ) {
			$_SESSION['state'] = $this->getUniqId();
		}
		if ( !isset($_SESSION['challenge']) ) {
			$_SESSION['challenge'] = $this->getUniqId();
		}
	}

	/**
	 * Check callback.
	 * 
	 * @access protected
	 * @param void
	 * @return bool
	 */
	protected function isValidCallback() : bool
	{
		if ( isset($_REQUEST['state']) && isset($_REQUEST['code']) ) {
			return ($_REQUEST['state'] == $_SESSION['state']);
		}
		return false;
	}

	/**
	 * Check canceled callback.
	 * 
	 * @access protected
	 * @param void
	 * @return bool
	 */
	protected function isCanceledCallback() : bool
	{
		return isset($_REQUEST['error']) 
		&& ($_REQUEST['error'] == 'access_denied');
	}

	/**
	 * Get uniq ID.
	 * 
	 * @access protected
	 * @param void
	 * @return string
	 */
	protected function getUniqId()
	{
		return uniqid();
	}

	/**
	 * Set client ID.
	 * 
	 * @access protected
	 * @param void
	 * @return void
	 * @throws Exception
	 */
	protected function setClientId()
	{
		if ( !file_exists(($file = "{$this->dir}/secret.yaml")) ) {
			@file_put_contents($file,'clientId:');
		}
		$secret = $this->getConfig()::parseFile($file);
		$this->clientId = $secret['clientId'] ?? '';
		if ( empty($this->clientId) ) {
			throw new \Exception('Undefined client ID');
		}
	}

	/**
	 * Set redirect URL.
	 * 
	 * @access protected
	 * @param string $website
	 * @return void
	 * @throws Exception
	 */
	protected function setRedirectUrl(string $website = '')
	{
		if ( empty($website) ) {
			throw new \Exception('Undefined callback website');
		}
		$this->redirectUrl = "{$website}/twitter/callback/";
	}

	/**
	 * Set payload.
	 * 
	 * @access protected
	 * @param array $response
	 * @return void
	 */
	protected function setPayload($response = [])
	{
		// Init payload
		$this->payload = [
			'access'  => $response['access_token'] ?? '',
			'refresh' => $response['refresh_token'] ?? ''
		];

		// Set user
		$user = $this->getUser();
		$this->payload['id'] = $user['data']['id'] ?? '';
		$this->payload['name'] = $user['data']['name'] ?? '';
		$this->payload['username'] = $user['data']['username'] ?? '';

		// Update payload
		$this->updatePayload();
	}

	/**
	 * Get HTTP client.
	 * 
	 * @access protected
	 * @param void
	 * @return HttpClient
	 */
	protected function getHttpClient() : HttpClient
	{
		return new HttpClient([
			'base_uri' => self::ENDPOINT,
			'verify'   => $this->sslVerify
		]);
	}

	/**
	 * Get config.
	 * 
	 * @access protected
	 * @param void
	 * @return Config
	 */
	protected function getConfig() : Config
	{
		return new Config;
	}

    /**
     * Log errors.
     * 
     * @access protected
     * @param string $msg 
     * @return void
     */
    protected function log(string $msg)
    {
		$date = date('[d-m-Y]');
		$file = "{$this->dir}/logs/log-{$date}.txt";
		$date = date('[d-m-Y H:i:s]');
		$msg  = "{$date}: {$msg}" . PHP_EOL;
		@file_put_contents($file,$msg,FILE_APPEND);
    }
}
