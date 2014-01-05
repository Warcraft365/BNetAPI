<?php
/**
 * Provides easy access to the Battle.net World of Warcraft API.
 * The goal of this library is provide a simple method to access
 * the API with built-in caching, and only a single file to maintain.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * 
 * @package WoW API Client
 * @name PHP World of Warcraft API Client Class
 * @version 0.5 Beta (Tagged: Community Preview, Code Review, Quality Assurance, Expiremental) (Accepting: Bugs, Feature Requests)
 * @author Mysticell of Stormrage (US) <mysticell@warcraft365.com>
 * @copyright 2011 All Rights Reserved
 * @license Simple Public License (SimPL-2.0) http://opensource.org/licenses/Simple-2.0
 * @link http://summit.warcraft365.com/
 * @location R:\PHP World of Warcraft API Class\wowApi.php
 * @location http://warcraft365.com/~mysticell/wowapi/wowApi.php.txt
 * @note Most cache options need extensive testing.  Please email any bugs to <mysticell@warcraft365.com>.
 * @todo Ensure all times are GMT, in S not MS.
 * @todo Item data API.
 * @todo Bother Straton for an auth key to test with.
 * @todo Auction data API (this will be super fun).
 * @todo Better API error response handling.
 * @todo Recipe information API  (http://us.battle.net/api/wow/recipe/33994)
 * @todo Switch _fetchData to HttpRequestPool.
 * @note If using MySQL or SQLite for caching, drop all tables (or recreate the database) before upgrading to version 0.5.
 */

class wowApi
{
    /**
     * Main Battle.net URL Part
     * 
     * @constant string
     */
    const BATTLE_NET_URL_API = '.battle.net/api/wow/';
    
    /**
     * Main Battle.net URL Part (China)
     * 
     * @constant string
     */
    const BATTLE_NET_URL_API_CN = 'battlenet.com.cn/api/wow/';
    
    /**
     * User Agent for API Requests (Extend the class to override this, and include your site's contact URL)
     * 
     * @constant string
     */
    const CLIENT_USER_AGENT = 'libcurl phpWowApi/0.5 (I LOVE YOU, %s!) +http://warcraft365.com/~mysticell/wowapi/wowApi.php.txt';
    
    /**
     * API Forum Posters
     *
     * @access protected
     * @var array
     */
    protected $_bnetDevs = array( 'Straton', 'Peratryn', 'Grotako', 'Osundir' );
    
    /**
     * Battle.net Region  (Use setRegion to change)
     * 
     * @access protected
     * @var string
     */
    protected $_region;
    
    /**
     * Use SSL for API requests?
     * 
     * @access private
     * @var boolean
     */
    private $_ssl;
    
    /**
     * API Authentication Credentials
     * 
     * @access private
     * @var array
     */
    private $_authCredentials;
    
    /**
     * Cache method (set in cacheInit)
     *
     * @access private
     * @var string
     */
    private $_cacheMethod;
    
    /**
     * Cache Settings
     *
     * @access private
     * @var array
     */
    private $_cacheSettings;
    
    /**
     * Remember that loot chest back in Ulduar, the Cache of Winter?  Well, this is a different kind of cache.
     *
     * @access private
     * @var object
     */
    private $_cacheObject;
    
    /**
     * Imaging settings for setImagingDirs.
     *
     * @access protected
     * @var array
     */
    protected $_imagingSettings;
    
    /**
     * Trigger non-fatal errors?
     *
     * @access public
     * @var boolean
     * @todo Do not show non-fatal errors when this variable is set to false.
     */
    public $errorReporting = TRUE;
    
    /**
     * File for logging all requests to the Battle.net service.
     *
     * @access public
     * @var string
     * @todo Log all cURL requests when this variable is set to a filename.
     */
    public $requestLog;
    
    /**
     * Constructor
     * 
     * @access public
     * @param string $region Initial region: us, eu, kr, tw
     * @param boolean $ssl Set to true to use SSL for API requests.
     * @return void
     */
    public function __construct($region, $ssl = FALSE)
    {
        if( !is_bool( $ssl ) )
        {
            /**
             * @error_id 1
             * @severity Fatal -> Security Exception
             * @explanation $ssl parameter is not set properly.
             */
            throw new Exception( '[#1] ' . __CLASS__ . ' $ssl parameter should be boolean.', 1 );
        }
        if( !function_exists( 'json_decode' ) )
        {
            /**
             * @error_id 2
             * @severity Fatal -> Missing Function
             * @explanation JSON functions are not present in the PHP compilation.
             */
            throw new Exception( '[#2] ' . __CLASS__ . ' requires JSON functions.', 2 );
        }
        if( !function_exists( 'curl_init' ) )
        {
            /**
             * @error_id 3
             * @severity Fatal -> Missing Functions
             * @explanation CURL functions are not present in the PHP compilation.
             */
            throw new Exception( '[#3] ' . __CLASS__ . ' requires CURL functions.', 3 );
        }
        
        $this->setRegion( $region );
        if( !isset( $this->_region ) )
        {
            /**
             * @error_id 8
             * @severity Fatal -> Invalid Region
             * @explanation An invalid region was specified when instantiating the class.  Region should be either us, eu, kr, or tw.
             */
            throw new Exception( '[#8] ' . __CLASS__ . ' invalid region specified.', 8 );
        }
        
        $this->_ssl = $ssl;
        $this->_authCredentials = array();
        
        $this->_getAllRealms();
    }
    
    /**
     * Destructor
     * 
     * @access public
     * @return void
     */
    public function __destruct()
    {
        if( isset( $this->_cacheSettings ) )
        {
            $this->_cacheShutdown( FALSE );
        }
    }
    
    /**
     * Set up caching.
     * 
     * <pre>
     * $cacheOpts should be an array of options, some of which are specific to certain caching methods.
     * Note: If your application uses its own MySQL database, you should implement your own caching method using the same connection for increased performance.
     * --------- --------- ------------------------ ----------------------------------------------------------------------------------
     * Method    Type      Name                     Description
     * --------- --------- ------------------------ ----------------------------------------------------------------------------------
     * <ALL>     (integer) $cacheOpts['ttl']        Default cache time to live in seconds.  Certain caches multiply by this value.
     * FILE      (string)  $cacheOpts['file']       Location of the cache file to use.
     * CACHELITE (string)  $cacheOpts['dir']        Directory to store cache files in.
     * MEMCACHED (boolean) $cacheOpts['persistent'] Use persistent Memcached connection?
     * MEMCACHED (array)   $cacheOpts['servers']    Array of Memcached servers to use.
     * MEMCACHED (boolean) $cacheOpts['skipCheck']  Skip checking of Memcached servers if you know the connection details are correct.
     * SQLITE    (string)  $cacheOpts['file']       Location of the database file to use.
     * SQLITE    (string)  $cacheOpts['prefix']     Database table prefix to use.
     * MYSQL     (string)  $cacheOpts['host']       MySQL server hostname or IP.  Prefix with 'p:' to create a persistent connection.
     * MYSQL     (string)  $cacheOpts['user']       MySQL username.
     * MYSQL     (string)  $cacheOpts['pass']       MySQL password.
     * MYSQL     (string)  $cacheOpts['database']   MySQL database name.
     * MYSQL     (integer) $cacheOpts['port']       MySQL server port, if not default (3306).
     * MYSQL     (string)  $cacheOpts['prefix']     Database table prefix to use.
     * MYSQL     (string)  $cacheOpts['engine']     MySQL storage engine to use.  Server default if left blank.
     * MYSQL     (boolean) $cacheOpts['useMemory']  If true, the MEMORY storage engine will be used for realms and misc cache tables.
     * </pre>
     *
     * @access public
     * @param string $method Caching method to use.
     * @param array $cacheOpts Array of options specific to the caching method.
     * @return boolean
     */
    public function cacheInit($method, $cacheOpts)
    {
        $method = strtolower( $method );
        
        if( isset( $this->_cacheSettings ) )
        {
            /**
             * @error_id 23
             * @severity Warning -> Halting Method Execution
             * @explanation cacheInit has already been called successfully, and cannot be called again.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#23] Cannot change cache settings after initial setup.', E_USER_NOTICE );
            }
            return FALSE;
        }
        if( !in_array( $method, array( 'pageload', 'file', 'cachelite', 'apc', 'memcached', 'sqlite', 'mysql' ) ) )
        {
            /**
             * @error_id 22
             * @severity Warning -> Halting Method Execution
             * @explanation $method should be one of the above options.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#22] Invalid $method for cacheSetOpts.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        $this->_cacheMethod = $method;
        $this->_cacheSettings = $cacheOpts;
        
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                $this->_cacheInstall();
                break;
            case 'file':
                if( file_exists( $this->_cacheSettings['file'] ) )
                {
                    $this->_cacheObject = unserialize( file_get_contents( $this->_cacheSettings['file'] ) );
                }
                else
                {
                    $this->_cacheInstall();
                }
                break;
            case 'cachelite':
                if( !file_exists( 'class.cacheLite.php' ) )
                {
                    /**
                     * @error_id 24
                     * @severity Fatal -> Cache Initialization Error
                     * @explanation Cache method is set to cachelite, but the class file was not found.
                     */
                    throw new Exception( '[#24] CacheLite class file not found.', 24 );
                }
                
                require_once( 'class.cacheLite.php' );
                $optsCompiled = array( 'cacheDir' => $this->_cacheSettings['dir'],
                                        'caching' => TRUE,
                                       'lifeTime' => $this->_cacheSettings['ttl'],
                                    'fileLocking' => TRUE,
                                   'writeControl' => TRUE,
                                    'readControl' => TRUE,
                                'readControlType' => 'strlen',
                             'fileNameProtection' => TRUE,
                         'automaticSerialization' => TRUE );
                $this->_cacheObject = new Cache_Lite( $optsCompiled );
                break;
            case 'apc':
                if( !function_exists( 'apc_fetch' ) )
                {
                    /**
                     * @error_id 25
                     * @severity Fatal -> Cache Initialization Error
                     * @explanation Cache method is set to apc, but APC functions are not present.
                     */
                    throw new Exception( '[#25] APC functions not available.', 25 );
                }
                break;
            case 'memcached':
                if( $cacheOpts['persistent'] == TRUE )
                {
                    $this->_cacheObject = new Memcached( 'wowApiCache' );
                }
                else
                {
                    $this->_cacheObject = new Memcached();
                }
                
                $serversCount = 0;
                foreach( $this->_cacheObject->getServersList as $server )
                {
                    $serversCount++;
                }
                if( isset( $this->_cacheSettings['servers'] ) && $serversCount < 1 )
                {
                    $this->_cacheObject->addServers( $this->_cacheSettings['servers'] );
                }
                
                if( $this->_cacheSettings['skipCheck'] != TRUE )
                {
                    if( !isset( $this->_cacheObject->getStats['pid'] ) )
                    {
                        /**
                         * @error_id 26
                         * @severity Fatal -> Cache Initialization Error
                         * @explanation Something went wrong with Memcached.
                         */
                        throw new Exception( '[#26] Memcached error.', 26 );
                    }
                }
                break;
            case 'sqlite':
                $this->_cacheObject = new SQLite3( $this->_cacheSettings['file'] );
                if( !$this->_cacheObject )
                {
                    /**
                     * @error_id 28
                     * @severity Fatal -> Cache Initialization Error
                     * @explanation Something went wrong while initializing the SQLite database.
                     */
                    throw new Exception( '[#28] SQLite error.', 28 );
                }
                
                $this->_cacheObject->busyTimeout( 100 );
                
                if( $this->_cacheObject->querySingle( 'SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'' . $this->_cacheSettings['prefix'] . 'realms\'' ) != 'realms' )
                {
                    $this->_cacheInstall();
                }
                break;
            case 'mysql':
                /**
                 * @note If $cacheOpts['host'] begins with 'p:', the connection will be persistent.
                 */
                if( isset( $this->_cacheSettings['port'] ) )
                {
                    $this->_cacheObject = new mysqli( $this->_cacheSettings['host'], $this->_cacheSettings['user'], $this->_cacheSettings['pass'], $this->_cacheSettings['database'], $this->_cacheSettings['port'] );
                }
                else
                {
                    $this->_cacheObject = new mysqli( $this->_cacheSettings['host'], $this->_cacheSettings['user'], $this->_cacheSettings['pass'], $this->_cacheSettings['database'] );
                }
                if( $this->_cacheObject->connect_error )
                {
                    /**
                     * @error_id 27
                     * @severity Fatal -> Cache Initialization Error
                     * @explanation Something went wrong while connecting to the MySQL database.
                     */
                    throw new Exception( '[#27] MySQL error: (' . $this->_cacheObject->connect_errno . ') ' . $this->_cacheObject->connect_error, 27 );
                }
                
                if( $this->_cacheObject->query( 'SHOW TABLES LIKE \'' . $this->_cacheSettings['prefix'] . 'realms\'' ) )
                {
                    $queryResult = $this->_cacheObject->use_result();
                    if( $queryResult->num_rows < 1 )
                    {
                        $this->_cacheInstall();
                    }
                }
                else
                {
                    /**
                     * @error_id 29
                     * @severity Fatal -> Cache Initialization Error
                     * @explanation Could not execute installation check query.
                     */
                    throw new Exception( '[#29] Could not execute MySQL installation check query.', 29 );
                }
                break;
        }
    }
    
    /**
     * Shutdown the cache.
     * 
     * @access public
     * @return void
     */
    public function _cacheShutdown($unset = TRUE)
    {
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                // Nothing to do here.
                break;
            case 'file':
                file_put_contents( $this->_cacheSettings['file'], serialize( $this->_cacheObject ) );
                break;
            case 'cachelite':
                // Nothing to do here.
                break;
            case 'apc':
                // Nothing to do here.
                break;
            case 'memcached':
                // Nothing to do here.
                break;
            case 'sqlite':
                break;
            case 'mysql':
                break;
        }
        if( $unset )
        {
            unset( $this->_cacheMethod );
            unset( $this->_cacheSettings );
            unset( $this->_cacheObject );
        }
    }
    
    /**
     * Run first-time installation for caching methods that need it.
     * 
     * @access protected
     * @return void
     * @todo sqlite, mysql
     */
    protected function _cacheInstall()
    {
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                $this->_cacheObject = new stdClass();
                $this->_cacheObject->realms = array();
                $this->_cacheObject->characters = array();
                $this->_cacheObject->guilds = array();
                $this->_cacheObject->arenaTeams = array();
                $this->_cacheObject->auctionData = array();
                $this->_cacheObject->misc = array();
                break;
            case 'file':
                $this->_cacheObject = new stdClass();
                $this->_cacheObject->realms = array();
                $this->_cacheObject->characters = array();
                $this->_cacheObject->guilds = array();
                $this->_cacheObject->arenaTeams = array();
                $this->_cacheObject->auctionData = array();
                $this->_cacheObject->misc = array();
                break;
            case 'cachelite':
                // Nothing to do here.
                break;
            case 'apc':
                // Nothing to do here.
                break;
            case 'memcached':
                // Nothing to do here.
                break;
            case 'sqlite':
                // Todo
                break;
            case 'mysql':
                if( isset( $this->_cacheSettings['engine'] ) )
                {
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'misc`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'realms`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'characters`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'guilds`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'arenaTeams`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'auctionData`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=' . $this->_cacheSettings['engine'] );
                }
                elseif( $this->_cacheSettings['useMemory'] == TRUE )
                {
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'misc`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=MEMORY' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'realms`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) ) ENGINE=MEMORY' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'characters`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'guilds`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'arenaTeams`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'auctionData`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                }
                else
                {
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'misc`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'realms`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'characters`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'guilds`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'arenaTeams`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                    $this->_cacheObject->query( 'CREATE TABLE `' . $this->_cacheSettings['prefix'] . 'auctionData`( `key` varchar(255) NOT NULL , `data` longtext , `expires` int UNSIGNED , PRIMARY KEY (`key`) )' );
                }
                break;
        }
    }
    
    /**
     * Generate a file-safe cache name.
     *
     * @deprecated
     * @access protected
     * @param mixed $input String to clean.
     * @return string 
     */
    protected function _cacheGenerateName($input)
    {
        if( is_array( $input ) )
        {
            $input = implode( '__', $input );
        }
        return utf8_encode( str_replace( ' ', '_', $input ) );
    }
    
    /**
     * Generate the timestamp when a cache will be removed.
     *
     * @access protected
     * @param integer $ttl Time to live.
     * @return integer
     */
    protected function _cacheGenerateTtl($ttl = NULL)
    {
        if( $ttl != NULL )
        {
            return time() + $ttl;
        }
        return time() + $this->_cacheSettings['ttl'];
    }
    
    /**
     * Add an item to the cache.
     *
     * @access protected
     * @param string $type Cache group (characters, guilds, arenaTeams, realms, or misc).
     * @param string $key Cache key (ex: name of a character, guild, etc).
     * @param mixed $data Data to store (any datatype will be accepted).
     * @param integer $ttl Time to live.
     * @return void
     * @todo Error checking
     */
    protected function _cacheStore($type, $key, $data, $ttl = NULL)
    {
        if( !is_int( $ttl ) )
        {
            /**
             * @error_id 30
             * @severity Warning -> Variable Defaulted
             * @explanation $ttl must be integer.
             */
            trigger_error( '[#30] $ttl must be integer, using default.', E_USER_NOTICE );
            $ttl = NULL;
        }
        if( $ttl == NULL )
        {
            $ttl = $this->_cacheSettings['ttl'];
        }
        
        if( !isset( $this->_cacheSettings ) )
        {
            return TRUE;
        }
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                $data['dropTime'] = $this->_cacheGenerateTtl( $ttl );
                $this->_cacheObject->{$type}[$key] = $data;
                break;
            case 'file':
                $data['dropTime'] = $this->_cacheGenerateTtl( $ttl );
                $this->_cacheObject->{$type}[$key] = $data;
                break;
            case 'cachelite':
                $this->_cacheObject->setLifeTime( $ttl );
                $this->_cacheObject->save( $data, $key, $type );
                break;
            case 'apc':
                $dataString = serialize( $data );
                apc_store( $type . '++' . $key, $dataString, $ttl );
                break;
            case 'memcached':
                $this->_cacheObject->set( $type . '++' . $key, $data, $this->_cacheGenerateTtl( $ttl ) );
                break;
            case 'sqlite':
                $table = $this->_cacheSettings['file'] . '.' . $this->_cacheSettings['prefix'] . $type;
                $dataString = $this->_cacheObject->escapeString( serialize( $data ) );
                $expires = time() + $ttl;
                $this->_cacheObject->exec( 'INSERT OR REPLACE INTO ' . $table . ' (key, data, expires) VALUES (' . $key . ', ' . $dataString . ', ' . $expires . ')' );
                break;
            case 'mysql':
                $dataString = $this->_cacheObject->real_escape_string( serialize( $data ) );
                $expires = time() + $ttl;
                $this->_cacheObject->query( 'REPLACE INTO ' . $this->_cacheSettings['prefix'] . $type . ' (key, data, expires) VALUES (' . $key . ', ' . $dataString . ', ' . $expires . ')' );
                break;
        }
    }
    
    /**
     * Fetch an item from the cache.
     *
     * @access protected
     * @param string $type Cache group (characters, guilds, arenaTeams, realms, or misc).
     * @param string $key Cache key (ex: name of a character, guild, etc).
     * @return mixed Data in the cache if exists, or false.
     */
    protected function _cacheFetch($type, $key)
    {
        if( !isset( $this->_cacheSettings ) )
        {
            return FALSE;
        }
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                if( isset( $this->_cacheObject->{$type}[$key] ) )
                {
                    if( $this->_cacheObject->{$type}[$key]['dropTime'] > time() )
                    {
                        return $this->_cacheObject->{$type}[$key];
                    }
                    else
                    {
                        $this->_cacheDrop($type, $key);
                    }
                }
                return FALSE;
                break;
            case 'file':
                if( isset( $this->_cacheObject->{$type}[$key] ) )
                {
                    if( $this->_cacheObject->{$type}[$key]['dropTime'] > time() )
                    {
                        return $this->_cacheObject->{$type}[$key];
                    }
                    else
                    {
                        $this->_cacheDrop( $type, $key );
                        return FALSE;
                    }
                }
                return FALSE;
                break;
            case 'cachelite':
                return $this->_cacheObject->get( $key, $type );
                break;
            case 'apc':
                return unserialize( apc_fetch( $type . '++' . $key ) );
                break;
            case 'memcached':
                return $this->_cacheObject->get( $type . '++' . $key );
                break;
            case 'sqlite':
                $table = $this->_cacheSettings['file'] . '.' . $this->_cacheSettings['prefix'] . $type;
                $queryReturn = $this->_cacheObject->querySingle( 'SELECT data, expires FROM ' . $table . ' WHERE key=' . $key );
                if( $queryReturn )
                {
                    if( $queryReturn['expires'] > time() )
                    {
                        return unserialize( $queryReturn['data'] );
                    }
                    else
                    {
                        $this->_cacheDrop( $type, $key );
                    }
                }
                return FALSE;
                break;
            case 'mysql':
                if( $this->_cacheObject->query( 'SELECT data, expires FROM ' . $this->_cacheSettings['prefix'] . $type . ' WHERE key=' . $key ) )
                {
                    $result = $this->_cacheObject->use_result();
                    if( $result )
                    {
                        $queryReturn = $result->fetch_assoc();
                        if( $queryReturn['expires'] > time () )
                        {
                            return unserialize( $queryReturn['data'] );
                        }
                        else
                        {
                            $this->_cacheDrop( $type, $key );
                        }
                    }
                }
                return FALSE;
                break;
        }
    }
    
    /**
     * Drop an item from the cache.
     *
     * @access protected
     * @param string $type Cache group (characters, guilds, arenaTeams, realms, or misc).
     * @param string $key Cache key (ex: name of a character, guild, etc).
     * @return boolean True if dropped successfully, false if error or not 
     * @todo Return checks for SQLite and MySQL
     */
    protected function _cacheDrop($type, $key)
    {
        if( !isset( $this->_cacheSettings ) )
        {
            return TRUE;
        }
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                if( isset( $this->_cacheObject->{$type}[$key] ) )
                {
                    unset( $this->_cacheObject->{$type}[$key] );
                    return TRUE;
                }
                return FALSE;
                break;
            case 'file':
                if( isset( $this->_cacheObject->{$type}[$key] ) )
                {
                    unset( $this->_cacheObject->{$type}[$key] );
                    return TRUE;
                }
                return FALSE;
                break;
            case 'cachelite':
                return $this->_cacheObject->remove( $key, $type );
                break;
            case 'apc':
                return apc_delete( $type . '++' . $key );
                break;
            case 'memcached':
                return $this->_cacheObject->delete( $type . '++' . $key );
                break;
            case 'sqlite':
                $table = $this->_cacheSettings['file'] . '.' . $this->_cacheSettings['prefix'] . $type;
                $this->_cacheObject->exec( 'DELETE FROM ' . $table . ' WHERE key=' . $key );
                return TRUE;
                break;
            case 'mysql':
                $this->_cacheObject->query( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . $type . ' WHERE key=' . $key );
                return TRUE;
                break;
        }
    }
    
    /**
     * Dump the entire cache.
     * 
     * @access public
     * @return boolean True on success.
     * @todo Return checks
     */
    public function cacheDropAll()
    {
        switch( $this->_cacheMethod )
        {
            case 'pageload':
                unset( $this->_cacheObject );
                $this->_cacheInstall();
                break;
            case 'file':
                unlink( $this->_cacheSettings['file'] );
                unset( $this->_cacheObject );
                $this->_cacheInstall();
                break;
            case 'cachelite':
                $this->_cacheObject->clean();
                break;
            case 'apc':
                apc_clear_cache();
                break;
            case 'memcached':
                $this->_cacheObject->flush();
                break;
            case 'sqlite':
                $this->_cacheObject->exec( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . 'realms' );
                $this->_cacheObject->exec( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . 'characters' );
                $this->_cacheObject->exec( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . 'guilds' );
                $this->_cacheObject->exec( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . 'arenaTeams' );
                $this->_cacheObject->exec( 'DELETE FROM ' . $this->_cacheSettings['prefix'] . 'misc' );
                break;
            case 'mysql':
                $this->_cacheObject->query( 'TRUNCATE TABLE ' . $this->_cacheSettings['prefix'] . 'realms' );
                $this->_cacheObject->query( 'TRUNCATE TABLE ' . $this->_cacheSettings['prefix'] . 'characters' );
                $this->_cacheObject->query( 'TRUNCATE TABLE ' . $this->_cacheSettings['prefix'] . 'guilds' );
                $this->_cacheObject->query( 'TRUNCATE TABLE ' . $this->_cacheSettings['prefix'] . 'arenaTeams' );
                $this->_cacheObject->query( 'TRUNCATE TABLE ' . $this->_cacheSettings['prefix'] . 'misc' );
                break;
        }
        $this->_getAllRealms();
        return TRUE;
    }
    
    /**
     * Check if a key exists in the cache, and execute a function if not, then return the data.
     *
     * @access protected
     * @param string $type Cache group (characters, guilds, arenaTeams, realms, or misc).
     * @param string $key Cache key (ex: name of a character, guild, etc).
     * @param string $execute String that will be eval'd, stored, and returned if nothing is in the cache for the key.
     * @param integer $ttl Time to live.
     * @return mixed Requested cache data.
     */
    protected function _cacheFetchOrExecute($type, $key, $execute, $ttl = NULL)
    {
        $check = $this->_cacheFetch( $type, $key );
        if( $check )
        {
            return $check;
        }
        else
        {
            $data = exec( $execute );
            $this->_cacheStore( $type, $key, $data, $ttl );
            return $data;
        }
    }
    
    /**
     * Generate an API url based on the region, method, and query string.
     * 
     * @access protected
     * @param string $method API resource 
     * @param mixed $realm
     * @param string $name
     * @param mixed $options
     * @return string
     */
    protected function _generateApiUrl($method, $realm = NULL, $name = NULL, $options = NULL)
    {
        if( strpos( 'realm', $method ) )
        {
            if( is_string( $realm ) )
            {
                $query = '?realm=' . $realm;
            }
            elseif( is_array( $realm ) )
            {
                $query = '?realms=' . implode( ',', $realm );
            }
            else
            {
                $query = NULL;
            }
            unset( $realm );
        }
        else
        {
            if( is_string( $options ) )
            {
                $query = '?fields=' . $options;
            }
            elseif( is_array( $options ) )
            {
                $query = '?fields=' . implode( ',', $options );
            }
            else
            {
                $query = NULL;
            }
        }
        
        if( $realm && $name )
        {
            if( $this->_ssl == TRUE )
            {
                return 'https://' . $this->_region . self::BATTLE_NET_URL_API . $method . '/' . $realm . '/' . $name . $query;
            }
            else
            {
                return 'http://' . $this->_region . self::BATTLE_NET_URL_API . $method . '/' . $realm . '/' . $name . $query;
            }
        }
        elseif( $realm )
        {
            if( $this->_ssl == TRUE )
            {
                return 'https://' . $this->_region . self::BATTLE_NET_URL_API . $method . '/' . $realm . $query;
            }
            else
            {
                return 'http://' . $this->_region . self::BATTLE_NET_URL_API . $method . '/' . $realm . $query;
            }
        }
        else
        {
            if( $this->_ssl == TRUE )
            {
                return 'https://' . $this->_region . self::BATTLE_NET_URL_API . $method . $query;
            }
            else
            {
                return 'http://' . $this->_region . self::BATTLE_NET_URL_API . $method . $query;
            }
        }
    }
    
    /**
     * Generate the Battle.net API authorization request header.
     *
     * @access private
     * @param string $path
     * @param string $verb
     * @return string
     */
    private function _generateAuthHeader($link, $verb = 'GET')
    {
        $head  = 'Authorization: BNET ';
        $head .= $this->_authCredentials['publicKey'];
        $head .= ':';
        
        $link = str_replace( 'http://' . $this->_region . '.battle.net', '', $link );
        $link = explode( '?', $link, -1 );
        $link = implode( '?', $link );
        
        $stringToSign  = $verb . '\n';
        $stringToSign .= date( 'r' ) . '\n';
        $stringToSign .= $link . '\n';
        
        $head .= base64_encode( hash_hmac( 'sha1', utf8_encode( $stringToSign ), utf8_encode( $this->_authCredentials['privateKey'] ) ) );
        
        return $head;
    }
    
    /**
     * Fetch data from the API.
     * 
     * @access protected
     * @param string $link
     * @param integer $cacheTime
     * @param boolean $ignoreAuth
     * @return string
     * @todo Error response handling
     */
    protected function _fetchData($link, $cacheTime = NULL, $ignoreAuth = FALSE)
    {
        $ch = curl_init();
        
        curl_setopt( $ch, CURLOPT_URL, $link );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 6 );
        curl_setopt( $ch, CURLOPT_ENCODING, '' );
        curl_setopt( $ch, CURLOPT_USERAGENT, sprintf( self::CLIENT_USER_AGENT, $this->_bnetDevs[ rand( 0, count( $this->_bnetDevs ) ) ] ) );
        
        if( isset( $cacheTime ) )
        {
            curl_setopt( $ch, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE );
            curl_setopt( $ch, CURLOPT_TIMEVALUE, $cacheTime );
        }
        
        if( $ignoreAuth == FALSE )
        {
            if( isset( $this->_authCredentials['publicKey'] ) && isset( $this->_authCredentials['privateKey'] ) )
            {
                curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $this->_generateAuthHeader( $link ) ) );
            }
            if( $this->_ssl == TRUE )
            {
                curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
            }
        }
        
        $data = curl_exec( $ch );
        $error = curl_errno( $ch );        
        curl_close( $ch );
        
        if( $error )
        {
            /**
             * @error_id 7
             * @severity Execution Halt -> No Return Data
             * @explanation There was an error retrieving data from the Battle.net API.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#7] An error ocurred while attempting to retrieve data from the Battle.net service.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        return $data;
    }
    
    /**
     * UTF8-decode all values in an array.
     * 
     * @author Ulminia @ Zangarmarsh-US <http://us.battle.net/wow/en/forum/topic/2786749085#8>
     * @access protected
     * @param array $input
     * @return array 
     */
    protected function _decodeArrayUtf8( $input )
    {
        $return = array();
        
        foreach( $input as $key => $val )
        {
            if( is_array( $val ) )
            {
                $return[$key] = $this->_decodeArrayUtf8( $val );
            }
            else
            {
                $return[$key] = utf8_decode( $val );
            }
        }
        
        return $return;
    }
    
    /**
     * Save a file, and create the directory if it does not exist.
     *
     * @author Trent Tompkins <http://www.php.net/manual/en/function.file-put-contents.php#84180>
     * @access protected
     * @param type $dir
     * @param type $contents
     * @return integer
     */
    protected function _filePutContentsAnyDir( $dir, $contents )
    {
        $parts = explode( '/', $dir );
        $file = array_pop( $parts );
        $dir = '';
        foreach( $parts as $part )
        {
            if( !is_dir( $dir .= '/' . $part ) )
            {
                mkdir( $dir );
            }
        }
        return file_put_contents( $dir . '/' . $file, $contents );
    }
    
    /**
     * Download all realms' status to cache.
     * 
     * @access protected
     * @param boolean $recache
     * @return void
     */
    protected function _getAllRealms($recache = FALSE)
    {
        $data = $this->_cacheFetch( 'realms', $this->_region );
        if( !$data || $data[0]['cache_time'] + $this->_cacheSettings['ttl'] < time() || $recache == TRUE )
        {
            if( $data )
            {
                $this->_cacheDrop( 'realms', $this->_region );
            }
            
            $realms = array();
            
            $returnArray = json_decode( $this->_fetchData( $this->_generateApiUrl( 'realm/status' ) ), TRUE );
            foreach( $returnArray['realms'] as $realm )
            {
                $realms[$realm['name']] = $realm;
                $realms[$realm['name']]['cache_time'] = time();
            }
            
            $this->_cacheStore( 'realms', $this->_region, $realms );
        }
    }
    
    /**
     * Set the Battle.net region.
     *
     * @access public
     * @param string $newRegion
     * @return boolean
     * @todo sea, cn (pending official support)
     */
    public function setRegion($newRegion)
    {
        if( !in_array( strtolower( $newRegion ), array( 'us', 'eu', 'kr', 'tw' ) ) )
        {
            return FALSE;
        }
        $this->_region = strtolower( $newRegion );
        
        $this->_getAllRealms();
        $this->getRealmList();
        
        return TRUE;
    }
    
    /**
     * Set and check API authentication credentials
     * 
     * @access public
     * @param string $publicKey
     * @param string $privateKey
     * @param boolean $useSsl
     * @return boolean
     */
    public function authenticate($publicKey, $privateKey, $useSsl = TRUE)
    {
        if( !is_bool( $useSsl ) )
        {
            /**
             * @error_id 16
             * @severity Fatal -> Security Exception
             * @explanation $ssl parameter must be boolean.
             */
            throw new Exception( '[#16] ' . __CLASS__ . ' authenticate() function $useSsl parameter should be boolean.', 1 );
            return FALSE;
        }
        
        $this->_authCredentials['public'] = $publicKey;
        $this->_authCredentials['private'] = $privateKey;
        
        $this->_ssl = $useSsl;
        
        return TRUE;
    }
    
    /**
     * Generate an array of all realm names for the current region.
     *
     * @access public
     * @param boolean $recache
     * @return array
     */
    public function getRealmList($recache = FALSE)
    {
        $realmList = $this->_cacheFetch( 'misc', 'realmlist_' . $this->_region );
        
        if( !$realmList || $recache == TRUE )
        {
            $realms = $this->_cacheFetch( 'realms', $this->_region );
            if( !$realms )
            {
                $this->_getAllRealms();
                $realms = $this->_cacheFetch( 'realms', $this->_region );
            }
            
            unset( $realmList );
            $realmList = array();
            $realmList['list'] = array();
            $realmList['cache_time'] = time();
            foreach( $realms as $realm )
            {
                $realmList['list'] = array_merge( $realmList['list'], array( $realm['name'] ) );
            }
            
            $this->_cacheStore( 'misc', 'realmlist_' . $this->_region, $realmList, $this->_cacheSettings['ttl'] * 20 );
        }
        
        return $realmList['list'];
    }
    
    /**
     * Fetch a realm's (or multiple realms') status.
     * 
     * @access public
     * @param mixed $realms
     * @param boolean $recache
     * @return array
     */
    public function getRealmStatus($realms = NULL, $recache = FALSE)
    {
        if( empty( $realms ) )
        {
            if( $recache == TRUE )
            {
                $this->_getAllRealms();
            }
            return $this->_cacheFetch( 'realms', $this->_region );
        }
        
        if( $recache == TRUE )
        {
            $returnArray = json_decode( $this->_fetchData( $this->_generateApiUrl( 'realm/status' . $realms ) ), TRUE );
            foreach( $returnArray['realms'] as $realm )
            {
                $this->cache->realms[$this->_region][$realm->name] = $realm;
                $this->cache->realms[$this->_region][$realm->name]['cache_time'] = time();
                $returnArray[$realm->name] = $realm;
            }
        }
        else
        {
            if( is_string( $realms ) )
            {
                $realmsRequested = @explode( ',', $realms );
                if( !empty( $realmsRequested ) )
                {
                    $realms = $realmsRequested;
                }
            }
            if( is_array( $realms ) )
            {
                foreach( $realms as $realm )
                {
                    $returnArray[$realm] = $this->cache->realms[$this->_region][$realm];
                }
            }
            else
            {
                $returnArray = $this->cache->realms[$this->_region];
            }
        }
        
        return $returnArray;
    }
    
    /**
     * Fetch information about a character.
     *
     * @access public
     * @param string $name
     * @param string $realm
     * @param mixed $fields
     * @param boolean $recache
     * @return array
     */
    public function getCharacterInfo($name, $realm, $fields = NULL, $recache=FALSE)
    {
        if( !in_array( ucwords( $realm ), $this->cache->realmList[$this->_region]['list'] ) )
        {
            /**
             * @error_id 4
             * @severity Warning -> Halting Method Execution
             * @explanation The $realm passed to the method does not exist (it is not in the realm status listing).
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#4] The provided realm does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        if( isset( $this->cache->characters[$this->_region][$realm][$name] ) )
        {
            if( ( $this->cache->characters[$this->_region][$realm][$name]['lastModified'] / 1000 ) + $this->_cacheSettings['ttl'] > time() && $recache == FALSE )
            {
                if( is_string( $fields ) )
                {
                    $fields = explode( ',', $fields );
                }
                
                if( is_array( $fields ) )
                {
                    foreach( $fields as $field )
                    {
                        if( !isset( $this->cache->characters[$this->_region][$realm][$name][$field] ) )
                        {
                            return $this->getCharacterInfo( $name, $realm, $fields, TRUE );
                        }
                    }
                }
                
                return $this->cache->characters[$this->_region][$realm][$name];
            }
        }
        
        if( isset( $this->cache->characters[$this->_region][$realm][$name]['lastModified'] ) )
        {
            $lastModTime = $this->cache->characters[$this->_region][$realm][$name]['lastModified'] / 1000;
            $returnData = $this->_fetchData( $this->_generateApiUrl( 'character', $realm, $name, $fields ), $lastModTime );
            if( strlen( $returnData ) > 0 )
            {
                $returnArray = $this->_decodeArrayUtf8( json_decode( $returnData, TRUE ) );
                $this->cache->characters[$this->_region][$realm][$name] = $returnArray;
            }
            else
            {
                $returnArray = $this->cache->characters[$this->_region][$realm][$name];
            }
        }
        else
        {
            $returnArray = $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'character', $realm, $name, $fields ) ), TRUE ) );
            $this->cache->characters[$this->_region][$realm][$name] = $returnArray;
        }
        
        return $returnArray;
    }
    
    /**
     * Fetch information about a guild.
     *
     * @access public
     * @param string $name
     * @param string $realm
     * @param mixed $fields
     * @param boolean $recache
     * @return array
     */
    public function getGuildInfo($name, $realm, $fields = NULL, $recache = FALSE)
    {
        if( !in_array( ucwords( $realm ), $this->cache->realmList[$this->_region]['list'] ) )
        {
            /**
             * @error_id 5
             * @severity Warning -> Halting Method Execution
             * @explanation The $realm passed to the method does not exist (it is not in the realm status listing).
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#5] The provided realm does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        if( isset( $this->cache->guilds[$this->_region][$realm][$name] ) )
        {
            if( ( $this->cache->guilds[$this->_region][$realm][$name]['lastModified'] / 1000 ) + $this->_cacheSettings['ttl'] > time() && $recache == FALSE )
            {
                if( is_string( $fields ) )
                {
                    $fields = explode( ',', $fields );
                }
                
                if( is_array( $fields ) )
                {
                    foreach( $fields as $field )
                    {
                        if( !isset( $this->cache->guilds[$this->_region][$realm][$name][$field] ) )
                        {
                            return $this->getGuildInfo( $name, $realm, $fields, TRUE );
                        }
                    }
                }
                
                return $this->cache->guilds[$this->_region][$realm][$name];
            }
        }
        
        if( isset( $this->cache->guilds[$this->_region][$realm][$name]['lastModified'] ) )
        {
            $lastModTime = $this->cache->guilds[$this->_region][$realm][$name]['lastModified'] / 1000;
            $returnData = $this->_fetchData( $this->_generateApiUrl( 'guild', $realm, $name, $fields ), $lastModTime );
            if( strlen( $returnData ) > 0 )
            {
                $returnArray = $this->_decodeArrayUtf8( json_decode( $returnData, TRUE ) );
                $this->cache->guilds[$this->_region][$realm][$name] = $returnArray;
            }
            else
            {
                $returnArray = $this->cache->guilds[$this->_region][$realm][$name];
            }
        }
        else
        {
            $returnArray = $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'guild', $realm, $name, $fields ) ), TRUE ) );
            $this->cache->guilds[$this->_region][$realm][$name] = $returnArray;
        }
        
        return $returnArray;
    }
    
    /**
     * Fetch information about an arena team
     *
     * @access public
     * @param mixed $size
     * @param string $name
     * @param string $realm
     * @param mixed $fields
     * @param boolean $recache
     * @return array
     */
    public function getArenaTeamInfo($size, $name, $realm, $fields = NULL, $recache = FALSE)
    {
        if( !in_array( ucwords( $realm ), $this->cache->realmList[$this->_region]['list'] ) )
        {
            /**
             * @error_id 6
             * @severity Warning -> Halting Method Execution
             * @explanation The $realm passed to the method does not exist (it is not in the realm status listing).
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#6] The provided realm does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        if( is_integer( $size ) && in_array( $size, array( 2, 3, 5 ) ) )
        {
            $size = $size . 'v' . $size;
        }
        
        if( !in_array( $size, array( '2v2', '3v3', '5v5' ) ) )
        {
            /**
             * @error_id 9
             * @severity Warning -> Halting Method Execution
             * @explanation Size should be one of the following: 2, 3, 5, 2v2, 3v3, 5v5
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#9] The provided team size is not possible.', E_USER_NOTICE );
            }
        }
        
        if( isset( $this->cache->arenaTeams[$this->_region][$realm][$size][$name] ) )
        {
            if( ( $this->cache->arenaTeams[$this->_region][$realm][$size][$name]['lastModified'] / 1000 ) + $this->_cacheSettings['ttl'] > time() && $recache == FALSE )
            {
                if( is_string( $fields ) )
                {
                    $fields = explode( ',', $fields );
                }
                
                if( is_array( $fields ) )
                {
                    foreach( $fields as $field )
                    {
                        if( !isset( $this->cache->arenaTeams[$this->_region][$realm][$size][$name][$field] ) )
                        {
                            return $this->getArenaTeamInfo( $size, $name, $realm, $fields, TRUE );
                        }
                    }
                }
                
                return $this->cache->arenaTeams[$this->_region][$realm][$size][$name];
            }
        }
        
        if( isset( $this->cache->arenaTeams[$this->_region][$realm][$size][$name]['lastModified'] ) )
        {
            $lastModTime = $this->cache->arenaTeams[$this->_region][$realm][$size][$name]['lastModified'] / 1000;
            $returnData = $this->_fetchData( $this->_generateApiUrl( 'arena', $realm, $size . '/' . $name, $query), $lastModTime );
            if( strlen( $returnData ) > 0 )
            {
                $returnArray = $this->_decodeArrayUtf8( json_decode( $returnData, TRUE ) );
                $this->cache->arenaTeams[$this->_region][$realm][$size][$name] = $returnArray;
            }
            else
            {
                $returnArray = $this->cache->arenaTeams[$this->_region][$realm][$size][$name];
            }
        }
        else
        {
            $returnArray = $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'arena', $realm, $size . '/' . $name, $query ) ), TRUE ) );
            $this->cache->arenaTeams[$this->_region][$realm][$size][$name] = $returnArray;
        }
        
        return $returnArray;
    }
    
    /**
     * Fetch a battlegroup's arena ladder.
     *
     * @access public
     * @param string $battlegroup
     * @param mixed $size
     * @return array
     * @todo Caching
     */
    public function getArenaLadder($battlegroup, $size)
    {
        if( is_integer( $size ) && in_array( $size, array( 2, 3, 5 ) ) )
        {
            $size = $size . 'v' . $size;
        }
        
        if( !in_array( $size, array( '2v2', '3v3', '5v5' ) ) )
        {
            /**
             * @error_id 34
             * @severity Warning -> Halting Method Execution
             * @explanation Size should be one of the following: 2, 3, 5, 2v2, 3v3, 5v5
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#34] The provided team size is not possible.', E_USER_NOTICE );
            }
        }
        
        return $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'pvp/arena/', $battlegroup . '/' . $size ) ), TRUE ) );
    }
    
    /**
     * Fetch a realm's auction data.
     * 
     * @cache_ttl Divided by 10
     * @access public
     * @param string $realm
     * @return array
     */
    public function getAuctionData($realm)
    {
        if( !in_array( ucwords( $realm ), $this->cache->realmList[$this->_region]['list'] ) )
        {
            /**
             * @error_id 31
             * @severity Warning -> Halting Method Execution
             * @explanation The $realm passed to the method does not exist (it is not in the realm status listing).
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#31] The provided realm does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        if( isset( $this->cache->auctionData[$this->_region][$realm] ) )
        {
            if( ( $this->cache->auctionData[$this->_region][$realm]['lastModified'] / 1000 ) + ( $this->_cacheSettings['ttl'] / 10 ) > time() && $recache == FALSE )
            {
                return $this->cache->auctionData[$this->_region][$realm];
            }
        }
        
        if( isset( $this->cache->auctionData[$this->_region][$realm]['lastModified'] ) )
        {
            $lastModTime = $this->cache->auctionData[$this->_region][$realm]['lastModified'] / 1000;
            $aucFiles = $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'auction/data', $realm ) ), TRUE ) );
            $returnData =  $this->_fetchData( $aucFiles['files']['url'] );
            if( strlen( $returnData ) > 0 )
            {
                $returnArray = $this->_decodeArrayUtf8( json_decode( $returnData, TRUE ) );
                $this->cache->auctionData[$this->_region][$realm] = $returnArray;
            }
            else
            {
                $returnArray = $this->cache->auctionData[$this->_region][$realm];
            }
        }
        else
        {
            $aucFiles = $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'auction/data', $realm ) ), TRUE ) );
            $returnArray =  $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $aucFiles['files']['url'] ), TRUE ) );
            $this->cache->auctionData[$this->_region][$realm] = $returnArray;
        }
        
        return $returnArray;
    }
    
    /**
     * Set where to store images for getCharacterImage.
     *
     * @access public
     * @param string $directory
     * @param string $link
     * @return array
     */
    public function setImagingDirs($directory, $link, $ttl = NULL)
    {
        if( !is_string( $directory ) )
        {
            /**
             * @error_id 12
             * @severity Warning -> Halting Method Execution
             * @explanation $directory should be string.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#12] $directory must be set as string when calling setImagingDirs.', E_USER_NOTICE );
            }
            return FALSE;
        }
        if( !is_string( $link ) )
        {
            /**
             * @error_id 13
             * @severity Warning -> Halting Method Execution
             * @explanation $link should be string.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#13] $link must be set as string when calling setImagingDirs.', E_USER_NOTICE );
            }
            return FALSE;
        }
        if( $ttl == NULL )
        {
            $ttl = $this->_cacheSettings['ttl'] * 10;
        }
        if( !is_int( $ttl ) )
        {
            /**
             * @error_id 20
             * @severity Warning -> Halting Method Execution
             * @explanation $ttl should be integer.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#20] $ttl should be set as integer or null when calling setImagingDirs.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        $this->_imagingSettings = array( 'directory' => rtrim( $directory, '/' ),
                                                    'link' => rtrim( $link, '/' ),
                                                     'ttl' => $ttl );
        
        return $this->_imagingSettings;
    }
    
    /**
     * Get the link to a character image.  The link will either be to a cached image on your server if $blizzLink is false, or to the image on Blizzard's server if $blizzLink is true.
     *
     * @access public
     * @param string $type
     * @param string $name
     * @param string $realm
     * @param boolean $allowDefault
     * @param boolean $blizzLink
     * @return string
     */
    public function getCharacterImage($type, $name, $realm, $allowDefault = TRUE, $blizzLink = FALSE)
    {
        if( $blizzLink == FALSE && !isset( $this->_imagingSettings ) )
        {
            /**
             * @error_id 11
             * @severity Warning -> Halting Method Execution
             * @explanation Must call setImagingDirs before getCharacterImage when $blizzLink is false.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#11] No imaging directory set while $blizzLink is false.', E_USER_NOTICE );
            }
            return FALSE;
        }
        if( !in_array( $type, array( 'avatar', 'card', 'inset', 'profilemain' ) ) )
        {
            /**
             * @error_id 10
             * @severity Warning -> Halting Method Execution
             * @explanation The provided character image type is incorrect.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#10] The provided image type does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        $characterData = $this->getCharacterInfo( $name, $realm );
        if( !$characterData )
        {
            return FALSE;
        }
        
        switch( $type )
        {
            case 'avatar':
                $imageLocation = 'http://' . $this->_region . '.battle.net/static-render/' . $this->_region . '/' . $characterData['thumbnail'];
                break;
            case 'card':
                $imageLocation = 'http://' . $this->_region . '.battle.net/static-render/' . $this->_region . '/' . str_replace( 'avatar', 'card', $characterData['thumbnail'] );
                break;
            case 'inset':
                $imageLocation = 'http://' . $this->_region . '.battle.net/static-render/' . $this->_region . '/' . str_replace( 'avatar', 'inset', $characterData['thumbnail'] );
                break;
            case 'profilemain':
                $imageLocation = 'http://' . $this->_region . '.battle.net/static-render/' . $this->_region . '/' . str_replace( 'avatar', 'profilemain', $characterData['thumbnail'] );
                break;
        }
        
        $image = $this->_fetchData( $imageLocation );
        if( !$image && $allowDefault )
        {
            switch( $type )
            {
                case 'avatar':
                    $imageLocation = 'http://' . $this->_region . '.battle.net/wow/static/images/2d/avatar/' . $characterData['race'] . '-' . $characterData['gender'] . '.jpg';
                    break;
                case 'card':
                    $imageLocation = 'http://' . $this->_region . '.battle.net/wow/static/images/2d/card/' . $characterData['race'] . '-' . $characterData['gender'] . '.jpg';
                    break;
                case 'inset':
                    $imageLocation = 'http://' . $this->_region . '.battle.net/wow/static/images/2d/inset/' . $characterData['race'] . '-' . $characterData['gender'] . '.jpg';
                    break;
                case 'profilemain':
                    $imageLocation = 'http://' . $this->_region . '.battle.net/wow/static/images/2d/profilemain/race/' . $characterData['race'] . '-' . $characterData['gender'] . '.jpg';
                    break;
            }
        }
        
        if( $blizzLink )
        {
            return $imageLocation;
        }
        else
        {
            $diskLocation = $this->_imagingSettings['directory'] . '/character/' . $type . '/' . $realm . '/' . $name . '.jpg';
            if( !file_exists( $diskLocation ) || filemtime( $diskLocation ) < time() + $this->_imagingSettings['ttl'] )
            {
                if( file_exists( $diskLocation ) )
                {
                    $returnData = $this->_fetchData( $imageLocation, filemtime( $diskLocation ), TRUE );
                    if( strlen( $returnData ) > 0 )
                    {
                        $this->_filePutContentsAnyDir( $diskLocation, $returnData );
                    }
                }
                else
                {
                    $this->_filePutContentsAnyDir( $diskLocation, $this->_fetchData( $imageLocation, NULL, TRUE ) );
                }
            }
            
            return $this->_imagingSettings['link'] . '/character/' . $type . '/' . $realm . '/' . $name . '.jpg';
        }
    }
    
    /**
     * Get the link to a game icon.  The link will either be to a cached image on your server if $blizzLink is false, or to the image on Blizzard's server if $blizzLink is true.
     *
     * @access public
     * @param string $textureName
     * @param integer $size
     * @param boolean $blizzLink
     * @return string
     */
    public function getGameIcon($textureName, $size = 36, $blizzLink = FALSE)
    {
        if( $blizzLink == FALSE && !isset( $this->_imagingSettings ) )
        {
            /**
             * @error_id 21
             * @severity Warning -> Halting Method Execution
             * @explanation Must call setImagingDirs before getGameIcon when $blizzLink is false.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#21] No imaging directory set while $blizzLink is false.', E_USER_NOTICE );
            }
            return FALSE;
        }
        if( !in_array( $size, array( 18, 36, 56 ) ) )
        {
            /**
             * @error_id 17
             * @severity Warning -> Halting Method Execution
             * @explanation The provided icon image size is incorrect.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#17] The provided icon size does not exist.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        $imageLocation = 'http://' . $this->_region . '.media.blizzard.com/wow/icons/' . $size . '/' . $textureName . '.jpg';
        
        if( $blizzLink )
        {
            return $imageLocation;
        }
        else
        {
            $diskLocation = $this->_imagingSettings['directory'] . '/icon/' . $size . '/' . $textureName . '.jpg';
            if( !file_exists( $diskLocation ) )
            {
                $this->_filePutContentsAnyDir( $diskLocation, $this->_fetchData( $imageLocation, NULL, TRUE ) );
            }
            
            return $this->_imagingSettings['link'] . '/icon/' . $size . '/' . $textureName . '.jpg';
        }
    }
    
    /**
     * Returns the armory link for a character or guild.
     * 
     * @access public
     * @param string $type
     * @param string $name
     * @param string $realm
     * @return string
     * @todo Arena team support
     */
    public function getArmoryLink($type, $name, $realm)
    {
        return 'http://' . $this->_region . '.battle.net/wow/en/' . $type . '/' . $realm . '/' . $name . '/';
    }
    
    /**
     * Fetch an API data resource array.
     *
     * @access public
     * @final Used in function idToString
     * @param string $name
     * @return array
     * @todo Code expansion, caching
     */
    public function getDataResource($name)
    {
        return $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'data/' . $name ) ), TRUE ) );
    }
    
    /**
     * Fetch information about an item by ID.
     *
     * @access public
     * @param integer $id
     * @return array
     * @todo Caching
     */
    public function getItemInfo($id)
    {
        if( !is_integer( $id) )
        {
            /**
             * @error_id 32
             * @severity Warning -> Halting Method Execution
             * @explanation $id for getItemInfo should be an integer.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#32] $id for getItemInfo should be integer.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        return $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'item/' . $id ) ), TRUE ) );
    }
    
    /**
     * Fetch information about a quest by ID.
     *
     * @access public
     * @param integer $id
     * @return array
     * @todo Caching
     */
    public function getQuestInfo($id)
    {
        if( !is_integer( $id) )
        {
            /**
             * @error_id 33
             * @severity Warning -> Halting Method Execution
             * @explanation $id for getQuestInfo should be an integer.
             */
            if( $this->errorReporting )
            {
                trigger_error( '[#33] $id for getQuestInfo should be integer.', E_USER_NOTICE );
            }
            return FALSE;
        }
        
        return $this->_decodeArrayUtf8( json_decode( $this->_fetchData( $this->_generateApiUrl( 'quest/' . $id ) ), TRUE ) );
    }
    
    /**
     * Convert an ID to a string (or array of strings).
     *
     * @contributor Ujournal @ Chromaggus-US <http://us.battle.net/wow/en/forum/topic/2791479173#2> (Reforge ID's)
     * @access public
     * @param string $type
     * @param integer $id
     * @return mixed
     * @todo More $types.
     */
    public function idToString($type, $id)
    {
        switch( $type )
        {
            case 'race':
                $data = $this->getDataResource( 'character/races' );
                foreach( $data['races'] as $race )
                {
                    if( $race['id'] == $id )
                    {
                        return $race['name'];
                    }
                }
                break;
            case 'class':
                $data = $this->getDataResource( 'character/classes' );
                foreach( $data['classes'] as $class )
                {
                    if( $class['id'] == $id )
                    {
                        return $class['name'];
                    }
                }
                break;
            case 'reforgeFrom':
                $data = $this->idToString('reforge', $id);
                return $data['from'];
                break;
            case 'reforgeTo':
                $data = $this->idToString('reforge', $id);
                return $data['to'];
                break;
            case 'reforge':
                $data = array(  113 => array( 'from' => 'Spirit', 'to' => 'Dodge Rating' ),
                                114 => array( 'from' => 'Spirit', 'to' => 'Parry Rating' ),
                                115 => array( 'from' => 'Spirit', 'to' => 'Hit Rating' ),
                                116 => array( 'from' => 'Spirit', 'to' => 'Crit Rating' ),
                                117 => array( 'from' => 'Spirit', 'to' => 'Haste Rating' ),
                                118 => array( 'from' => 'Spirit', 'to' => 'Expertise Rating' ),
                                119 => array( 'from' => 'Spirit', 'to' => 'Mastery' ),
                                120 => array( 'from' => 'Dodge Rating', 'to' => 'Spirit' ),
                                121 => array( 'from' => 'Dodge Rating', 'to' => 'Parry Rating' ),
                                122 => array( 'from' => 'Dodge Rating', 'to' => 'Hit Rating' ),
                                123 => array( 'from' => 'Dodge Rating', 'to' => 'Crit Rating' ),
                                124 => array( 'from' => 'Dodge Rating', 'to' => 'Haste Rating' ),
                                125 => array( 'from' => 'Dodge Rating', 'to' => 'Expertise Rating' ),
                                126 => array( 'from' => 'Dodge Rating', 'to' => 'Mastery' ),
                                127 => array( 'from' => 'Parry Rating', 'to' => 'Spirit' ),
                                128 => array( 'from' => 'Parry Rating', 'to' => 'Dodge Rating' ),
                                129 => array( 'from' => 'Parry Rating', 'to' => 'Hit Rating' ),
                                130 => array( 'from' => 'Parry Rating', 'to' => 'Crit Rating' ),
                                131 => array( 'from' => 'Parry Rating', 'to' => 'Haste Rating' ),
                                132 => array( 'from' => 'Parry Rating', 'to' => 'Expertise Rating' ),
                                133 => array( 'from' => 'Parry Rating', 'to' => 'Mastery' ),
                                134 => array( 'from' => 'Hit Rating', 'to' => 'Spirit' ),
                                135 => array( 'from' => 'Hit Rating', 'to' => 'Dodge Rating' ),
                                136 => array( 'from' => 'Hit Rating', 'to' => 'Parry Rating' ),
                                137 => array( 'from' => 'Hit Rating', 'to' => 'Crit Rating' ),
                                138 => array( 'from' => 'Hit Rating', 'to' => 'Haste Rating' ),
                                139 => array( 'from' => 'Hit Rating', 'to' => 'Expertise Rating' ),
                                140 => array( 'from' => 'Hit Rating', 'to' => 'Mastery' ),
                                141 => array( 'from' => 'Crit Rating', 'to' => 'Spirit' ),
                                142 => array( 'from' => 'Crit Rating', 'to' => 'Dodge Rating' ),
                                143 => array( 'from' => 'Crit Rating', 'to' => 'Parry Rating' ),
                                144 => array( 'from' => 'Crit Rating', 'to' => 'Hit Rating' ),
                                145 => array( 'from' => 'Crit Rating', 'to' => 'Haste Rating' ),
                                146 => array( 'from' => 'Crit Rating', 'to' => 'Expertise Rating' ),
                                147 => array( 'from' => 'Crit Rating', 'to' => 'Mastery' ),
                                148 => array( 'from' => 'Haste Rating', 'to' => 'Spirit' ),
                                149 => array( 'from' => 'Haste Rating', 'to' => 'Dodge Rating' ),
                                150 => array( 'from' => 'Haste Rating', 'to' => 'Parry Rating' ),
                                151 => array( 'from' => 'Haste Rating', 'to' => 'Hit Rating' ),
                                152 => array( 'from' => 'Haste Rating', 'to' => 'Crit Rating' ),
                                153 => array( 'from' => 'Haste Rating', 'to' => 'Expertise Rating' ),
                                154 => array( 'from' => 'Haste Rating', 'to' => 'Mastery' ),
                                155 => array( 'from' => 'Expertise Rating', 'to' => 'Spirit' ),
                                156 => array( 'from' => 'Expertise Rating', 'to' => 'Dodge Rating' ),
                                157 => array( 'from' => 'Expertise Rating', 'to' => 'Parry Rating' ),
                                158 => array( 'from' => 'Expertise Rating', 'to' => 'Hit Rating' ),
                                159 => array( 'from' => 'Expertise Rating', 'to' => 'Crit Rating' ),
                                160 => array( 'from' => 'Expertise Rating', 'to' => 'Haste Rating' ),
                                161 => array( 'from' => 'Expertise Rating', 'to' => 'Mastery' ),
                                162 => array( 'from' => 'Mastery', 'to' => 'Spirit' ),
                                163 => array( 'from' => 'Mastery', 'to' => 'Dodge Rating' ),
                                164 => array( 'from' => 'Mastery', 'to' => 'Parry Rating' ),
                                165 => array( 'from' => 'Mastery', 'to' => 'Hit Rating' ),
                                166 => array( 'from' => 'Mastery', 'to' => 'Crit Rating' ),
                                167 => array( 'from' => 'Mastery', 'to' => 'Haste Rating' ),
                                168 => array( 'from' => 'Mastery', 'to' => 'Expertise Rating' )
                             );
                return $data[$id];
                break;
            default:
                return FALSE;
                break;
        }
    }
    
    /**
     * Get a realm's datacenter, timezone, IP, etc.
     *
     * @access public
     * @param string $realm
     * @return array
     * @todo Add all realms
     */
    public function getRealmInfo($realm)
    {
        $data = array( 'us' => array( 'ExampleRealm' => array( 'datacenter' => 'Chicago', 'timezone' => 'Eastern', 'ip' => '1.1.1.1' ) ) );
        return $data[$this->_region][$realm];
    }
}