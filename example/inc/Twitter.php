<?php

require_once __DIR__ . '/vendor/autoload.php';

use Atweet\AbstractTwitter;

/**
 * Twitter OAuth 2.0 implementation for WordPress.
 * Allows multiple websites tweet per single account (Beta).
 * 
 * @todo Use JWT for internal API.
 */
final class Twitter extends AbstractTwitter
{
	/**
	 * @param void
	 */
	public function __construct()
	{
		$this->disableSSL(); // Debug only
		$this->setDir(wp_normalize_path(__DIR__));
		parent::__construct(get_site_url());
	}
	
	/**
	 * Authenticate.
	 * 
	 * @access public
	 * @param void
	 * @return void
	 */
	public function authenticate()
	{
		if ( self::isMainWebsite() ) {
			wp_redirect($this->getAuthenticationUrl());
			return;
		}
		
		if ( $this->getRemoteAccessToken() ) {
			if ( ($user = $this->getUser()) ) {
				$this->updateUser($user);
			}
		}
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
		if ( self::isMainWebsite() ) {
			return parent::refreshToken();
		}
		return $this->getRemoteAccessToken();
	}

	/**
	 * Get remote access token (Bearer).
	 * 
	 * @access public
	 * @param string $access
	 * @return bool
	 */
	public function getRemoteAccessToken() : bool
	{
		$token = get_option('twitter-external-token');
		$endpoint = get_option('twitter-external-endpoint');
		$this->log('Remote access token requested');
		try {

			$response = $this->getHttpClient()
			->request('GET', "{$endpoint}/wp-json/twitter/v1/access/", [
	            'headers' => [
	                'Authorization' => "Bearer {$token}"
	            ]
			]);
			$body = json_decode($response->getBody(),true);
			$access = $body['access'] ?? '';
			$this->payload['access'] = $access;
			$this->updateAccessToken($access);
			return true;

		} catch (Exception $e) {
			$this->log($e->getMessage());

		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->log($e->getMessage());
		}

		return false;
	}

	/**
	 * Init internal API.
	 * 
	 * @access public
	 * @param void
	 * @return void
	 */
	public static function initInternalAPI()
	{
		if ( !get_option('twitter-internal-token') ) {
			update_option('twitter-internal-token',wp_generate_uuid4());
		}
		add_action('rest_api_init',[__CLASS__,'registerInternalApi']);
	}

	/**
	 * Register internal API.
	 *
	 * @access public
	 * @param WP_REST_Server $server
	 * @return void
	 */
	public static function registerInternalApi(\WP_REST_Server $server)
	{
	    register_rest_route('twitter/v1', 'access', [
	        'methods'             => 'GET',
	        'callback'            => [__CLASS__,'internalApiCallback'],
	        'permission_callback' => [__CLASS__,'internalApiPermission']
	    ], false);
	}

	/**
	 * Internal API callback.
	 *
	 * @access public
	 * @param array $request
	 * @return void
	 */
	public static function internalApiCallback($request)
	{
		wp_send_json([
			'access' => get_option('twitter-access-token')
		]);
	}

	/**
	 * Internal API permission.
	 *
	 * @access public
	 * @param array $args
	 * @return bool
	 */
	public static function internalApiPermission($args) : bool
	{
		if ( !self::isMainWebsite() ) {
			return false;
		}
		if ( !($token = self::getBearerToken()) ) {
			return false;
		}
		return (get_option('twitter-internal-token') === $token);
	}

	/**
	 * Check twitter main website.
	 * 
	 * @access public
	 * @param string $access
	 * @return bool
	 */
	public static function isMainWebsite()
	{
		return (get_option('twitter-main-website') == 'yes');
	}

	/**
	 * Remove options.
	 *
	 * @access public
	 * @param void
	 * @return void
	 */
	public static function removeOptions()
	{
		delete_option('twitter-access-token');
		delete_option('twitter-refresh-token');
		delete_option('twitter-account-id');
		delete_option('twitter-account-name');
		delete_option('twitter-account-username');

		// Advanced
		delete_option('twitter-internal-token');
		delete_option('twitter-external-token');
		delete_option('twitter-external-endpoint');
		delete_option('twitter-main-website');
	}

	/**
	 * Update payload.
	 * 
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function updatePayload()
	{
		update_option('twitter-access-token',$this->payload['access']);
		update_option('twitter-refresh-token',$this->payload['refresh']);
		update_option('twitter-account-id',$this->payload['id']);
		update_option('twitter-account-name',$this->payload['name']);
		update_option('twitter-account-username',$this->payload['username']);
	}

	/**
	 * Get refresh token.
	 * 
	 * @access protected
	 * @param void
	 * @return string
	 */
	protected function getRefershToken() : string
	{
		return get_option('twitter-refresh-token');
	}

	/**
	 * Get access token (Bearer).
	 * 
	 * @access protected
	 * @param void
	 * @return string
	 */
	protected function getAccessToken() : string
	{
		return get_option('twitter-access-token');
	}

	/**
	 * Update access token (Bearer).
	 * 
	 * @access private
	 * @param string $access
	 * @return void
	 */
	private function updateAccessToken(string $access)
	{
		update_option('twitter-access-token',$access);
	}

	/**
	 * Update user.
	 * 
	 * @access private
	 * @param array $user
	 * @return void
	 */
	private function updateUser(array $user)
	{
		$id = $user['data']['id'] ?? '';
		$name = $user['data']['name'] ?? '';
		$username = $user['data']['username'] ?? '';
		update_option('twitter-account-id',$id);
		update_option('twitter-account-name',$name);
		update_option('twitter-account-username',$username);
	}

	/**
	 * Get bearer token.
	 * 
     * @access private
     * @param void
     * @return mixed
     */
    private static function getBearerToken()
    {
        if ( ($headers = self::getAuthorizationHeaders()) ) {
        	preg_match('/Bearer\s(\S+)/',$headers,$matches);
            return $matches[1] ?? '';
        }
        return false;
    }

	/**
	 * Get authorization headers.
	 * 
	 * @access private
	 * @param void
	 * @return mixed
	 */
	private static function getAuthorizationHeaders()
	{
        if ( isset($_SERVER['Authorization']) ) {
            return trim($_SERVER['Authorization']);

        } elseif ( isset($_SERVER['http_authorization']) ) {
            return trim($_SERVER['http_authorization']);

        } elseif ( function_exists('apache_request_headers') ) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(
            	array_map('ucwords',array_keys($requestHeaders)),
            	array_values($requestHeaders)
            );
            if ( isset($requestHeaders['Authorization']) ) {
                return trim($requestHeaders['Authorization']);
            }
        }
        return false;
    }
}
