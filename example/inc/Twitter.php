<?php

require_once __DIR__ . '/vendor/autoload.php';

use Atweet\AbstractTwitter;

/**
 * Twitter OAuth 2.0 implementation for WordPress.
 * Allows multiple websites tweet per single account.
 * 
 * FOR TESTING PURPOSE ONLY!
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
		wp_redirect($this->getAuthenticationUrl());
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
		// Main account
		if ( get_option('twitter-allow-refresh-token') == 'yes' ) {
			return parent::refreshToken();
		}

		// Get remote access token
		$secret = $this->getConfig()::parseFile("{$this->dir}/secret.yaml");
		$token = $secret['internal']['token'] ?? '';
		$endpoint = $secret['internal']['endpoint'] ?? '';
		$this->log('Remote refresh token requested');
		try {

			$response = $this->getHttpClient()
			->request('GET', "{$endpoint}/wp-json/twitter/v1/access/", [
	            'headers' => [
	                'Authorization' => "Bearer {$token}"
	            ]
			]);
			$body = json_decode($response->getBody(),true);
			$access = $body['access'] ?? '';
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
		if ( get_option('twitter-allow-refresh-token') !== 'yes' ) {
			return false;
		}
		if ( !($token = self::getBearerToken()) ) {
			return false;
		}
		return (get_option('twitter-internal-token') === $token);
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
