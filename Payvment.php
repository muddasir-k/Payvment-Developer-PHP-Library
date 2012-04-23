<?php

/**
 * This module implements the basic authentication methods needed
 * to interface w/ Payvment's Application
 * 
 * Also provides client calls for the Stores and Orders API.  More to come soon!
 * 
 * version: 1.0
 * 
 *
 * @author mjelks
 */

require_once('BasePayvment.php');
defined('PRODUCTION_API_CALLBACK') || define('PRODUCTION_API_CALLBACK', 'https://api.payvment.com');
defined('SANDBOX_API_CALLBACK') || define('SANDBOX_API_CALLBACK', 'https://api-sandbox.payvment.com');


//define('PRODUCTION_APPLICATION_ID', '<YOUR_APP_ID>');              // Update this with your Production Payvment App ID
//define('PRODUCTION_APPLICATION_SECRET', '<YOUR_APP_SECRET>');      // Update this with your Production Payvment App Secret
 
//define('SANDBOX_APPLICATION_ID', '<YOUR_SANDBOX_APP_ID>');         // Update this with your Sandbox Payvment App ID
//define('SANDBOX_APPLICATION_SECRET', '<YOUR_SANDBOX_APP_SECRET>'); // Update this with your Sandbox Payvment App Secret


if (!defined('PRODUCTION_APPLICATION_ID') || !defined('PRODUCTION_APPLICATION_SECRET') || !defined('SANDBOX_APPLICATION_ID') || !defined('SANDBOX_APPLICATION_SECRET'))
    exit("Must define all of the following configurations: PRODUCTION_APPLICATION_ID, PRODUCTION_APPLICATION_SECRET, SANDBOX_APPLICATION_ID, SANDBOX_APPLICATION_SECRET");


class Payvment extends BasePayvment {
    
    private $_request;
    private $_sandbox;
    
    protected $_callbackUrl;
    protected $_applicationId;
    protected $_redirectUrl;
    protected $_payvmentId;
    protected $_payvmentToken;

    public function __set($name, $value)
    {
        $this->{$name} = $value;
    }
    
    public function __construct($request=false)
    {
        // we pass in the request varible to allow for dependency injection
        // http://www.richardcastera.com/blog/php-convert-array-to-object-with-stdclass
        $this->_request = (!empty($request)) ? (object) $request : (object) $_REQUEST;
        $this->_sandbox = (isset($this->_request->sandbox)) ? true : false;
        
        $this->_callbackUrl = ($this->_sandbox) ? SANDBOX_API_CALLBACK : PRODUCTION_API_CALLBACK;
        
        $this->_applicationId = ($this->_sandbox) ? SANDBOX_APPLICATION_ID : PRODUCTION_APPLICATION_ID;
        $this->_applicationSecret = ($this->_sandbox) ? SANDBOX_APPLICATION_SECRET : PRODUCTION_APPLICATION_SECRET;
        
    }
    
    public function generateAuthorizationUrl($redirect=true)
    {
        $_SESSION['state'] = md5(uniqid(rand(), TRUE)); //CSRF protection
        $this->_redirectUrl .= ($this->_sandbox) ? '?sandbox=1' : '';
        
        $authorizeUrl = 
            $this->_callbackUrl . 
            "/oauth/authorize?" .
            "client_id={$this->_applicationId}&" .
            "redirect_uri=" . urlencode($this->_redirectUrl) . "&" .
            "state=" . $_SESSION['state'];
            
        // @codeCoverageIgnoreStart
        if ($redirect) {
            header('Location: ' . $authorizeUrl);
        }
        // @codeCoverageIgnoreEnd
        else {
            return $authorizeUrl;
        } 
    // @codeCoverageIgnoreStart
    }
    // @codeCoverageIgnoreEnd
    
    public function generateTokenUrl()
    {
        $tokenUrl = 
            $this->_callbackUrl . "/oauth/accesstoken?" . 
            "client_id={$this->_applicationId}" . 
            "&client_secret={$this->_applicationSecret}" . 
            "&code=" . $this->_request->code;
        return $tokenUrl;
    }
    
    
    /**
     * Passing in a url or file resource,
     * return the xml document
     * NOTE: simplexml_load_file returns false if invalid or no xml 
     * 
     * @param string $url 
     * @return mixed (boolean/xml) 
     */
    public function getXml($url)
    {
        return simplexml_load_file($url);        
    }
    
    public function postXmlData($url, $data) {
        //open connection
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
        $result = curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Passing in a url or file resource and a POST body,
     * return the xml document
     * NOTE: simplexml_load_file returns false if invalid or no xml 
     * 
     * @param string $url 
     * @return mixed (boolean/xml) 
     */
    public function postXml($url, $datafile)
    {
        $fp = fopen($datafile, 'r');
        if (!$fp) {
            throw new Exception("cannot open data file for postXML");
        }

        return $this->postXmlData($url, stream_get_contents($fp));        
        fclose($fp);
    }
    
    
    public function isUserAuthenticated()
    {
        $authenticated = false;
        
        if (
            isset($this->_payvmentId) && is_int($this->_payvmentId) && 
            isset($this->_payvmentToken) && !empty($this->_payvmentToken)
        ) 
        {
            $authenticated = true;
        }
        
        return $authenticated;
    }
    
    public function generateToken()
    {
        if($this->_request->state == $_SESSION['state']) 
        {
            
            //Make request for access token and Payvment ID
            $xml = $this->getXml($this->generateTokenUrl());
            if (isset($xml->payvment_userid) && isset($xml->token)) {
                $this->setPayvmentId($xml->payvment_userid); //Store Payvment ID to your DB
                $this->setPayvmentToken($xml->token); //Store access token to your DB
            } else {
                throw new Exception('Token and/or xml document not returned.');
            }
        } 
        else
        {
            throw new Exception('The state does not match. You may be a victim of CSRF.');
        }
        
        return true;
    }

    /* Payvment API Support */

    /* Utilities for constructing the Urls */  

    // Stores Management
    // See http://open.payvment.com/docs_getstores.php
    public function getStoresUrl($params="")
    {
        $url = $this->_callbackUrl . "/1/stores/list?access_token=" . 
                $this->_payvmentToken;
        if (!empty($params)) {
            foreach ($params as $key => $val) {
                $url .= "&" . urlencode($key) . '=' . urlencode($val);
            }
        }

        return $url;
    }  
    
    /**
     * This is the REST call for Payvment's orders API
     * the default command will pull all orders for a given retailer
     * 
     * @param string $command
     * @return string $url
     * 
     */
    public function getOrdersUrl($params="")
    {
        $url = $this->_callbackUrl . "/rest/orders/?access_token=" . 
                $this->_payvmentToken;
                
        if (!empty($params)) {
            foreach ($params as $key => $val) {
                $url .= "&" . urlencode($key) . '=' . urlencode($val);
            }
        }

        return $url;
    }
    
    /**
     * This is the REST call for Payvment's orders API
     * the default command will pull all orders for a given retailer
     * 
     * @param string $command
     * @return string $url
     * 
     */
    public function getImportProductsUrl($params="")
    {
        $url = $this->_callbackUrl . "/1/products/import?access_token=" . 
                $this->_payvmentToken;
                
        if (!empty($params)) {
            foreach ($params as $key => $val) {
                $url .= "&" . urlencode($key) . '=' . urlencode($val);
            }
        }

        return $url;
    }
    
    /**
     * This is the REST call for Payvment's Accounts API
     * 
     * @param array $params
     * @return string $url
     * 
     */
    public function getAccountsUrl($params=array())
    {
        $url = $this->_callbackUrl . "/1/accounts/user?access_token=" . 
                $this->_payvmentToken;
        
        if (!empty($params)) {
            foreach ($params as $key => $val) {
                $url .= "&" . urlencode($key) . '=' . urlencode($val);
            }
        }

        return $url;
    }
    
    /**
     * REST calls for Payvment's stores API
     * the default command will pull all orders for a given retailer
     * 
     * @param string $format
     * @return string $result
     */
    public function stores($params=false, $format='xml')
    {
        $result = false;
        if(!$params) {
            $params = array();
        }
        
        switch ($format) {
            case 'xml':
                $result = $this->getXml($this->getStoresUrl($params));
                break;
            default:
                $result = 'Invalid format passed.';
                break;
        }
        
        return $result;
    }
    
    /**
     *
     * Return all orders for a given retailer -- 
     * 
     * @param string $format
     * @return string $result
     */
    public function orders($params=false, $format='xml')
    {
        $result = false;
        
        // default command is pullOrders (pull all orders from Payvment)
        if (!$params) {
            $params = array('command' => 'pullOrders');
        }

        switch ($format) {
            case 'xml':
                $result = $this->getXml($this->getOrdersUrl($params));
                break;
            default:
                $result = 'Invalid format passed.';
                break;
        }
        
        return $result;
    }
    
    /**
     *
     * Import Products for a given retailer -- 
     * 
     * @param string $format
     * @return string $result
     */
    public function importProducts($datafile, $params=false, $format='xml')
    {
        $result = false;
        
        if(!$params) {
            $params = array();
        }
        
        switch ($format) {
            case 'xml':
                $result = $this->postXml($this->getImportProductsUrl($params), $datafile);
                break;
            default:
                $result = 'Invalid format passed.';
                break;
        }
        
        return $result;
    }
    
    /**
     *
     * Create User for a given agency -- 
     * 
     * The default command will create Payvment Account with the given email
     * @param array
     * @format string
     * @return string $result
     */
    public function createUserAccount($email, $first_name, $last_name, $type, $format='xml')
    {
        $result = false;
        
        // must have email parameter
        $user_data = array('command'=>'create',
                           'first_name'=>$first_name,
                           'last_name'=>$last_name,
                           'email'=>$email,
                           'type'=>$type);
        
        switch ($format) {
            case 'xml':
                $user_data['format'] = 'xml';
                $result = $this->postXmlData($this->getAccountsUrl(), $user_data);
                break;
            default:
                $result = 'Invalid format passed.';
                break;
        }
        
        return $result;
    }
    
}
