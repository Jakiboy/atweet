<?php

use Atweet\AbstractTwitter;

/**
 * Twitter OAuth 2.0 implementation for WordPress.
 */
final class Twitter extends AbstractTwitter
{
	/**
	 * @param void
	 */
	public function __construct()
	{
		$this->disableSSL(); // Debug only
		parent::__construct(get_site_url(), __DIR__);
	}

	/**
	 * Remove payload.
	 * 
	 * @access public
	 * @param void
	 * @return void
	 */
	public function removePayload()
	{
		delete_option('twitter-access-token');
		delete_option('twitter-refresh-token');
		delete_option('twitter-account-id');
		delete_option('twitter-account-name');
		delete_option('twitter-account-username');
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
}
