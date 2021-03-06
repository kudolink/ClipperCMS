<?php
require_once('core.class.inc.php');

define('DP_PUB_UNPUBLISHED', 0);
define('DP_PUB_PUBLISHED', 1);
define('DP_PUB_ALL', 0xFFFF);

/**
 * ClipperCMS Document Parser
 *
 * This class contains the main document parsing functions.
 *
 */
class DocumentParser extends Core {
    var $event, $Event; // event object
    var $pluginEvent;
    var $config= null;
    var $rs;
    var $result;
    var $sql;
    var $table_prefix;
    var $debug;
    var $documentIdentifier;
    var $documentMethod;
    var $documentGenerated;
    var $documentContent;
    var $tstart;
    var $minParserPasses;
    var $maxParserPasses;
    var $documentObject;
    var $templateObject;
    var $snippetObjects;
    var $stopOnNotice;
    var $currentSnippet;
    var $documentName;
    var $aliases;
    var $visitor;
    var $entrypage;
    var $documentListing;
    var $dumpSnippets;
    var $chunkCache;
    var $snippetCache;
    var $contentTypes;
    var $virtualDir;
    var $placeholders;
    var $sjscripts;
    var $jscripts;
    var $jquery_scripts;
    var $loadedjscripts;
    var $documentMap;
    var $forwards= 3;

	/**
	 * @var is this an RSS feed request?
	 */
	var $is_rss = false;

    /**
     * @var array Map forked snippet names to names of earlier compatible snippets.
     * Note that keys are all lowercase.
     *
     * @todo Construct an API and/or config system for this. Currently only applies to core/bundled snippets.
     */
    var $snippetMap = array('ditto'=>'List', 'webloginpe'=>'WebUsers');
    
    /**
     * @var hold type of code being eval'd
     */
    private $eval_type = null;
    
    /**
     * @var hold name of code being eval'd
     */
    private $eval_name = null;

	/**
	 * @var stack for nested eval'd elements using registerEvalInfo()
	 */
	private $eval_stack = array();

    /**
     * Document constructor
     *
     * @return DocumentParser
     */
    function __construct() {
        $this->loadExtension('DBAPI') or die('Could not load DBAPI class.'); // load DBAPI class
        $this->dbConfig= & $this->db->config; // alias for backward compatibility
        $this->jscripts= array ();
        $this->sjscripts= array ();
        $this->jquery_scripts = array();
        $this->loadedjscripts= array ();
        // events
        $this->event= new SystemEvent();
        $this->Event= & $this->event; //alias for backward compatibility
        $this->pluginEvent= array ();
        // set track_errors ini variable
        // @ini_set("track_errors", "1"); // enable error tracking in $php_errormsg
    }

    /**
     * Loads an extension from the extenders folder.
     * Currently of limited use - can only load the DBAPI and ManagerAPI.
     *
     * @param string $extnamegetAllChildren
     * @return boolean
     */
    function loadExtension($extname) {
        global $database_type;

        switch ($extname) {
            // Database API
            case 'DBAPI' :
                if (!include_once MODX_BASE_PATH . 'manager/includes/extenders/dbapi.' . $database_type . '.class.inc.php')
                    return false;
                $this->db= new DBAPI($this);
                return true;
                break;

                // Manager API
            case 'ManagerAPI' :
                if (!include_once MODX_BASE_PATH . 'manager/includes/extenders/manager.api.class.inc.php')
                    return false;
                $this->manager= new ManagerAPI;
                return true;
                break;

            default :
                return false;
        }
    }

    /**
     * Redirect
     *
     * @param string $url
     * @param int $count_attempts
     * @param type $type
     * @param type $responseCode
     * @return boolean
     */
    function sendRedirect($url, $count_attempts= 0, $type= '', $responseCode= '') {
    
        global $base_url, $site_url;
    
        if (empty ($url)) {
            return false;
        } else {
            if ($count_attempts == 1) {
                // append the redirect count string to the url
                $currentNumberOfRedirects= isset ($_REQUEST['err']) ? $_REQUEST['err'] : 0;
                if ($currentNumberOfRedirects > 3) {
                    $this->messageQuit('Redirection attempt failed - please ensure the document you\'re trying to redirect to exists. <p>Redirection URL: <i>' . $url . '</i></p>');
                } else {
                    $currentNumberOfRedirects += 1;
                    if (strpos($url, "?") > 0) {
                        $url .= "&err=$currentNumberOfRedirects";
                    } else {
                        $url .= "?err=$currentNumberOfRedirects";
                    }
                }
            }
            if ($type == 'REDIRECT_REFRESH') {
                $header= 'Refresh: 0;URL=' . $url;
            }
            elseif ($type == 'REDIRECT_META') {
                $header= '<META HTTP-EQUIV="Refresh" CONTENT="0; URL=' . $url . '" />';
                echo $header;
                exit;
            }
            elseif ($type == 'REDIRECT_HEADER' || empty ($type)) {
                // check if url has /$base_url
                if (substr($url, 0, strlen($base_url)) == $base_url) {
                    // append $site_url to make it work with Location:
                    $url= $site_url . substr($url, strlen($base_url));
                }
                if (strpos($url, "\n") === false) {
                    $header= 'Location: ' . $url;
                } else {
                    $this->messageQuit('No newline allowed in redirect url.');
                }
            }
            if ($responseCode && (strpos($responseCode, '30') !== false)) {
                header($responseCode);
            }
            header($header);
            exit();
        }
    }

    /**
     * Forward to another page
     *
     * @param int $id
     * @param string $responseCode
     */
    function sendForward($id, $responseCode= '') {
        if ($this->forwards > 0) {
            $this->forwards= $this->forwards - 1;
            $this->documentIdentifier= $id;
            $this->documentMethod= 'id';
            $this->documentObject= $this->getDocumentObject('id', $id);
            if ($responseCode) {
                header($responseCode);
            }
            $this->prepareResponse();
            exit();
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            die('<h1>ERROR: Too many forward attempts!</h1><p>The request could not be completed due to too many unsuccessful forward attempts.</p>');
        }
    }

    /**
     * Redirect to the error page, by calling sendForward(). This is called for example when the page was not found.
     */
    function sendErrorPage() {
        // invoke OnPageNotFound event
        $this->invokeEvent('OnPageNotFound');
//        $this->sendRedirect($this->makeUrl($this->config['error_page'], '', '&refurl=' . urlencode($_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'])), 1);
        $this->sendForward($this->config['error_page'] ? $this->config['error_page'] : $this->config['site_start'], 'HTTP/1.0 404 Not Found');
        exit();
    }

    /**
     * Redirect to the unauthorized page, for example on calling a page without having the permissions to see this page.
     */
    function sendUnauthorizedPage() {
        // invoke OnPageUnauthorized event
        $_REQUEST['refurl'] = $this->documentIdentifier;
        $this->invokeEvent('OnPageUnauthorized');
        if ($this->config['unauthorized_page']) {
            $unauthorizedPage= $this->config['unauthorized_page'];
        } elseif ($this->config['error_page']) {
            $unauthorizedPage= $this->config['error_page'];
        } else {
            $unauthorizedPage= $this->config['site_start'];
        }
        $this->sendForward($unauthorizedPage, 'HTTP/1.1 403 Forbidden');	// Changed by TimGS 22/6/2012. Originally was a 401 but this HTTP code appears intended for situations
        									// where the client can authenticate via HTTP authentication and send a www-authenticate header.
        exit();
    }

    /**
     * Connect to the database
     *
     * @deprecated use $modx->db->connect()
     */
    function dbConnect() {
        $this->db->connect();
        $this->rs= $this->db->conn; // for compatibility
    }

    /**
     * Query the database
     *
     * @deprecated use $modx->db->query()
     * @param string $sql The SQL statement to execute
     * @return resource|bool
     */
    function dbQuery($sql) {
        return $this->db->query($sql);
    }

    /**
     * Count the number of rows in a record set
     *
     * @deprecated use $modx->db->getRecordCount($rs)
     * @param resource
     * @return int
     */
    function recordCount($rs) {
        return $this->db->getRecordCount($rs);
    }

    /**
     * Get a result row
     * 
     * @deprecated use $modx->db->getRow()
     * @param array $rs
     * @param string $mode
     * @return array
     */
    function fetchRow($rs, $mode= 'assoc') {
        return $this->db->getRow($rs, $mode);
    }

    /**
     * Get the number of rows affected in the last db operation
     * 
     * @deprecated use $modx->db->getAffectedRows()
     * @param array $rs
     * @return int
     */
    function affectedRows($rs) {
        return $this->db->getAffectedRows($rs);
    }

    /**
     * Get the ID generated in the last query
     * 
     * @deprecated use $modx->db->getInsertId()
     * @param array $rs
     * @return int
     */
    function insertId($rs) {
        return $this->db->getInsertId($rs);
    }

    /**
     * Close a database connection
     *
     * @deprecated use $modx->db->disconnect()
     */
    function dbClose() {
        $this->db->disconnect();
    }

    /**
     * Get MODx settings including, but not limited to, the system_settings table
     */
    function getSettings() {
        if (!is_array($this->config) || empty ($this->config)) {
            if ($included= file_exists(MODX_BASE_PATH . 'assets/cache/siteCache.idx.php')) {
                $included= include_once (MODX_BASE_PATH . 'assets/cache/siteCache.idx.php');
            }
            if (!$included || !is_array($this->config) || empty ($this->config)) {
                include_once MODX_BASE_PATH . "/manager/processors/cache_sync.class.processor.php";
                $cache = new synccache();
                $cache->setCachepath(MODX_BASE_PATH . "/assets/cache/");
                $cache->setReport(false);
                $rebuilt = $cache->buildCache($this);
                $included = false;
                if($rebuilt && $included= file_exists(MODX_BASE_PATH . 'assets/cache/siteCache.idx.php')) {
                    $included= include MODX_BASE_PATH . 'assets/cache/siteCache.idx.php';
                }
                if(!$included) {
                    $result= $this->db->query('SELECT setting_name, setting_value FROM ' . $this->getFullTableName('system_settings'));
                    while ($row= $this->db->getRow($result, 'both')) {
                        $this->config[$row[0]]= $row[1];
                    }
                }
            }

            // added for backwards compatibility - garry FS#104
            $this->config['etomite_charset'] = & $this->config['modx_charset'];

            // store base_url and base_path inside config array
            $this->config['base_url']= MODX_BASE_URL;
            $this->config['base_path']= MODX_BASE_PATH;
            $this->config['site_url']= MODX_SITE_URL;
            
            $this->getUserSettings();
        }
    }

    /**
     * Load user settings if user is logged in
     */
    function getUserSettings() {
        $usrSettings= array ();
        if ($id= $this->getLoginUserID()) {
            $usrType= $this->getLoginUserType();
            if (isset ($usrType) && $usrType == 'manager')
                $usrType= 'mgr';

            if ($usrType == 'mgr' && $this->isBackend()) {
                // invoke the OnBeforeManagerPageInit event, only if in backend
                $this->invokeEvent("OnBeforeManagerPageInit");
            }

            if (isset ($_SESSION[$usrType . 'UsrConfigSet'])) {
                $usrSettings= & $_SESSION[$usrType . 'UsrConfigSet'];
            } else {
                if ($usrType == 'web')
                    $query= $this->getFullTableName('web_user_settings') . ' WHERE webuser=\'' . $id . '\'';
                else
                    $query= $this->getFullTableName('user_settings') . ' WHERE user=\'' . $id . '\'';
                $result= $this->db->query('SELECT setting_name, setting_value FROM ' . $query);
                while ($row= $this->db->getRow($result, 'both'))
                    $usrSettings[$row[0]]= $row[1];
                if (isset ($usrType))
                    $_SESSION[$usrType . 'UsrConfigSet']= $usrSettings; // store user settings in session
            }
        }
        if ($this->isFrontend() && $mgrid= $this->getLoginUserID('mgr')) {
            $musrSettings= array ();
            if (isset ($_SESSION['mgrUsrConfigSet'])) {
                $musrSettings= & $_SESSION['mgrUsrConfigSet'];
            } else {
                $query= $this->getFullTableName('user_settings') . ' WHERE user=\'' . $mgrid . '\'';
                if ($result= $this->db->query('SELECT setting_name, setting_value FROM ' . $query)) {
                    while ($row= $this->db->getRow($result, 'both')) {
                        $usrSettings[$row[0]]= $row[1];
                    }
                    $_SESSION['mgrUsrConfigSet']= $musrSettings; // store user settings in session
                }
            }
            if (!empty ($musrSettings)) {
                $usrSettings= array_merge($musrSettings, $usrSettings);
            }
        }
        $this->config= array_merge($this->config, $usrSettings);
    }

    /**
     * Convert a UTF-8 language array to the required character set
     *
     * @param array &$cla_conversion_lang Array to convert
     * @param string $fallback filename of fallback file
     * @param string $fallback_var name of array in fallback file
     * @return bool Success/Fail
     */
    function convertLanguageArray(&$cla_conversion_lang, $fallback, $fallback_var) {

	    global $modx_manager_charset;
	    
	    $charset = ($this->isFrontend() || !@$modx_manager_charset) ? $this->config['modx_charset'] : $modx_manager_charset;
	    
	    $errors = false;
	    
	    if ($charset && $charset != 'UTF-8') {
		    foreach($cla_conversion_lang as $__k => $__v) {
		        $tmp = iconv('UTF-8', $charset.'//TRANSLIT', $cla_conversion_lang[$__k]);
		        if ($cla_conversion_lang[$__k] == iconv($charset, 'UTF-8//TRANSLIT', $tmp)) {
		            // No errors - conversion possible from UTF-8 to selected encoding
		            $cla_conversion_lang[$__k] = $tmp;
		        } else {
		            // Errors - language file cannot be converted to selected encoding.
		            // Fallback to English as it can be shown in most character encodings, and thus minimises the risk of an unusable manager.
		            if (!$errors) {
		            	require($fallback);
		            	$errors = true;
		           	}
		            $cla_conversion_lang[$__k] = iconv('UTF-8', $charset.'//TRANSLIT', $$fallback_var[$__k]);
		            // If the conversion from English is also not possible (maybe the target charset is unsupported in libiconv) do no conversion.
		            if (!$cla_conversion_lang[$__k]) {
		                $cla_conversion_lang[$__k] = ${$fallback_var}[$__k];
		            }
		        }
		    }
	    }
	    return $errors;
    }

    /**
     * Merge a UTF-8 language array into the global $_lang
     *
     * @param string $filename
     * @param string $new_lang_array Name of array to merge
     * @param string $fallback filename of fallback file
     * @param string $fallback_var name of array in fallback file
     * @return bool Success/Fail
     */
    function mergeLanguageArray($filename, $new_lang_array, $fallback, $fallback_var) {
        if (include($filename)) {
            $errors = $this->convertLanguageArray($$new_lang_array, $fallback, $fallback_var);
            $GLOBALS['_lang'] = array_merge($GLOBALS['_lang'], $$new_lang_array);
        }
    
    return $errors;
    }

    /**
     * Get the method by which the current document/resource was requested
     *
     * @return string 'alias' (friendly url alias), 'rss' (friendly url alias with rss/ at the start of $_REQUEST['q']) or 'id' (may or may not be an RSS request).
     */
    function getDocumentMethod() {
        // function to test the query and find the retrieval method
        if (isset ($_REQUEST['q'])) {
            return preg_match('/^\/?rss\//', $_REQUEST['q']) ? 'rss' : 'alias';
        }
        elseif (isset ($_REQUEST['id'])) {
            return "id";
        } else {
            return "none";
        }
    }

    /**
     * Returns the document identifier of the current request
     *
     * @param string $method id and alias are allowed
     * @return int
     */
    function getDocumentIdentifier($method) {
        // function to test the query and find the retrieval method
        $docIdentifier= $this->config['site_start'];
        switch ($method) {
        	case 'rss':
        		if (!is_string($_REQUEST['q'])) { // If an array is passed (TimGS)
            		$this->sendErrorPage();
            	}
            	$q = preg_replace('/^\/?rss\//', '', $_REQUEST['q']);
            	if ($q) {
            	    $docIdentifier = $this->db->escape($q);
            	} else {
            		$docIdentifier = $this->config['site_start'];
                }
                break;
            case 'alias' :
            	if (!is_string($_REQUEST['q'])) { // If an array is passed (TimGS)
            		$this->sendErrorPage();
            	}
                $docIdentifier= $this->db->escape($_REQUEST['q']);
                break;
            case 'id' :
                if (!is_numeric($_REQUEST['id'])) {
                    $this->sendErrorPage();
                } else {
                    $docIdentifier= intval($_REQUEST['id']);
                }
                break;
        }
        return $docIdentifier;
    }

    /**
     * Check for manager login session
     *
     * @return boolean
     */
    function checkSession() {
        if (isset ($_SESSION['mgrValidated'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks, if a the result is a preview
     *
     * @return boolean
     */
    function checkPreview() {
        if ($this->checkSession() == true) {
            if (isset ($_REQUEST['z']) && $_REQUEST['z'] == 'manprev') {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * check if site is offline
     *
     * @return boolean
     */
    function checkSiteStatus() {
        $siteStatus= $this->config['site_status'];
        if ($siteStatus == 1) {
            // site online
            return true;
        }
        elseif ($siteStatus == 0 && $this->checkSession()) {
            // site offline but launched via the manager
            return true;
        } else {
            // site is offline
            return false;
        }
    }

    /**
     * Create a 'clean' document identifier with path information, friendly URL suffix and prefix.
     *
     * @param string $qOrig
     * @return string
     */
    function cleanDocumentIdentifier($qOrig) {
        (!empty($qOrig)) or $qOrig = $this->config['site_start'];
        $q= $qOrig;
        /* First remove any / before or after */
        if ($q[strlen($q) - 1] == '/')
            $q= substr($q, 0, -1);
        if ($q[0] == '/')
            $q= substr($q, 1);
        /* Save path if any */
        /* FS#476 and FS#308: only return virtualDir if friendly paths are enabled */
        if ($this->config['use_alias_path'] == 1) {
            $this->virtualDir= dirname($q);
            $this->virtualDir= ($this->virtualDir == '.' ? '' : $this->virtualDir);
            $q= basename($q);
        } else {
            $this->virtualDir= '';
        }
        $q= str_replace($this->config['friendly_url_prefix'], "", $q);
        $q= str_replace($this->config['friendly_url_suffix'], "", $q);
        if (is_numeric($q) && !$this->documentListing[$q]) { /* we got an ID returned, check to make sure it's not an alias */
            /* FS#476 and FS#308: check that id is valid in terms of virtualDir structure */
            if ($this->config['use_alias_path'] == 1) {
                if ((($this->virtualDir != '' && !$this->documentListing[$this->virtualDir . '/' . $q]) || ($this->virtualDir == '' && !$this->documentListing[$q])) && (($this->virtualDir != '' && in_array($q, $this->getChildIds($this->documentListing[$this->virtualDir], 1))) || ($this->virtualDir == '' && in_array($q, $this->getChildIds(0, 1))))) {
                    $this->documentMethod= 'id';
                    return $q;
                } else { /* not a valid id in terms of virtualDir, treat as alias */
                    $this->documentMethod= 'alias';
                    return $q;
                }
            } else {
                $this->documentMethod= 'id';
                return $q;
            }
        } else { /* we didn't get an ID back, so instead we assume it's an alias */
            if ($this->config['friendly_alias_urls'] != 1) {
                $q= $qOrig;
            }
            $this->documentMethod= 'alias';
            return $q;
        }
    }

    /**
     * Check the cache for a specific document/resource
     *
     * @param int $id
     * @return string
     */
    function checkCache($id) {
        $cacheFile = $this->pageCacheFile($id);
        if (file_exists($cacheFile)) {
            $this->documentGenerated= 0;
            $flContent = file_get_contents($cacheFile, false);
            $flContent= substr($flContent, 37); // remove php header
            $a= explode("<!--__MODxCacheSpliter__-->", $flContent, 2);
            if (count($a) == 1)
                return $a[0]; // return only document content
            else {
                $docObj= unserialize($a[0]); // rebuild document object
                // check page security
                if ($docObj['privateweb'] && isset ($docObj['__MODxDocGroups__'])) {
                    $pass= false;
                    $usrGrps= $this->getUserDocGroups();
                    $docGrps= explode(",", $docObj['__MODxDocGroups__']);
                    // check is user has access to doc groups
                    if (is_array($usrGrps)) {
                        foreach ($usrGrps as $k => $v)
                            if (in_array($v, $docGrps)) {
                                $pass= true;
                                break;
                            }
                    }
                    // diplay error pages if user has no access to cached doc
                    if (!$pass) {
                        if ($this->config['unauthorized_page']) {
                            // check if file is not public
                            $tbldg= $this->getFullTableName("document_groups");
                            $secrs= $this->db->query("SELECT id FROM $tbldg WHERE document = '" . $id . "' LIMIT 1;");
                            if ($secrs)
                                $seclimit= $this->db->getRecordCount($secrs);
                        }
                        if ($seclimit > 0) {
                            // match found but not publicly accessible, send the visitor to the unauthorized_page
                            $this->sendUnauthorizedPage();
                            exit; // stop here
                        } else {
                            // no match found, send the visitor to the error_page
                            $this->sendErrorPage();
                            exit; // stop here
                        }
                    }
                }
				// Grab the Scripts
				if (isset($docObj['__MODxSJScripts__'])) $this->sjscripts = $docObj['__MODxSJScripts__'];
				if (isset($docObj['__MODxJScripts__']))  $this->jscripts = $docObj['__MODxJScripts__'];
				if (isset($docObj['__MODxJQUERYScripts__']))  $this->jquery_scripts = $docObj['__MODxJQUERYScripts__'];

				// Remove intermediate variables
                unset($docObj['__MODxDocGroups__'], $docObj['__MODxSJScripts__'], $docObj['__MODxJScripts__'], $docObj['__MODxJQUERYScripts__']);

                $this->documentObject= $docObj;
                return $a[1]; // return document content
            }
        } else {
            $this->documentGenerated= 1;
            return "";
        }
    }

    /**
     * Final processing and output of the document/resource.
     * 
     * - runs uncached snippets
     * - add javascript to <head>
     * - removes unused placeholders
     * - converts URL tags [~...~] to URLs
     *
     * @param boolean $noEvent Default: false
     */
    function outputContent($noEvent= false) {

        $this->documentOutput= $this->documentContent;

        if ($this->documentGenerated == 1 && $this->documentObject['cacheable'] == 1 && $this->documentObject['type'] == 'document' && $this->documentObject['published'] == 1) {
    		if (!empty($this->sjscripts)) $this->documentObject['__MODxSJScripts__'] = $this->sjscripts;
    		if (!empty($this->jscripts)) $this->documentObject['__MODxJScripts__'] = $this->jscripts;
    		if (!empty($this->jquery_scripts)) $this->documentObject['__MODxJQUERYScripts__'] = $this->jquery_scripts;
        }

        // check for non-cached snippet output
        if (strpos($this->documentOutput, '[!') !== false) {
            $this->documentOutput= $this->parseDocumentSource($this->documentOutput, true);
    	}

    	// Moved from prepareResponse() by sirlancelot
    	// Insert Startup jscripts & CSS scripts into template - template must have a <head> tag
    	if ($js= $this->getRegisteredClientStartupScripts()) {
    		// change to just before closing </head>
    		// $this->documentContent = preg_replace("/(<head[^>]*>)/i", "\\1\n".$js, $this->documentContent);
    		$this->documentOutput= preg_replace("/(<\/head>)/i", $js . "\n\\1", $this->documentOutput);
    	}

    	// Insert jscripts & html block into template - template must have a </body> tag
    	if ($js= $this->getRegisteredClientScripts()) {
    		$this->documentOutput= preg_replace("/(<\/body>)/i", $js . "\n\\1", $this->documentOutput);
    	}
    	// End fix by sirlancelot

        // remove all unused placeholders
        if (strpos($this->documentOutput, '[+') > -1) {
            $matches= array ();
            preg_match_all('~\[\+(.*?)\+\]~', $this->documentOutput, $matches);
            if ($matches[0])
                $this->documentOutput= str_replace($matches[0], '', $this->documentOutput);
        }

        $this->documentOutput= $this->rewriteUrls($this->documentOutput);
        
        // In RSS feeds change relative URLs to absolute URLs.
        if ($this->is_rss) {
            $this->documentOutput = preg_replace('/href="(?!http)/', 'href="'.$this->config['site_url'], $this->documentOutput);
        }

        // send out content-type and content-disposition headers
        if (IN_PARSER_MODE == "true") {
            if ($this->is_rss) {
                header('Content-Type: application/rss+xml; charset='.$this->config['modx_charset']);
            } else {
                $type= !empty ($this->contentTypes[$this->documentIdentifier]) ? $this->contentTypes[$this->documentIdentifier] : "text/html";
                header('Content-Type: ' . $type . '; charset=' . $this->config['modx_charset']);
            }

            if (!$this->checkPreview() && $this->documentObject['content_dispo'] == 1) {
                if ($this->documentObject['alias'])
                    $name= $this->documentObject['alias'];
                else {
                    // strip title of special characters
                    $name= $this->documentObject['pagetitle'];
                    $name= strip_tags($name);
                    $name= strtolower($name);
                    $name= preg_replace('/&.+?;/', '', $name); // kill entities
                    $name= preg_replace('/[^\.%a-z0-9 _-]/', '', $name);
                    $name= preg_replace('/\s+/', '-', $name);
                    $name= preg_replace('|-+|', '-', $name);
                    $name= trim($name, '-');
                }
                $header= 'Content-Disposition: attachment; filename=' . $name;
                header($header);
            }
        }

        $totalTime= ($this->getMicroTime() - $this->tstart);
        $queryTime= $this->queryTime;
        $phpTime= $totalTime - $queryTime;

        $queryTime= sprintf("%2.4f s", $queryTime);
        $totalTime= sprintf("%2.4f s", $totalTime);
        $phpTime= sprintf("%2.4f s", $phpTime);
        $source= $this->documentGenerated == 1 ? "database" : "cache";
        $queries= isset ($this->executedQueries) ? $this->executedQueries : 0;

        $out =& $this->documentOutput;
        if ($this->dumpSQL) {
            $out .= $this->queryCode;
        }
        $out= str_replace("[^q^]", $queries, $out);
        $out= str_replace("[^qt^]", $queryTime, $out);
        $out= str_replace("[^p^]", $phpTime, $out);
        $out= str_replace("[^t^]", $totalTime, $out);
        $out= str_replace("[^s^]", $source, $out);
        //$this->documentOutput= $out;

        // invoke OnWebPagePrerender event
        if (!$noEvent) {
            $this->invokeEvent("OnWebPagePrerender");
        }

        echo $this->documentOutput;
        ob_end_flush();
    }

    /**
     * Checks the publish state of page
     */
    function checkPublishStatus() {
        $cacheRefreshTime= 0;
        @include $this->config["base_path"] . "assets/cache/sitePublishing.idx.php";
        $timeNow= time() + $this->config['server_offset_time'];
        if ($cacheRefreshTime <= $timeNow && $cacheRefreshTime != 0) {
            // now, check for documents that need publishing
            $sql = "UPDATE ".$this->getFullTableName("site_content")." SET published=1, publishedon=".time()." WHERE ".$this->getFullTableName("site_content").".pub_date <= $timeNow AND ".$this->getFullTableName("site_content").".pub_date!=0 AND published=0";
            if (@ !$result= $this->db->query($sql)) {
                $this->messageQuit("Execution of a query to the database failed", $sql);
            }

            // now, check for documents that need un-publishing
            $sql= "UPDATE " . $this->getFullTableName("site_content") . " SET published=0, publishedon=0 WHERE " . $this->getFullTableName("site_content") . ".unpub_date <= $timeNow AND " . $this->getFullTableName("site_content") . ".unpub_date!=0 AND published=1";
            if (@ !$result= $this->db->query($sql)) {
                $this->messageQuit("Execution of a query to the database failed", $sql);
            }

            // clear the cache
            $basepath= $this->config["base_path"] . "assets/cache/";
            if ($handle= opendir($basepath)) {
                $filesincache= 0;
                $deletedfilesincache= 0;
                while (false !== ($file= readdir($handle))) {
                    if ($file != "." && $file != "..") {
                        $filesincache += 1;
                        if ($this->isPageCacheFile($file)) {
                            $deletedfilesincache += 1;
                            while (!unlink($basepath . "/" . $file));
                        }
                    }
                }
                closedir($handle);
            }

            // update publish time file
            $timesArr= array ();
            $sql= "SELECT MIN(pub_date) AS minpub FROM " . $this->getFullTableName("site_content") . " WHERE pub_date>$timeNow";
            if (@ !$result= $this->db->query($sql)) {
                $this->messageQuit("Failed to find publishing timestamps", $sql);
            }
            $tmpRow= $this->db->getRow($result);
            $minpub= $tmpRow['minpub'];
            if ($minpub != NULL) {
                $timesArr[]= $minpub;
            }

            $sql= "SELECT MIN(unpub_date) AS minunpub FROM " . $this->getFullTableName("site_content") . " WHERE unpub_date>$timeNow";
            if (@ !$result= $this->db->query($sql)) {
                $this->messageQuit("Failed to find publishing timestamps", $sql);
            }
            $tmpRow= $this->db->getRow($result);
            $minunpub= $tmpRow['minunpub'];
            if ($minunpub != NULL) {
                $timesArr[]= $minunpub;
            }

            if (count($timesArr) > 0) {
                $nextevent= min($timesArr);
            } else {
                $nextevent= 0;
            }

            $basepath= $this->config["base_path"] . "assets/cache";
            $fp= @ fopen($basepath . "/sitePublishing.idx.php", "wb");
            if ($fp) {
                @ flock($fp, LOCK_EX);
                @ fwrite($fp, "<?php \$cacheRefreshTime=$nextevent; ?>");
                @ flock($fp, LOCK_UN);
                @ fclose($fp);
            }
        }
    }

    /** 
     * Check for and log fatal errors
     *
     * @return void
     */
     function fatalErrorCheck() {
         // Log fatal errors
        $error = error_get_last();
        if ($error['type'] == E_ERROR || $error['type'] == E_USER_ERROR || $error['type'] == E_PARSE) {
        
            $file = $error['file'];
            if (strpos($file, '/document.parser.class.inc.php') !== false) {
                $file = 'DocumentParser'.(strpos($file, 'eval()\'d code') === false ? '' : ' eval\'d code').($this->eval_type ? " in {$this->eval_type} {$this->eval_name}" : '');
            }
    
            if ($this->eval_type) {
                $this->messageQuitFromElement(ucfirst($this->eval_type)." {$this->eval_name}", 'Fatal '.($error['type'] == 'E_USER_ERROR' ? '(user) ' : '')."error: {$error['message']}", '', true, $error['type'], $file, '', $error['message'], $error['line']);
            } else {
                $this->messageQuit('Fatal '.($error['type'] == 'E_USER_ERROR' ? '(user) ' : '')."error: {$error['message']}", '', true, $error['type'], $file, '', $error['message'], $error['line']);
            }
        }
    }

    /**
     * Final jobs.
     *
     * - cache page
     */
    function postProcess() {
        // if the current document was generated, cache it!
        if ($this->documentGenerated == 1 && $this->documentObject['cacheable'] == 1 && $this->documentObject['type'] == 'document' && $this->documentObject['published'] == 1) {
            // invoke OnBeforeSaveWebPageCache event
            $this->invokeEvent("OnBeforeSaveWebPageCache");
            if ($fp = @ fopen($this->pageCacheFile($this->documentIdentifier), 'w')) {
                // get and store document groups inside document object. Document groups will be used to check security on cache pages
                $sql= "SELECT document_group FROM " . $this->getFullTableName("document_groups") . " WHERE document='" . $this->documentIdentifier . "'";
                $docGroups= $this->db->getColumn("document_group", $sql);

				// Attach Document Groups and Scripts
				if (is_array($docGroups)) $this->documentObject['__MODxDocGroups__'] = implode(",", $docGroups);

                $docObjSerial= serialize($this->documentObject);
                $cacheContent= $docObjSerial . "<!--__MODxCacheSpliter__-->" . $this->documentContent;
                fputs($fp, "<?php die('Unauthorized access.'); ?>$cacheContent");
                fclose($fp);
            }
        }

        // Useful for example to external page counters/stats packages
        $this->invokeEvent('OnWebPageComplete');

        // end post processing
    }

    /**
     * Merge meta tags
     *
     * @param string $template
     * @return string
     * @deprecated
     */
    function mergeDocumentMETATags($template) {
        //content removed as this function is deprecated
        return $template;
    }

    /**
     * Merge content fields and TVs
     *
     * @param string $template
     * @return string
     */
    function mergeDocumentContent($template) {
    
        static $documentObjects = array(); // Could be improved by use of the modx cache. TODO below

        $replace= array ();
        preg_match_all('~\[\*(.*?)\*\]~', $template, $matches);
        $variableCount= count($matches[1]);
        $basepath= $this->config["base_path"] . "manager/includes";
        for ($i= 0; $i < $variableCount; $i++) {
            $key= $matches[1][$i];
            $key= substr($key, 0, 1) == '#' ? substr($key, 1) : $key; // remove # for QuickEdit format

            // Detect output modifiers
            // Put result into $key and $modifiers
            extract($this->getModifiers($key));
            
            if (($sep_pos = strpos($key, '@')) !== false) {
                // Handle [*<fieldname/TVname>@<docid>*]
                // Identify the docid first.
                // <docid> can be any id, 'parent', 'ultimateparent', or contain site settings placeholders e.g. [(site_start)]
                $other_docid = null;
                if (ctype_digit($other_docid = substr($key, $sep_pos + 1))) {
                    $other_docid = (int)$other_docid;
                } else {
                    switch ($other_docid) {
                        case 'parent':
                            $other_docid = $this->documentObject['parent'];
                            break;
                        case 'ultimateparent':
                            $other_docid = $this->getUltimateParentId($this->documentIdentifier);
                            break;
                        default:
                        	if (array_key_exists($other_docid, $this->config)) {
                        		$other_docid = $this->config[$other_docid];
                        	}
                            if (ctype_digit($other_docid)) {
                                $other_docid = (int)$other_docid;
                            } else {
                                $other_docid = null;
                            }
                            break;
                    }
                }

                if ($other_docid) {
                    if ($other_docid != $this->documentIdentifier) {
                        // Another docid is found, is valid, is not zero and is not the current document.
                        // TODO: cache handling. May need to modify checkCache()
                        if (!isset($documentObjects[$other_docid])) {
                            $documentObjects[$other_docid] = $this->getDocumentObject('id', $other_docid);
                        }
                        $value = $documentObjects[$other_docid][substr($key, 0, $sep_pos)];
                    } else {
                        // Using the current document.
                        $value= $this->documentObject[substr($key, 0, $sep_pos)];
                    }
                } else {
                    // Invalid $other_docid - don't change the content of the placeholder as it may be used by an Extra
                    $value = $matches[1][$i];
                }
            } else {
                // Using the current document.
                $value= $this->documentObject[$key];
            }

            if (is_array($value)) {
                include_once $basepath . "/tmplvars.format.inc.php";
                include_once $basepath . "/tmplvars.commands.inc.php";
                $w= "100%";
                $h= "300";
                $value= getTVDisplayFormat($value[0], $value[1], $value[2], $value[3], $value[4]);
            }

            // Process output modifiers
            if (is_array($modifiers)) {
                foreach($modifiers as $modifier) {
                    $value = $this->modifyOutput($value, $modifier);
                }
            }

            $replace[$i] = $value;
        }
        $template= str_replace($matches[0], $replace, $template);

        return $template;
    }

   /**
    * Get output modifiers
    *
    * @param string $key placeholder name plus modifiers separated by ';'
    * @return array Associative array of two elements - 'key' is the key without any modifiers, 'modifiers' is an array of modifiers.
    */
   function getModifiers($key) {
        if (strpos($key, ';') != false) {
            $modifiers = explode(';', $key);
            $key = $modifiers[0];
            $modifiers = array_slice($modifiers, 1);
        } else {
            $modifiers = null;
        }
        return array('key'=>$key, 'modifiers'=>$modifiers);
    }

   /** 
     * Modifies output
     *
     * @param string $string 
     * @param string $modifier in the form 'modifier' or 'modifier(argument)'
     * @return string
     */
    function modifyOutput($string, $modifier) {

        if (($pos = strpos($modifier, '(')) !== false) {
            $arg = substr(substr($modifier, $pos + 1), 0, -1);
            $modifier = substr($modifier, 0, $pos);
        } else {
            $arg = null;
        }

        switch ($modifier) {
            case 'strtolower':
            case 'strtoupper':
            case 'ucwords':
            case 'ucfirst':
            case 'strip_tags':
            case 'urlencode':
            case 'rawurlencode':
                $string = $modifier($string);
                break;
                
            case 'html':
                $string = htmlentities($string, ENT_QUOTES, $this->config['modx_charset']);
                break;
            
            case 'limit':
                if (ctype_digit($arg)) {
                    $string = substr($string, 0, $arg);
                }
                break;

            case 'ellipsis':
                if (ctype_digit($arg) && strlen($string) > $arg) {
                    $string = rtrim(substr(ltrim($string), 0, $arg)).'&hellip;';
                }
                break;
            
            case 'date':
                // Check for timestamp, MySQL style datetime or legacy style date
                if (is_numeric($string)) {
                    $timestamp = (int)$string;
                } elseif(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})\ ([0-9]{2}):([0-9]{2}):([0-9]{2})$/', $string, $matches)) {
                    $timestamp = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
                } elseif(preg_match('/^([0-9]{2})-([0-9]{2})-([0-9]{4})\ ([0-9]{2}):([0-9]{2}):([0-9]{2})$/', $string, $matches)) {
                    $timestamp = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[1], $matches[3]);
                } else {
                    // Fallback - attempt to use strtotime(). This may work with dates that have had the date formatter widget applied.
                    $timestamp = strtotime(str_replace(',', ' ', $string));
                }
                if ($timestamp) {
                    if (!$arg) {
                        $arg = 'r';
                        $datefn = 'date';
                    } else {
                        $datefn = (strpos($arg, '%') === false) ? 'date' : 'strftime';
                    }
                    $string = $datefn($arg, $timestamp);
                } else {
                    return '';
                }
                break;

        }

    return $string;
    }

    /**
     * Merge system settings
     *
     * @param string $template
     * @return string
     */
    function mergeSettingsContent($template) {
        $replace= array ();
        $matches= array ();
        if (preg_match_all('~\[\(([a-z\_]*?)\)\]~', $template, $matches)) {
            $settingsCount= count($matches[1]);
            for ($i= 0; $i < $settingsCount; $i++) {
                if (array_key_exists($matches[1][$i], $this->config))
                    $replace[$i]= $this->config[$matches[1][$i]];
            }

            $template= str_replace($matches[0], $replace, $template);
        }
        return $template;
    }

    /**
     * Merge chunks
     *
     * @param string $content
     * @return string
     */
    function mergeChunkContent($content) {
        $replace= array ();
        $matches= array ();
        if (preg_match_all('~{{(.*?)}}~', $content, $matches)) {
            $settingsCount= count($matches[1]);
            for ($i= 0; $i < $settingsCount; $i++) {
                if (isset ($this->chunkCache[$matches[1][$i]])) {
                    $replace[$i]= $this->chunkCache[$matches[1][$i]];
                } else {
                    $sql= "SELECT `snippet` FROM " . $this->getFullTableName("site_htmlsnippets") . " WHERE " . $this->getFullTableName("site_htmlsnippets") . ".`name`='" . $this->db->escape($matches[1][$i]) . "';";
                    $result= $this->db->query($sql);
                    $limit= $this->db->getRecordCount($result);
                    if ($limit < 1) {
                        $this->chunkCache[$matches[1][$i]]= "";
                        $replace[$i]= "";
                    } else {
                        $row= $this->db->getRow($result);
                        $this->chunkCache[$matches[1][$i]]= $row['snippet'];
                        $replace[$i]= $row['snippet'];
                    }
                }
            }
            $content= str_replace($matches[0], $replace, $content);
        }
        return $content;
    }

    /**
     * Merge placeholder values
     *
     * @param string $content
     * @return string
     */
    function mergePlaceholderContent($content) {
        $replace= array ();
        $matches= array ();
        if (preg_match_all('~\[\+(.*?)\+\]~', $content, $matches)) {
            $cnt= count($matches[1]);
            for ($i= 0; $i < $cnt; $i++) {
                $v= '';
                $key= $matches[1][$i];

                // Detect output modifiers
                // Put result into $key and $modifiers
                extract($this->getModifiers($key));
            
                if (is_array($this->placeholders) && array_key_exists($key, $this->placeholders))
                    $v= $this->placeholders[$key];
                    // Process output modifiers
                    if (is_array($modifiers)) {
                        foreach($modifiers as $modifier) {
                            $v = $this->modifyOutput($v, $modifier);
                        }
                    }
                if ($v === '')
                    unset ($matches[0][$i]); // here we'll leave empty placeholders for last.
                else
                    $replace[$i]= $v;
            }
            $content= str_replace($matches[0], $replace, $content);
        }
        return $content;
    }
    
    /**
     * Prepare text output for HTML
     *
     * @param string $str
     * @param bool $all_entities If false, just run htmlspecialchars(). If true run htmlentities() (which converts all characters with entities). Default false.
     * @return string
     */
    function html($str, $all_entities = false, $charset = null) {
        if (is_null($charset)) $charset = $this->config['modx_charset'];
        return $all_entities ? htmlentities($str, ENT_QUOTES, $charset) : htmlspecialchars($str, ENT_QUOTES, $charset);
    }

    /**
     * Set eval type and name
     * Used by the fatal error handler.
     * After the eval'd code is run, call unregisterEvalInfo().
     *
     * @param string $type
     * @param string $name
     * @return void
     */
    function registerEvalInfo($type, $name) {
    	$this->eval_stack[] = array($this->eval_type, $this->eval_name);
    	$this->eval_type = $type;
    	$this->eval_name = $name;
    }
    
    /**
     * Unset eval type and name
     *
     * @return void
     */
    function unregisterEvalInfo() {
    	list($this->eval_type, $this->eval_name) = array_pop($this->eval_stack);
    }

    /**
     * Run a plugin
     *
     * @param string $pluginCode Code to run
     * @param array $params
     */
    function evalPlugin($___plugin_code, $___params) {
    	global $modx; // For eval'd code
        if ($___plugin_code) {
            $this->event->params= &$___params; // store params inside event object
            if (is_array($___params)) {
                extract($___params, EXTR_SKIP);
            }
            $this->registerEvalInfo('plugin', $this->event->activePlugin);
            ob_start();
            $___plug = eval ($___plugin_code);
            $___msg = ob_get_contents();
            ob_end_clean();
            if ($___plug === false) {
                $this->messageQuitFromElement("Plugin {$this->event->activePlugin}", "PHP Parse error in plugin {$this->event->activePlugin}");
            }
            $this->unregisterEvalInfo();
            echo $___msg;
            unset ($this->event->params);
        } else {
            $this->logEvent(0, 3, "Plugin {$___name} missing or empty");
        }
    }

    /**
     * Run a snippet
     *
     * @param string $snippet Code to run
     * @param array $params
     * @param string $name Snippet name. Optional but advised.
     * @return string
     */
    function evalSnippet($___snippet_code, $___params, $___name = null) {
    	global $modx; // For eval'd code
        if ($___snippet_code) {
            $this->event->params= & $___params; // store params inside event object
            if (is_array($___params)) {
                extract($___params, EXTR_SKIP);
            }
            $this->registerEvalInfo('snippet', $___name);
            ob_start();
            $___snip = eval ($___snippet_code);
            $___msg = ob_get_contents();
            ob_end_clean();
            if ($___snip === false) {
                $this->messageQuitFromElement("Snippet {$___name}", "PHP Parse error in snippet {$___name}");
            }
            $this->unregisterEvalInfo();
            unset ($this->event->params);
            return $___msg . $___snip;
        } else {
            $this->logEvent(0, 3, "Snippet {$___name} missing or empty");
        }
    }

    /**
     * Run snippets as per the tags in $documentSource and replace the tags with the returned values.
     *
     * @param string $documentSource
     * @return string
     */
    function evalSnippets($documentSource) {
        // preg_match_all('~\[\[(.*?)\]\]~ms', $documentSource, $matches);
	preg_match_all('~\[\[((.(?!\[[[!]))*?)\]\]~ms', $documentSource, $matches); // Nested snippets now possible (TimGS)

        $etomite= & $this;

	$snippets = array();

        if ($matchCount= count($matches[1])) {
            for ($i= 0; $i < $matchCount; $i++) {
                $spos= strpos($matches[1][$i], '?', 0);
                if ($spos !== false) {
                    $params= substr($matches[1][$i], $spos);
                    $matches[1][$i]= substr($matches[1][$i], 0, $spos);
                } else {
                    $params= '';
                }

                if (isset($this->snippetMap[strtolower($matches[1][$i])])) {
                     $snippets[$i]['oldname'] = $matches[1][$i]; // Store old name as it appears in the source
                     $matches[1][$i] = $this->snippetMap[strtolower($matches[1][$i])]; // Map old name to new
                }

                $snippetParams[$i]= $params;
            }
            $nrSnippetsToGet= $matchCount;
            for ($i= 0; $i < $nrSnippetsToGet; $i++) { // Raymond: Mod for Snippet props
                if (isset ($this->snippetCache[$matches[1][$i]])) {
                    $snippets[$i]['name']= $matches[1][$i];
                    $snippets[$i]['snippet']= $this->snippetCache[$matches[1][$i]];
                    if (array_key_exists($matches[1][$i] . "Props", $this->snippetCache))
                        $snippets[$i]['properties']= $this->snippetCache[$matches[1][$i] . "Props"];
                } else {
                    // get from db and store a copy inside cache
                    $sql= "SELECT `name`, `snippet`, `properties` FROM " . $this->getFullTableName("site_snippets") . " WHERE " . $this->getFullTableName("site_snippets") . ".`name`='" . $this->db->escape($matches[1][$i]) . "';";
                    $result= $this->db->query($sql);
                    $added = false;
                    if ($this->db->getRecordCount($result) == 1) {
                        $row= $this->db->getRow($result);
                        if($row['name'] == $matches[1][$i]) {
                            $snippets[$i]['name']= $row['name'];
                            $snippets[$i]['snippet']= $this->snippetCache[$row['name']]= $row['snippet'];
                            $snippets[$i]['properties']= $this->snippetCache[$row['name'] . "Props"]= $row['properties'];
                            $added = true;
                        }
                    }
                    if(!$added) {
                        $snippets[$i]['name']= $matches[1][$i];
                        $snippets[$i]['snippet']= $this->snippetCache[$matches[1][$i]]= null;
                        $snippets[$i]['properties']= '';
                    }
                }
            }

            for ($i= 0; $i < $nrSnippetsToGet; $i++) {
                $parameter= array ();
                $snippetName= $this->currentSnippet= $snippets[$i]['name'];
                // FIXME Undefined index: properties
                if (array_key_exists('properties', $snippets[$i])) {
                    $snippetProperties= $snippets[$i]['properties'];
                } else {
                    $snippetProperties= '';
                }
                
                // load default params/properties - Raymond
                $parameter= $this->parseProperties($snippetProperties);
                
                // This snippet's parameters.
                // NOTE 1: &amp; and & situation non-ideal, but needed to avoid breaking sites that use snippet calls in richtext fields!
                // NOTE 2: For backwards compatability the first parameter name need not be prefixed with '&', but this behaviour is deprecated.
                $params_to_process = str_replace('&amp;', '&', trim(substr($snippetParams[$i], 1)));
                if ($params_to_process[0] != '&') $params_to_process = '&'.$params_to_process;
                preg_match_all('/(^|[`\s])&([^=]+)\=`([^`]*)`/', $params_to_process, $tempSnippetParams, PREG_SET_ORDER);
                foreach ($tempSnippetParams as $tempSnippetParam) {
                    $parameter[$tempSnippetParam[2]] = $tempSnippetParam[3];
                }

                $executedSnippets[$i]= $this->evalSnippet($snippets[$i]['snippet'], $parameter, $snippets[$i]['name']);
                if ($this->dumpSnippets == 1) {
                    echo "<fieldset><legend><b>$snippetName</b></legend><textarea style='width:60%; height:200px'>" . htmlentities($executedSnippets[$i]) . "</textarea></fieldset><br />";
                }

                // Replace snippet call with snippet return value
                $documentSource= str_replace('[[' . $snippetName . $snippetParams[$i] . ']]', $executedSnippets[$i], $documentSource);
                
                if (isset($snippets[$i]['oldname'])) {
                    // And again for old mapped snippets
                    $documentSource= str_replace('[[' . $snippets[$i]['oldname'] . $snippetParams[$i] . ']]', $executedSnippets[$i], $documentSource);
                    }

            }
        }
        return $documentSource;
    }

    /**
     * Create a friendly URL
     *
     * @param string $pre
     * @param string $suff
     * @param string $alias
     * @return string
     */
    function makeFriendlyURL($pre, $suff, $alias) {
        $Alias = explode('/',$alias);
        $alias = array_pop($Alias);
        $dir = implode('/', $Alias);
        unset($Alias);
        return ($dir != '' ? "$dir/" : '') . $pre . $alias . $suff;
    }

    /** 
     * Convert URL tags [~...~] to URLs
     *
     * Simplified code compared to previous version. Uses makeURL(). Can cope with extraneous spaces.
     *
     * @param string $documentSource
     * @return string
     */
    function rewriteUrls($documentSource) {
  	    return preg_replace('!\[\~\s*([0-9]+)\s*\~\]!ise', "substr(\$this->makeURL('\\1'), strlen(\$this->config['base_url']))", $documentSource);
    }

    /**
     * Get all db fields and TVs for a document/resource
     *
     * @param type $method
     * @param type $identifier
     * @return array
     */
    function getDocumentObject($method, $identifier) {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");

        // allow alias to be full path
        if($method == 'alias') {
            $identifier = $this->cleanDocumentIdentifier($identifier);
            $method = $this->documentMethod;
        }
        if($method == 'alias' && $this->config['use_alias_path'] && array_key_exists($identifier, $this->documentListing)) {
            $method = 'id';
            $identifier = $this->documentListing[$identifier];
        }
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // get document
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql= "SELECT sc.*
              FROM $tblsc sc
              LEFT JOIN $tbldg dg ON dg.document = sc.id
              WHERE sc." . $method . " = '" . $identifier . "'
              AND ($access) LIMIT 1;";
        $result= $this->db->query($sql);
        $rowCount= $this->db->getRecordCount($result);
        if ($rowCount < 1) {
            if ($this->config['unauthorized_page']) {
                // method may still be alias, while identifier is not full path alias, e.g. id not found above
                if ($method === 'alias') {
                    $q = "SELECT dg.id FROM $tbldg dg, $tblsc sc WHERE dg.document = sc.id AND sc.alias = '{$identifier}' LIMIT 1;";
                } else {
                    $q = "SELECT id FROM $tbldg WHERE document = '{$identifier}' LIMIT 1;";
                }
                // check if file is not public
                $secrs= $this->db->query($q);
                if ($secrs)
                    $seclimit= $this->db->getRecordCount($secrs);
            }
            if ($seclimit > 0) {
                // match found but not publicly accessible, send the visitor to the unauthorized_page
                $this->sendUnauthorizedPage();
                exit; // stop here
            } else {
                $this->sendErrorPage();
                exit;
            }
        }

        # this is now the document :) #
        $documentObject= $this->db->getRow($result);

        if ($documentObject['template']) {
            // load TVs and merge with document - Orig by Apodigm - Docvars
            $sql= "SELECT tv.*, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
            $sql .= "FROM " . $this->getFullTableName("site_tmplvars") . " tv ";
            $sql .= "INNER JOIN " . $this->getFullTableName("site_tmplvar_templates")." tvtpl ON tvtpl.tmplvarid = tv.id ";
            $sql .= "LEFT JOIN " . $this->getFullTableName("site_tmplvar_contentvalues")." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '" . $documentObject['id'] . "' ";
            $sql .= "WHERE tvtpl.templateid = '" . $documentObject['template'] . "'";
            $rs= $this->db->query($sql);
            $rowCount= $this->db->getRecordCount($rs);
            if ($rowCount > 0) {
                for ($i= 0; $i < $rowCount; $i++) {
                    $row= $this->db->getRow($rs);
                    $tmplvars[$row['name']]= array (
                        $row['name'],
                        $row['value'],
                        $row['display'],
                        $row['display_params'],
                        $row['type']
                    );
                }
                $documentObject= array_merge($documentObject, $tmplvars);
            }
        }
        
        return $documentObject;
    }

    /**
     * Parse a source string.
     *
     * Handles most MODx tags. Exceptions include:
     *   - URL tags [~...~]
     *
     * @param string $source
     * @param bool $uncached_snippets
     * @return string
     */
    function parseDocumentSource($source, $uncached_snippets = false) {
        // set the number of times we are to parse the document source
        $this->minParserPasses= empty ($this->minParserPasses) ? 2 : $this->minParserPasses;
        $this->maxParserPasses= empty ($this->maxParserPasses) ? 10 : $this->maxParserPasses;
        $passes= $this->minParserPasses;
        for ($i= 0; $i < $passes; $i++) {
            // get source length if this is the final pass
            if ($i == ($passes -1))
                $st= strlen($source);
            if ($this->dumpSnippets == 1) {
                echo "<fieldset><legend><b style='color: #821517;'>PARSE PASS " . ($i +1) . "</b></legend>The following snippets (if any) were parsed during this pass.<div style='width:100%' align='center'>";
            }

            // invoke OnParseDocument event
            $this->documentOutput= $source; // store source code so plugins can
            $this->invokeEvent("OnParseDocument"); // work on it via $modx->documentOutput
            $source= $this->documentOutput;

            // combine template and document variables
            $source= $this->mergeDocumentContent($source);
            // replace settings referenced in document
            $source= $this->mergeSettingsContent($source);
            // replace HTMLSnippets in document
            $source= $this->mergeChunkContent($source);
            
            if ($uncached_snippets) {
                $source = str_replace(array('[!', '!]'), array('[[', ']]'), $source);
            }
            
            // find and merge snippets
            $source= $this->evalSnippets($source);
            // find and replace Placeholders (must be parsed last) - Added by Raymond
            $source= $this->mergePlaceholderContent($source);
            if ($this->dumpSnippets == 1) {
                echo "</div></fieldset><br />";
            }
            if ($i == ($passes -1) && $i < ($this->maxParserPasses - 1)) {
                // check if source length was changed
                $et= strlen($source);
                if ($st != $et)
                    $passes++; // if content change then increase passes because
            } // we have not yet reached maxParserPasses
        }
        return $source;
    }

    /**
     * Starts the parsing operations.
     * 
     * - connects to the db
     * - gets the settings (including system_settings)
     * - gets the document/resource identifier as in the query string
     * - finally calls prepareResponse()
     */
    function executeParser() {

        $this->set_error_handler();

        $this->db->connect();

        // get the settings
        if (empty ($this->config)) {
            $this->getSettings();
        }

        // IIS friendly url fix
        if ($this->config['friendly_urls'] == 1 && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
            $url= $_SERVER['QUERY_STRING'];
            $err= substr($url, 0, 3);
            if ($err == '404' || $err == '405') {
                $k= array_keys($_GET);
                unset ($_GET[$k[0]]);
                unset ($_REQUEST[$k[0]]); // remove 404,405 entry
                $_SERVER['QUERY_STRING']= $qp['query'];
                $qp= parse_url(str_replace($this->config['site_url'], '', substr($url, 4)));
                if (!empty ($qp['query'])) {
                    parse_str($qp['query'], $qv);
                    foreach ($qv as $n => $v)
                        $_REQUEST[$n]= $_GET[$n]= $v;
                }
                $_SERVER['PHP_SELF']= $this->config['base_url'] . $qp['path'];
                $_REQUEST['q']= $_GET['q']= $qp['path'];
            }
        }

        // check site settings
        if (!$this->checkSiteStatus()) {
            header('HTTP/1.0 503 Service Unavailable');
            if (!$this->config['site_unavailable_page']) {
                // display offline message
                $this->documentContent= $this->config['site_unavailable_message'];
                $this->outputContent();
                exit; // stop processing here, as the site's offline
            } else {
                // setup offline page document settings
                $this->documentMethod= "id";
                $this->documentIdentifier= $this->config['site_unavailable_page'];
            }
        } else {
            // make sure the cache doesn't need updating
            $this->checkPublishStatus();

            // find out which document we need to display
            $this->documentMethod= $this->getDocumentMethod();
            $this->documentIdentifier= $this->getDocumentIdentifier($this->documentMethod);

            $this->is_rss = ($this->documentMethod == 'rss' || !empty($_GET['rss']));

            if (is_int($this->documentIdentifier)) {
                $this->documentMethod = 'id';
            }
        }

        if ($this->documentMethod == "none") {
            $this->documentMethod= "id"; // now we know the site_start, change the none method to id
        }

        if ($this->documentMethod == 'alias' || $this->documentMethod == 'rss') {
            $this->documentIdentifier= $this->cleanDocumentIdentifier($this->documentIdentifier);
        }

        if ($this->documentMethod == 'alias' || $this->documentMethod == 'rss') {
            // Check use_alias_path and check if $this->virtualDir is set to anything, then parse the path
            if ($this->config['use_alias_path'] == 1) {
                $alias= (strlen($this->virtualDir) > 0 ? $this->virtualDir . '/' : '') . $this->documentIdentifier;
                if (array_key_exists($alias, $this->documentListing)) {
                    $this->documentIdentifier= $this->documentListing[$alias];
                } else {
                    $this->sendErrorPage();
                }
            } else {
                $this->documentIdentifier= $this->documentListing[$this->documentIdentifier];
            }
            $this->documentMethod= 'id';
        }

        // invoke OnWebPageInit event
        $this->invokeEvent("OnWebPageInit");

        // invoke OnLogPageView event
        if ($this->config['track_visitors'] == 1) {
            $this->invokeEvent("OnLogPageHit");
        }

        $this->prepareResponse();
    }

    /**
     * The next step called at the end of executeParser()
     *
     * - checks cache
     * - checks if document/resource is deleted/unpublished
     * - checks if resource is a weblink and redirects if so
     * - gets template and parses it
     * - ensures that postProcess is called when PHP is finished
     */
    function prepareResponse() {
        if ($this->is_rss) {
            $this->documentContent = ''; // RSS is not cached
        } else {
            // we now know the method and identifier, let's check the cache
            $this->documentContent= $this->checkCache($this->documentIdentifier);
        }
        
        if ($this->documentContent != "") {
            // invoke OnLoadWebPageCache  event
            $this->invokeEvent("OnLoadWebPageCache");
        } else {
            // get document object
            $this->documentObject= $this->getDocumentObject($this->documentMethod, $this->documentIdentifier);

            // write the documentName to the object
            $this->documentName= $this->documentObject['pagetitle'];

            // validation routines
            if ($this->documentObject['deleted'] == 1) {
                $this->sendErrorPage();
            }
            //  && !$this->checkPreview()
            if ($this->documentObject['published'] == 0) {

                // Can't view unpublished pages
                if (!$this->hasPermission('view_unpublished')) {
                    $this->sendErrorPage();
                } else {
                    // Inculde the necessary files to check document permissions
                    require_once('user_documents_permissions.class.php');
                    $udperms= new udperms();
                    $udperms->user= $this->getLoginUserID();
                    $udperms->document= $this->documentIdentifier;
                    $udperms->role= $_SESSION['mgrRole'];
                    // Doesn't have access to this document
                    if (!$udperms->checkPermissions()) {
                        $this->sendErrorPage();
                    }

                }

            }

            // check whether it's a reference
            if ($this->documentObject['type'] == "reference") {
                if (is_numeric($this->documentObject['content'])) {
                    // if it's a bare document id
                    $this->documentObject['content']= $this->makeUrl($this->documentObject['content']);
                }
                elseif (strpos($this->documentObject['content'], '[~') !== false) {
                    // if it's an internal docid tag, process it
                    $this->documentObject['content']= $this->rewriteUrls($this->documentObject['content']);
                }
                $this->sendRedirect($this->documentObject['content'], 0, '', 'HTTP/1.0 301 Moved Permanently');
            }

            // check if we should not hit this document
            if ($this->documentObject['donthit'] == 1) {
                $this->config['track_visitors']= 0;
            }

            if ($this->is_rss) {
                // The following line could be a config option
                $this->documentContent = '[[List? &format=`rss` &depth=`0` &display=`'.$this->config['rss_len'].'` &summarize=`'.$this->config['rss_len'].'` &parents=`'.($this->documentIdentifier == $this->config['site_start'] ? 0 : $this->documentIdentifier).'`]]';
            } else {
		        // get the template and start parsing!
		        if (!$this->documentObject['template'])
		            $this->documentContent= "[*content*]"; // use blank template
		        else {
		            $sql= "SELECT `content` FROM " . $this->getFullTableName("site_templates") . " WHERE " . $this->getFullTableName("site_templates") . ".`id` = '" . $this->documentObject['template'] . "';";
		            $result= $this->db->query($sql);
		            $rowCount= $this->db->getRecordCount($result);
		            if ($rowCount > 1) {
		                $this->messageQuit("Incorrect number of templates returned from database", $sql);
		            }
		            elseif ($rowCount == 1) {
		                $row= $this->db->getRow($result);
		                $this->documentContent= $row['content'];
		            }
		        }
		    }

            // invoke OnLoadWebDocument event
            $this->invokeEvent("OnLoadWebDocument");

            // Parse document source
            $this->documentContent= $this->parseDocumentSource($this->documentContent);

            // setup <base> tag for friendly urls
            //			if($this->config['friendly_urls']==1 && $this->config['use_alias_path']==1) {
            //				$this->regClientStartupHTMLBlock('<base href="'.$this->config['site_url'].'" />');
            //			}
        }
        register_shutdown_function(array (
            & $this,
            "postProcess"
        )); // tell PHP to call postProcess when it shuts down
        $this->outputContent();
        //$this->postProcess();
    }

    /**
     * Returns an array of all parent record IDs for the id passed.
     *
     * @param int $id Docid to get parents for.
     * @param int $height The maximum number of levels to go up, default 10.
     * @return array
     */
    function getParentIds($id, $height= 10) {
        $parents= array ();
        while ( $id && $height-- ) {
            $thisid = $id;
            $id = $this->aliasListing[$id]['parent'];
            if (!$id) break;
            $pkey = strlen($this->aliasListing[$thisid]['path']) ? $this->aliasListing[$thisid]['path'] : $this->aliasListing[$id]['alias'];
            if (!strlen($pkey)) $pkey = "{$id}";
            $parents[$pkey] = $id;
        }
        return $parents;
    }
    
    /**
     * Get the parent docid of a document
     * 
     * @param int docid
     * @return int
     */
    function getParentId($id) {
        return $this->aliasListing[$id]['parent'];
    }

    /**
     * Returns the ultimate parent of a document
     *
     * @param int $id Docid to get ultimate parent.
     * @return int
     */
    function getUltimateParentId($id) {
        while ($id) {
        	$last_id = $id;
            $id = $this->aliasListing[$id]['parent'];
        }
        return $last_id;
    }

    /**
     * Returns an array of child IDs belonging to the specified parent.
     *
     * @param int $id The parent resource/document to start from
     * @param int $depth How many levels deep to search for children, default: 10
     * @param array $children Optional array of docids to merge with the result.
     * @return array Contains the document Listing (tree) like the sitemap
     */
    function getChildIds($id, $depth= 10, $children= array ()) {

        // Initialise a static array to index parents->children
        static $documentMap_cache = array();
        if (!count($documentMap_cache)) {
            foreach ($this->documentMap as $document) {
                foreach ($document as $p => $c) {
                    $documentMap_cache[$p][] = $c;
                }
            }
        }

        // Get all the children for this parent node
        if (isset($documentMap_cache[$id])) {
            $depth--;

            foreach ($documentMap_cache[$id] as $childId) {
                $pkey = (strlen($this->aliasListing[$childId]['path']) ? "{$this->aliasListing[$childId]['path']}/" : '') . $this->aliasListing[$childId]['alias'];
                if (!strlen($pkey)) $pkey = "{$childId}";
                    $children[$pkey] = $childId;

                if ($depth) {
                    $children += $this->getChildIds($childId, $depth);
                }
            }
        }
        return $children;
    }

    /**
     * Displays a javascript alert message in the web browser
     *
     * @param string $msg Message to show
     * @param string $url URL to redirect to
     */
    function webAlert($msg, $url= "") {
        $msg= addslashes($this->db->escape($msg));
        if (substr(strtolower($url), 0, 11) == "javascript:") {
            $act= "__WebAlert();";
            $fnc= "function __WebAlert(){" . substr($url, 11) . "};";
        } else {
            $act= ($url ? "window.location.href='" . addslashes($url) . "';" : "");
        }
        $html= "<script>$fnc window.setTimeout(\"alert('$msg');$act\",100);</script>";
        if ($this->isFrontend())
            $this->regClientScript($html);
        else {
            echo $html;
        }
    }

    /**
     * Returns true if user has the currect permission
     *
     * @param string $pm Permission name
     * @return int
     */
    function hasPermission($pm) {
        $state= false;
        $pms= $_SESSION['mgrPermissions'];
        if ($pms)
            $state= ($pms[$pm] == 1);
        return $state;
    }

    /**
     * Add an a alert message to the system event log
     *
     * @param int $evtid Event ID
     * @param int $type Types: 1 = information, 2 = warning, 3 = error
     * @param string $msg Message to be logged
     * @param string $source source of the event (module, snippet name, etc.)
     *                       Default: Parser
     */
    function logEvent($evtid, $type, $msg, $source= 'Parser') {
        $msg= $this->db->escape($msg);
        $source= $this->db->escape($source);
        if ($GLOBALS['database_connection_charset'] == 'utf8' && extension_loaded('mbstring')) {
            $source = mb_substr($source, 0, 50 , "UTF-8");
        } else {
            $source = substr($source, 0, 50);
        }
		
        $LoginUserID = $this->getLoginUserID();
        if ($LoginUserID == '') $LoginUserID = 0;
		
		$usertype = $this->isFrontend() ? 1 : 0;
		
        $evtid= intval($evtid);
        $type = intval($type);
        if ($type < 1) {
            $type= 1;
        } elseif ($type > 3) {
            $type= 3; // Types: 1 = information, 2 = warning, 3 = error
        }
		
		$ds = $this->db->insert(array(
			'eventid' => $evtid,
			'type' =>$type,
			'createdon' => time(),
			'source' => $source,
			'description' => $msg, 
			'user' => $LoginUserID,
			'usertype' => $usertype
		), $this->getFullTableName("event_log"));
		
        if (!$ds) {
            echo "Error while inserting event log into database.";
            exit();
        }
    }

    /**
     * Returns true if we are currently in the manager/backend
     *
     * @return boolean
     */
    function isBackend() {
        return $this->insideManager() ? true : false;
    }

    /**
     * Returns true if we are currently in the frontend
     *
     * @return boolean
     */
    function isFrontend() {
        return !$this->insideManager() ? true : false;
    }

    /**
     * Gets all child documents of the specified document, including those which are unpublished or deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    function getAllChildren($id= 0, $sort= 'menuindex', $dir= 'ASC', $fields= 'id, pagetitle, description, parent, alias, menutitle') {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");
        // modify field names to use sc. table reference
        $fields= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // build query
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql= "SELECT DISTINCT $fields FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$id'
              AND ($access)
              GROUP BY sc.id
              ORDER BY $sort $dir;";
        $result= $this->db->query($sql);
        $resourceArray= array ();
        for ($i= 0; $i < @ $this->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Gets all active child documents of the specified document, i.e. those which published and not deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    function getActiveChildren($id= 0, $sort= 'menuindex', $dir= 'ASC', $fields= 'id, pagetitle, description, parent, alias, menutitle') {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");

        // modify field names to use sc. table reference
        $fields= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // build query
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql= "SELECT DISTINCT $fields FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$id' AND sc.published=1 AND sc.deleted=0
              AND ($access)
              GROUP BY sc.id
              ORDER BY $sort $dir;";
        $result= $this->db->query($sql);
        $resourceArray= array ();
        for ($i= 0; $i < @ $this->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Returns the children of the selected document/folder.
     *
     * @param int $parentid The parent document identifier
     *                      Default: 0 (site root)
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param string $where Where condition in SQL style. Should include a leading 'AND '
     *                      Default: Empty string
     * @param type $sort Should be a comma-separated list of field names on which to sort
     *                    Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string|int $limit Should be a valid SQL LIMIT clause without the 'LIMIT' i.e. just include the numbers as a string.
     *                          Default: Empty string (no limit)
     * @return array
     */
    function getDocumentChildren($parentid= 0, $published= 1, $deleted= 0, $fields= "*", $where= '', $sort= "menuindex", $dir= "ASC", $limit= "") {
        $limit= ($limit != "") ? "LIMIT $limit" : "";
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");
        // modify field names to use sc. table reference
        $fields= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort= ($sort == "") ? "" : 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        if ($where != '')
            $where= 'AND ' . $where;
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // build query
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql= "SELECT DISTINCT $fields
              FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$parentid' ".($published == DP_PUB_ALL ? '' : "AND sc.published=$published ")."AND sc.deleted=$deleted $where
              AND ($access)
              GROUP BY sc.id " .
         ($sort ? " ORDER BY $sort $dir " : "") . " $limit ";
        $result= $this->db->query($sql);
        $resourceArray= array ();
        for ($i= 0; $i < @ $this->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Returns multiple documents/resources
     *
     * @category API-Function
     * @param array $ids Documents to fetch by docid
     *                   Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param string $where Where condition in SQL style. Should include a leading 'AND '.
     *                      Default: Empty string
     * @param type $sort Should be a comma-separated list of field names on which to sort
     *                    Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string|int $limit Should be a valid SQL LIMIT clause without the 'LIMIT' i.e. just include the numbers as a string.
     *                          Default: Empty string (no limit)
     * @return array|boolean Result array with documents, or false
     */
    function getDocuments($ids= array (), $published= 1, $deleted= 0, $fields= "*", $where= '', $sort= "menuindex", $dir= "ASC", $limit= "") {
        if (count($ids) == 0) {
            return false;
        } else {
            $limit= ($limit != "") ? "LIMIT $limit" : ""; // LIMIT capabilities - rad14701
            $tblsc= $this->getFullTableName("site_content");
            $tbldg= $this->getFullTableName("document_groups");
            // modify field names to use sc. table reference
            $fields= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
            $sort= ($sort == "") ? "" : 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
            if ($where != '')
                $where= 'AND ' . $where;
            // get document groups for current user
            if ($docgrp= $this->getUserDocGroups())
                $docgrp= implode(",", $docgrp);
            $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
             (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
            $sql= "SELECT DISTINCT $fields FROM $tblsc sc
                    LEFT JOIN $tbldg dg on dg.document = sc.id
                    WHERE (sc.id IN (" . implode(",",$ids) . ") ".($published == DP_PUB_ALL ? '' : "AND sc.published=$published ")."AND sc.deleted=$deleted $where)
                    AND ($access)
                    GROUP BY sc.id " .
             ($sort ? " ORDER BY $sort $dir" : "") . " $limit ";
            $result= $this->db->query($sql);
            $resourceArray= array ();
            for ($i= 0; $i < @ $this->db->getRecordCount($result); $i++) {
                array_push($resourceArray, @ $this->db->getRow($result));
            }
            return $resourceArray;
        }
    }

    /**
     * Returns one document/resource
     *
     * @category API-Function
     * @param int $id docid
     *                Default: 0 (no documents)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @return boolean|string
     */
    function getDocument($id= 0, $fields= "*", $published= 1, $deleted= 0) {
        if ($id == 0) {
            return false;
        } else {
            $tmpArr[]= $id;
            $docs= $this->getDocuments($tmpArr, $published, $deleted, $fields, "", "", "", 1);
            if ($docs != false) {
                return $docs[0];
            } else {
                return false;
            }
        }
    }

    /**
     * Returns allowed child templates for a document
     *
     * @param $docid
     * @return array
     */
    function getDocumentAllowedChildTemplates($docid) {
        $rs = $this->db->query('SELECT te.restrict_children, te.allowed_child_templates
                                    FROM '.$this->getFullTableName('site_content').' sc, '.$this->getFullTableName('site_templates').' te
                                    WHERE sc.template = te.id
                                    AND sc.id = '.$docid);
        $row = $this->db->getRow($rs);

        if ($row['restrict_children']) {
            $allowed_child_templates = trim($row['allowed_child_templates']);
            return $allowed_child_templates ? explode(',', $allowed_child_templates) : array();
        } else {
            $rs2 = $this->db->select('id', $this->getFullTableName('site_templates'));
            return $this->db->getColumn('id', $rs2);
        }
    }

    /**
     * Returns the page information as database row, the type of result is
     * defined with the parameter $rowMode
     *
     * @param int $pageid The parent document identifier
     *                    Default: -1 (no result)
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    function getPageInfo($pageid= -1, $active= 1, $fields= 'id, pagetitle, description, alias') {
        if ($pageid == 0) {
            return false;
        } else {
            $tblsc= $this->getFullTableName("site_content");
            $tbldg= $this->getFullTableName("document_groups");
            $activeSql= $active == 1 ? "AND sc.published=1 AND sc.deleted=0" : "";
            // modify field names to use sc. table reference
            $fields= 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
            // get document groups for current user
            if ($docgrp= $this->getUserDocGroups())
                $docgrp= implode(",", $docgrp);
            $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
             (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
            $sql= "SELECT $fields
                    FROM $tblsc sc
                    LEFT JOIN $tbldg dg on dg.document = sc.id
                    WHERE (sc.id=$pageid $activeSql)
                    AND ($access)
                    LIMIT 1 ";
            $result= $this->db->query($sql);
            $pageInfo= @ $this->db->getRow($result);
            return $pageInfo;
        }
    }

    /**
     * Returns the parent document/resource of the given docid
     *
     * @param int $pid The parent docid. If -1, then fetch the current document/resource's parent
     *                 Default: -1
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    function getParent($pid= -1, $active= 1, $fields= 'id, pagetitle, description, alias, parent') {
        if ($pid == -1) {
            $pid= $this->documentObject['parent'];
            return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
        } else
            if ($pid == 0) {
                return false;
            } else {
                // first get the child document
                $child= $this->getPageInfo($pid, $active, "parent");
                // now return the child's parent
                $pid= ($child['parent']) ? $child['parent'] : 0;
                return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
            }
    }

    /**
     * Returns the id of the current snippet.
     *
     * @return int
     */
    function getSnippetId() {
        if ($this->currentSnippet) {
            $tbl= $this->getFullTableName("site_snippets");
            $rs= $this->db->query("SELECT id FROM $tbl WHERE name='" . $this->db->escape($this->currentSnippet) . "' LIMIT 1");
            $row= @ $this->db->getRow($rs);
            if ($row['id'])
                return $row['id'];
        }
        return 0;
    }

    /**
     * Returns the name of the current snippet.
     *
     * @return string
     */
    function getSnippetName() {
        return $this->currentSnippet;
    }

    /**
     * Clear the cache of MODX. Only clears page cache files; does not affect the main site cache file.
     *
     * @return bool
     */
    function clearCache() {
        $basepath= $this->config["base_path"] . "assets/cache";
        if (@ $handle= opendir($basepath)) {
            $filesincache= 0;
            $deletedfilesincache= 0;
            while (false !== ($file= readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $filesincache += 1;
                    if ($this->isPageCacheFile($file)) {
                        $deletedfilesincache += 1;
                        unlink($basepath . "/" . $file);
                    }
                }
            }
            closedir($handle);
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Refresh the entire cache of MODX including cache files and script caches that are properties of $this
     *
     * @return bool
     */
    function refreshCache() {
        require_once(MODX_BASE_PATH.'/manager/processors/cache_sync.class.processor.php');
        $sync = new synccache();
        $sync->setCachepath(MODX_BASE_PATH.'/assets/cache/');
        $sync->setReport(false);
        $sync->emptyCache();
        if (file_exists(MODX_BASE_PATH.'assets/cache/siteCache.idx.php')) {
            $this->config = null;
            $this->aliasListing = null;
            $this->documentListing = null;
            $this->documentMap = null;
            $this->contentTypes = null;
            $this->chunkCache = null;
            $this->snippetCache = null;
            $this->pluginCache = null;
            $this->pluginEvent = null;
            require(MODX_BASE_PATH.'assets/cache/siteCache.idx.php');
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Get the path of a page cache file
     *
     * @param int $docid
     * @param bool $fullpath If false give the path relative to the site root, if true give the fullpath. Default true.
     * @return string
     */
    function pageCacheFile($docid, $fullpath = true) {
        return ($fullpath ? $this->config['base_path'] : '')."assets/cache/docid_{$docid}.pageCache.php";
    }
    
    /**
     * Is a file a page cache file?
     *
     * @param string $filename
     * @return bool
     */
    function isPageCacheFile($filename) {
        return (bool)preg_match('/^docid_\d+\.pageCache\.php$/', $filename);
    }

    /**
     * Create an URL for the given document identifier. The url prefix and
     * postfix are used, when friendly_url is active.
     *
     * @param int $id The document identifier
     * @param string $alias The alias name for the document
     *                      Default: Empty string
     * @param string $args The paramaters to add to the URL
     *                     Default: Empty string
     * @param string $scheme With full as valus, the site url configuration is
     *                       used
     *                       Default: Empty string
     * @return string
     */
     function makeUrl($id, $alias= '', $args= '', $scheme= '') {
        $url= '';
        $virtualDir= '';
        $f_url_prefix = $this->config['friendly_url_prefix'];
        $f_url_suffix = $this->config['friendly_url_suffix'];
        if (!is_numeric($id)) {
            $this->messageQuit('`' . $id . '` is not numeric and may not be passed to makeUrl()');
        }
        if ($args != '' && $this->config['friendly_urls'] == 1) {
            // add ? to $args if missing
            $c= substr($args, 0, 1);
            if (strpos($f_url_prefix, '?') === false) {
                if ($c == '&')
                    $args= '?' . substr($args, 1);
                elseif ($c != '?') $args= '?' . $args;
            } else {
                if ($c == '?')
                    $args= '&' . substr($args, 1);
                elseif ($c != '&') $args= '&' . $args;
            }
        }
        elseif ($args != '') {
            // add & to $args if missing
            $c= substr($args, 0, 1);
            if ($c == '?')
                $args= '&' . substr($args, 1);
            elseif ($c != '&') $args= '&' . $args;
        }
        if ($this->config['friendly_urls'] == 1 && $alias != '') {
            $url= $f_url_prefix . $alias . $f_url_suffix . $args;
        }
        elseif ($this->config['friendly_urls'] == 1 && $alias == '') {
            $alias= $id;
            if ($this->config['friendly_alias_urls'] == 1) {
                $al= $this->aliasListing[$id];
                $alPath= !empty ($al['path']) ? $al['path'] . '/' : '';
                if ($al && $al['alias'])
                    $alias= $al['alias'];
            }
            $alias= $alPath . $f_url_prefix . $alias . $f_url_suffix;
            $url= $alias . $args;
        } else {
            $url= 'index.php?id=' . $id . $args;
        }

        $host= $this->config['base_url'];
        // check if scheme argument has been set
        if ($scheme != '') {
            // for backward compatibility - check if the desired scheme is different than the current scheme
            if (is_numeric($scheme) && $scheme != $_SERVER['HTTPS']) {
                $scheme= ($_SERVER['HTTPS'] ? 'http' : 'https');
            }

            // to-do: check to make sure that $site_url incudes the url :port (e.g. :8080)
            $host= $scheme == 'full' ? $this->config['site_url'] : $scheme . '://' . $_SERVER['HTTP_HOST'] . $host;
        }

        if ($this->config['xhtml_urls']) {
        	return preg_replace("/&(?!amp;)/","&amp;", $host . $virtualDir . $url);
        } else {
        	return $host . $virtualDir . $url;
        }
    }

    /**
     * Returns an entry from the config
     *
     * Note: most code accesses the config array directly and we will continue to support this.
     *
     * @return boolean|string
     */
    function getConfig($name= '') {
        if (!empty ($this->config[$name])) {
            return $this->config[$name];
        } else {
            return false;
        }
    }

    /**
     * Returns the ClipperCMS version information as version, branch, release date and full application name.
     *
     * @return array
     */
    function getVersionData() {
        require_once($this->config["base_path"] . "manager/includes/version.inc.php");
        $v= array ();
        $v['version']= CMS_RELEASE_VERSION;
        $v['branch']= CMS_NAME;
        $v['release_date']= CMS_RELEASE_DATE;
        $v['full_appname']= CMS_FULL_APPNAME;
        return $v;
    }

    /**
     * Returns an ordered or unordered HTML list.
     *
     * @param array $array
     * @param string $ulroot Default: root
     * @param string $ulprefix Default: sub_
     * @param string $type Default: Empty string
     * @param boolean $ordered Default: false
     * @param int $tablevel Default: 0
     * @return string
     */
    function makeList($array, $ulroot= 'root', $ulprefix= 'sub_', $type= '', $ordered= false, $tablevel= 0) {
        // first find out whether the value passed is an array
        if (!is_array($array)) {
            return "<ul><li>Bad list</li></ul>";
        }
        if (!empty ($type)) {
            $typestr= " style='list-style-type: $type'";
        } else {
            $typestr= "";
        }
        $tabs= "";
        for ($i= 0; $i < $tablevel; $i++) {
            $tabs .= "\t";
        }
        $listhtml= $ordered == true ? $tabs . "<ol class='$ulroot'$typestr>\n" : $tabs . "<ul class='$ulroot'$typestr>\n";
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $listhtml .= $tabs . "\t<li>" . $key . "\n" . $this->makeList($value, $ulprefix . $ulroot, $ulprefix, $type, $ordered, $tablevel +2) . $tabs . "\t</li>\n";
            } else {
                $listhtml .= $tabs . "\t<li>" . $value . "</li>\n";
            }
        }
        $listhtml .= $ordered == true ? $tabs . "</ol>\n" : $tabs . "</ul>\n";
        return $listhtml;
    }

    /**
     * Returns user login information, as loggedIn (true or false), internal key, username and usertype (web or manager).
     *
     * @return boolean|array
     */
    function userLoggedIn() {
        $userdetails= array ();
        if ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            // web user
            $userdetails['loggedIn']= true;
            $userdetails['id']= $_SESSION['webInternalKey'];
            $userdetails['username']= $_SESSION['webShortname'];
            $userdetails['usertype']= 'web'; // added by Raymond
            return $userdetails;
        } else
            if ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
                // manager user
                $userdetails['loggedIn']= true;
                $userdetails['id']= $_SESSION['mgrInternalKey'];
                $userdetails['username']= $_SESSION['mgrShortname'];
                $userdetails['usertype']= 'manager'; // added by Raymond
                return $userdetails;
            } else {
                return false;
            }
    }

    /**
     * Returns an array with keywords for the current document, or a document with a given docid
     *
     * @param int $id The docid, 0 means the current document
     *                Default: 0
     * @return array
     * @deprecated
     */
    function getKeywords($id= 0) {
    	//content removed as this function is deprecated
        $keywords= array ();
        return $keywords;
    }

    /**
     * Returns an array with meta tags for the current document, or a document with a given docid.
     *
     * @param int $id The document identifier, 0 means the current document
     *                Default: 0
     * @return array
     * @deprecated
     */
    function getMETATags($id= 0) {
    	//content removed as this function is deprecated
        $metatags= array ();
        return $metatags;
    }

    /**
     * Executes a snippet.
     *
     * @param string $snippetName
     * @param array $params Default: Empty array
     * @return string
     */
    function runSnippet($snippetName, $params= array ()) {

        if (isset($this->snippetMap[strtolower($snippetName)])) {
             $snippetName = $this->snippetMap[strtolower($snippetName)];
        }

        if (isset ($this->snippetCache[$snippetName])) {
            $snippet= $this->snippetCache[$snippetName];
            $properties= $this->snippetCache[$snippetName . "Props"];
        } else { // not in cache so let's check the db
            $sql= "SELECT `name`, `snippet`, `properties` FROM " . $this->getFullTableName("site_snippets") . " WHERE " . $this->getFullTableName("site_snippets") . ".`name`='" . $this->db->escape($snippetName) . "';";
            $result= $this->db->query($sql);
            if ($this->db->getRecordCount($result) == 1) {
                $row= $this->db->getRow($result);
                $snippet= $this->snippetCache[$row['name']]= $row['snippet'];
                $properties= $this->snippetCache[$row['name'] . "Props"]= $row['properties'];
            } else {
                $snippet= $this->snippetCache[$snippetName]= null;
                $properties= '';
            }
        }
        // load default params/properties
        $parameters= $this->parseProperties($properties);
        $parameters= array_merge($parameters, $params);
        // run snippet
        return $this->evalSnippet($snippet, $parameters, $snippetName);
    }

    /**
     * Returns the chunk content for the given chunk name
     * 
     * @param string $chunkName
     * @return boolean|string
     */
   function getChunk($chunkName) {
        $t= $this->chunkCache[$chunkName];
        return $t;
    }

    /**
     * Old method that just calls getChunk()
     * 
     * @deprecated Use getChunk
     * @param string $chunkName
     * @return boolean|string
     */
    function putChunk($chunkName) { // alias name >.<
        return $this->getChunk($chunkName);
    }

	/**
	 * Parse a chunk for placeholders
	 *
	 * @param string $chunkname Name of chunk to get from db
	 * @param string $chunkArr Array of placeholder names (array keys) and replacements (array values)
	 * @param string $prefix Placeholder prefix. Defaults to [+
	 * @param string $suffix Placeholder suffix. Defaults to +]
	 * @return string
	 */
    function parseChunk($chunkName, $chunkArr, $prefix= "[+", $suffix= "+]") {
        if (!is_array($chunkArr)) {
            return false;
        }
        $chunk= $this->getChunk($chunkName);
        foreach ($chunkArr as $key => $value) {
            $chunk= str_replace($prefix . $key . $suffix, $value, $chunk);
        }
        return $chunk;
    }

    /**
     * Get data from phpSniff
     *
     * @category API-Function
     * @return array
     */
    function getUserData() {
        include $this->config["base_path"] . "manager/includes/extenders/getUserData.extender.php";
        return $tmpArray;
    }

    /**
     * Returns the timestamp in the date format defined in $this->config['date_format']
     *
     * @param int $timestamp Default: 0
     * @param string $mode Default: Empty string (adds the time as below). Can also be 'dateOnly' for no time or 'formatOnly' to get the date_format string.
     * @return string
     */
    function toDateFormat($timestamp = 0, $mode = '') {
        $timestamp = trim($timestamp);
        $timestamp = intval($timestamp);

        switch($this->config['date_format']) {
            case 'dd-mm-yy':
                $dateFormat = '%d-%m-%Y';
                break;
            case 'mm/dd/yy':
                $dateFormat = '%m/%d/%Y';
                break;
            case 'yy/mm/dd':
                $dateFormat = '%Y/%m/%d';
                break;
        }
        
        switch($this->config['time_format']) {
            case 'HH:mm:ss':
                $timeFormat = '%H:%M:%S';
                break;
        }

        if (empty($mode)) {
            $strTime = strftime($dateFormat . " " . $timeFormat, $timestamp);
        } elseif ($mode == 'dateOnly') {
            $strTime = strftime($dateFormat, $timestamp);
        } elseif ($mode == 'formatOnly') {
        	$strTime = $dateFormat;
        }
        return $strTime;
    }

    /**
     * Make a timestamp from a string corresponding to the format in $this->config['date_format']
     *
     * @param string $str
     * @return string
     */
    function toTimeStamp($str) {
        $str = trim($str);
        if (empty($str)) {return '';}

		switch($this->config['date_format']) {
            case 'dd-mm-yy':
            	if (!preg_match('/^[0-9]{2}-[0-9]{2}-[0-9]{4}[0-9 :]*$/', $str)) {return '';}
                list ($d, $m, $Y, $H, $M, $S) = sscanf($str, '%2d-%2d-%4d %2d:%2d:%2d');
                break;
            case 'mm/dd/yy':
            	if (!preg_match('/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}[0-9 :]*$/', $str)) {return '';}
                list ($m, $d, $Y, $H, $M, $S) = sscanf($str, '%2d/%2d/%4d %2d:%2d:%2d');
                break;
            case 'yy/mm/dd':
            	if (!preg_match('/^[0-9]{4}\/[0-9]{2}\/[0-9]{2}[0-9 :]*$/', $str)) {return '';}
                list ($Y, $m, $d, $H, $M, $S) = sscanf($str, '%4d/%2d/%2d %2d:%2d:%2d');
                break;
        }
        
        if (!$H && !$M && !$S) {$H = 0; $M = 0; $S = 0;}
        $timeStamp = mktime($H, $M, $S, $m, $d, $Y);
        $timeStamp = intval($timeStamp);
        return $timeStamp;
    }

    /**
     * Get the TVs of a document's children. Returns an array where each element represents one child doc.
     *
     * Ignores deleted children. Gets all children - there is no where clause available.
     *
     * @param int $parentid The parent docid
     *                 Default: 0 (site root)
     * @param array $tvidnames. Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                      Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                      Default: 1
     * @param string $docsort How to sort the result array (field)
     *                      Default: menuindex
     * @param ASC $docsortdir How to sort the result array (direction)
     *                      Default: ASC
     * @param string $tvfields Fields to fetch from site_tmplvars, default '*'
     *                      Default: *
     * @param string $tvsort How to sort each element of the result array i.e. how to sort the TVs (field)
     *                      Default: rank
     * @param string  $tvsortdir How to sort each element of the result array i.e. how to sort the TVs (direction)
     *                      Default: ASC
     * @return boolean|array
     */
    function getDocumentChildrenTVars($parentid= 0, $tvidnames= array (), $published= 1, $docsort= "menuindex", $docsortdir= "ASC", $tvfields= "*", $tvsort= "rank", $tvsortdir= "ASC") {
        $docs= $this->getDocumentChildren($parentid, $published, 0, '*', '', $docsort, $docsortdir);
        if (!$docs)
            return false;
        else {
            $result= array ();
            // get user defined template variables
            $fields= ($tvfields == "") ? "tv.*" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $tvfields)));
            $tvsort= ($tvsort == "") ? "" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $tvsort)));
            if ($tvidnames == "*")
                $query= "tv.id<>0";
            else
                $query= (is_numeric($tvidnames[0]) ? "tv.id" : "tv.name") . " IN ('" . implode("','", $tvidnames) . "')";
            if ($docgrp= $this->getUserDocGroups())
                $docgrp= implode(",", $docgrp);

            $docCount= count($docs);
            for ($i= 0; $i < $docCount; $i++) {

                $tvs= array ();
                $docRow= $docs[$i];
                $docid= $docRow['id'];

                $sql= "SELECT $fields, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
                $sql .= "FROM " . $this->getFullTableName('site_tmplvars') . " tv ";
                $sql .= "INNER JOIN " . $this->getFullTableName('site_tmplvar_templates')." tvtpl ON tvtpl.tmplvarid = tv.id ";
                $sql .= "LEFT JOIN " . $this->getFullTableName('site_tmplvar_contentvalues')." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '" . $docid . "' ";
                $sql .= "WHERE " . $query . " AND tvtpl.templateid = " . $docRow['template'];
                if ($tvsort)
                    $sql .= " ORDER BY $tvsort $tvsortdir ";
                $rs= $this->db->query($sql);
                $limit= @ $this->db->getRecordCount($rs);
                for ($x= 0; $x < $limit; $x++) {
                    array_push($tvs, @ $this->db->getRow($rs));
                }

                // get default/built-in template variables
                ksort($docRow);
                foreach ($docRow as $key => $value) {
                    if ($tvidnames == "*" || in_array($key, $tvidnames))
                        array_push($tvs, array (
                            "name" => $key,
                            "value" => $value
                        ));
                }

                if (count($tvs))
                    array_push($result, $tvs);
            }
            return $result;
        }
    }

    /**
     * Get the TV outputs of a document's children.
     * 
     * Returns an array where each element represents one child doc and contains the result from getTemplateVarOutput()
     *
     * Ignores deleted children. Gets all children - there is no where clause available.
     *
     * @param int $parentid The parent docid
     *                        Default: 0 (site root)
     * @param array $tvidnames. Which TVs to fetch. In the form expected by getTemplateVarOutput().
     *                        Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                        Default: 1
     * @param string $docsort How to sort the result array (field)
     *                        Default: menuindex
     * @param ASC $docsortdir How to sort the result array (direction)
     *                        Default: ASC
     * @return boolean|array
     */
    function getDocumentChildrenTVarOutput($parentid= 0, $tvidnames= array (), $published= 1, $docsort= "menuindex", $docsortdir= "ASC") {
        $docs= $this->getDocumentChildren($parentid, $published, 0, '*', '', $docsort, $docsortdir);
        if (!$docs)
            return false;
        else {
            $result= array ();
            for ($i= 0; $i < count($docs); $i++) {
                $tvs= $this->getTemplateVarOutput($tvidnames, $docs[$i]["id"], $published);
                if ($tvs)
                    $result[$docs[$i]['id']]= $tvs; // Use docid as key - netnoise 2006/08/14
            }
            return $result;
        }
    }

    /**
     * Get the TVs that belong to a template
     *
     * @param int $template
     * @return array
     */
    function getTemplateTVs($template)
        {
        $rs = $this->db->query('SELECT tv.*
                                    FROM '.$this->getFullTableName('site_tmplvars').' tv 
                                    INNER JOIN '.$this->getFullTableName('site_tmplvar_templates').' tvtpl ON tvtpl.tmplvarid = tv.id 
                                    WHERE tvtpl.templateid = '.$template);
        return $this->db->makeArray($rs);
        }


    /**
     * Modified by Raymond for TV - Orig Modified by Apodigm - DocVars
     * Returns a single site_content field or TV record from the db.
     *
     * If a site content field the result is an associative array of 'name' and 'value'.
     *
     * If a TV the result is an array representing a db row including the fields specified in $fields.
     *
     * @param string $idname Can be a TV id or name
     * @param string $fields Fields to fetch from site_tmplvars. Default: *
     * @param type $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                        Default: 1
     * @return boolean
     */
    function getTemplateVar($idname= "", $fields= "*", $docid= "", $published= 1) {
        if ($idname == "") {
            return false;
        } else {
            $result= $this->getTemplateVars(array ($idname), $fields, $docid, $published, "", ""); //remove sorting for speed
            return ($result != false) ? $result[0] : false;
        }
    }

    /**
     * Returns an array of site_content field fields and/or TV records from the db
     *
     * Elements representing a site content field consist of an associative array of 'name' and 'value'.
     *
     * Elements representing a TV consist of an array representing a db row including the fields specified in $fields.
     *
     * @param array $idnames Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                        Default: Empty array
     * @param string $fields Fields to fetch from site_tmplvars.
     *                        Default: *
     * @param string $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                        Default: 1
     * @param string $sort How to sort the result array (field)
     *                        Default: rank
     * @param string $dir How to sort the result array (direction)
     *                        Default: ASC
     * @return boolean|array
     */
    function getTemplateVars($idnames= array (), $fields= "*", $docid= "", $published= 1, $sort= "rank", $dir= "ASC") {
        if (($idnames != '*' && !is_array($idnames)) || count($idnames) == 0) {
            return false;
        } else {
            $result= array ();

            // get document record
            if ($docid == "") {
                $docid= $this->documentIdentifier;
                $docRow= $this->documentObject;
            } else {
                $docRow= $this->getDocument($docid, '*', $published);
                if (!$docRow)
                    return false;
            }

            // Get TVs
            $fields= ($fields == "") ? "tv.*" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $fields)));
            $sort= ($sort == "") ? "" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $sort)));
            if ($idnames == "*")
                $query= "tv.id<>0";
            else
                $query= (is_numeric($idnames[0]) ? "tv.id" : "tv.name") . " IN ('" . implode("','", $idnames) . "')";

            $sql= "SELECT $fields, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
            $sql .= "FROM " . $this->getFullTableName('site_tmplvars')." tv ";
            $sql .= "INNER JOIN " . $this->getFullTableName('site_tmplvar_templates')." tvtpl ON tvtpl.tmplvarid = tv.id ";
            $sql .= "LEFT JOIN " . $this->getFullTableName('site_tmplvar_contentvalues')." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '" . $docid . "' ";
            $sql .= "WHERE " . $query . " AND tvtpl.templateid = " . $docRow['template'];
            if ($sort)
                $sql .= " ORDER BY $sort $dir ";
            $rs= $this->db->query($sql);
            for ($i= 0; $i < @ $this->db->getRecordCount($rs); $i++) {
                array_push($result, @ $this->db->getRow($rs));
            }

            // Get fields from site_content
            ksort($docRow);
            foreach ($docRow as $key => $value) {
                if ($idnames == "*" || in_array($key, $idnames))
                    array_push($result, array (
                        "name" => $key,
                        "value" => $value
                    ));
            }

            return $result;
        }
    }

    /**
     * Returns an associative array containing TV rendered output values.
     *
     * @param type $idnames Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                        Default: Empty array
     * @param string $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result. 0 or DP_PUB_UNPUBLISHED, 1 or DP_PUB_PUBLISHED or DP_PUB_ALL.
     *                        Default: 1
     * @param string $sep
     * @return boolean|array
     */
    function getTemplateVarOutput($idnames= array (), $docid= "", $published= 1, $sep='') {
        if (count($idnames) == 0) {
            return false;
        } else {
            $output= array ();
            $vars= ($idnames == '*' || is_array($idnames)) ? $idnames : array ($idnames);
            $docid= intval($docid) ? intval($docid) : $this->documentIdentifier;
            $result= $this->getTemplateVars($vars, "*", $docid, $published, "", "", $sep); // remove sort for speed
            if ($result == false)
                return false;
            else {
		$baspath= $this->config["base_path"] . "manager/includes";
		include_once $baspath . "/tmplvars.format.inc.php";
		include_once $baspath . "/tmplvars.commands.inc.php";
		for ($i= 0; $i < count($result); $i++) {
			$row= $result[$i];
			if (!$row['id'])
				$output[$row['name']]= $row['value'];
			else	$output[$row['name']]= getTVDisplayFormat($row['name'], $row['value'], $row['display'], $row['display_params'], $row['type'], $docid, $sep);
		}
		return $output;
            }
        }
    }

    /**
     * Returns the placeholder value
     *
     * @param string $name Placeholder name
     * @return string Placeholder value
     */
    function getPlaceholder($name) {
        return $this->placeholders[$name];
    }

    /**
     * Sets a value for a placeholder
     *
     * @param string $name The name of the placeholder
     * @param string $value The value of the placeholder
     */
    function setPlaceholder($name, $value) {
        $this->placeholders[$name]= $value;
    }

    /**
     * Set placeholders en masse via an array or object.
     *
     * @param object|array $subject
     * @param string $prefix
     */
    function toPlaceholders($subject, $prefix= '') {
        if (is_object($subject)) {
            $subject= get_object_vars($subject);
        }
        if (is_array($subject)) {
            foreach ($subject as $key => $value) {
                $this->toPlaceholder($key, $value, $prefix);
            }
        }
    }

    /**
     * For use by toPlaceholders(); For setting an array or object element as placeholder.
     *
     * @param string $key
     * @param object|array $value
     * @param string $prefix
     */
    function toPlaceholder($key, $value, $prefix= '') {
        if (is_array($value) || is_object($value)) {
            $this->toPlaceholders($value, "{$prefix}{$key}.");
        } else {
            $this->setPlaceholder("{$prefix}{$key}", $value);
        }
    }

    /**
     * Returns the manager relative URL/path with respect to the site root.
     *
     * @return string The complete URL to the manager folder
     */
    function getManagerPath() {
        global $base_url;
        $pth= $base_url . 'manager/';
        return $pth;
    }

    /**
     * Returns the cache relative URL/path with respect to the site root.
     *
     * @return string The complete URL to the cache folder
     */
    function getCachePath() {
        global $base_url;
        $pth= $base_url . 'assets/cache/';
        return $pth;
    }

    /**
     * Sends a message to a user's message box.
     *
     * @param string $type Type of the message
     * @param string $to The recipient of the message
     * @param string $from The sender of the message
     * @param string $subject The subject of the message
     * @param string $msg The message body
     * @param int $private Whether it is a private message, or not
     *                     Default : 0
     */
    function sendAlert($type, $to, $from, $subject, $msg, $private= 0) {
        $private= ($private) ? 1 : 0;
        if (!is_numeric($to)) {
            // Query for the To ID
            $sql= "SELECT id FROM " . $this->getFullTableName("manager_users") . " WHERE username='$to';";
            $rs= $this->db->query($sql);
            if ($this->db->getRecordCount($rs)) {
                $rs= $this->db->getRow($rs);
                $to= $rs['id'];
            }
        }
        if (!is_numeric($from)) {
            // Query for the From ID
            $sql= "SELECT id FROM " . $this->getFullTableName("manager_users") . " WHERE username='$from';";
            $rs= $this->db->query($sql);
            if ($this->db->getRecordCount($rs)) {
                $rs= $this->db->getRow($rs);
                $from= $rs['id'];
            }
        }
        // insert a new message into user_messages
        $sql= "INSERT INTO " . $this->getFullTableName("user_messages") . " ( id , type , subject , message , sender , recipient , private , postdate , messageread ) VALUES ( '', '$type', '$subject', '$msg', '$from', '$to', '$private', '" . time() . "', '0' );";
        $rs= $this->db->query($sql);
    }

    /**
     * Returns true, install or interact when inside manager.
     *
     * @deprecated
     * @return string
     */
    function insideManager() {
        $m= false;
        if (defined('IN_MANAGER_MODE') && IN_MANAGER_MODE == 'true') {
            $m= true;
            if (defined('SNIPPET_INTERACTIVE_MODE') && SNIPPET_INTERACTIVE_MODE == 'true')
                $m= "interact";
            else
                if (defined('SNIPPET_INSTALL_MODE') && SNIPPET_INSTALL_MODE == 'true')
                    $m= "install";
        }
        return $m;
    }

    /**
     * Returns current user id.
     *
     * @param string $context. Default is an empty string which indicates the method should automatically pick 'web (frontend) or 'mgr' (backend)
     * @return string
     */
    function getLoginUserID($context= '') {
        if ($context && isset ($_SESSION[$context . 'Validated'])) {
            return $_SESSION[$context . 'InternalKey'];
        }
        elseif ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return $_SESSION['webInternalKey'];
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return $_SESSION['mgrInternalKey'];
        }
    }

    /**
     * Returns current user name
     *
     * @param string $context. Default is an empty string which indicates the method should automatically pick 'web (frontend) or 'mgr' (backend)
     * @return string
     */
    function getLoginUserName($context= '') {
        if (!empty($context) && isset ($_SESSION[$context . 'Validated'])) {
            return $_SESSION[$context . 'Shortname'];
        }
        elseif ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return $_SESSION['webShortname'];
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return $_SESSION['mgrShortname'];
        }
    }

    /**
     * Returns current login user type - web or manager
     *
     * @return string
     */
    function getLoginUserType() {
        if ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return 'web';
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return 'manager';
        } else {
            return '';
        }
    }

    /**
     * Returns a user info record for the given manager user
     *
     * @param int $uid
     * @return boolean|string
     */
    function getUserInfo($uid) {
        $sql= "
              SELECT mu.username, mu.password, mua.*
              FROM " . $this->getFullTableName("manager_users") . " mu
              INNER JOIN " . $this->getFullTableName("user_attributes") . " mua ON mua.internalkey=mu.id
              WHERE mu.id = '$uid'
              ";
        $rs= $this->db->query($sql);
        $limit= $this->db->getRecordCount($rs);
        if ($limit == 1) {
            $row= $this->db->getRow($rs);
            if (!$row["usertype"])
                $row["usertype"]= "manager";
            return $row;
        }
    }

    /**
     * Returns a record for the web user
     *
     * @param int $uid
     * @return boolean|string
     */
    function getWebUserInfo($uid) {
        $sql= "
              SELECT wu.username, wu.password, wua.*
              FROM " . $this->getFullTableName("web_users") . " wu
              INNER JOIN " . $this->getFullTableName("web_user_attributes") . " wua ON wua.internalkey=wu.id
              WHERE wu.id='$uid'
              ";
        $rs= $this->db->query($sql);
        $limit= $this->db->getRecordCount($rs);
        if ($limit == 1) {
            $row= $this->db->getRow($rs);
            if (!$row["usertype"])
                $row["usertype"]= "web";
            return $row;
        }
    }

    /**
     * Returns an array of document groups that current user is assigned to.
     * This function will first return the web user doc groups when running from
     * frontend otherwise it will return manager user's docgroup.
     *
     * @param boolean $resolveIds Set to true to return the document group names
     *                            Default: false
     * @return string|array
     */
     function getUserDocGroups($resolveIds= false) {
        if ($this->isFrontend() && isset ($_SESSION['webDocgroups']) && isset ($_SESSION['webValidated'])) {
            $dg= $_SESSION['webDocgroups'];
            $dgn= isset ($_SESSION['webDocgrpNames']) ? $_SESSION['webDocgrpNames'] : false;
        } else
            if ($this->isBackend() && isset ($_SESSION['mgrDocgroups']) && isset ($_SESSION['mgrValidated'])) {
                $dg= $_SESSION['mgrDocgroups'];
                $dgn= $_SESSION['mgrDocgrpNames'];
            } else {
                $dg= '';
            }
        if (!$resolveIds)
            return $dg;
        else
            if (is_array($dgn))
                return $dgn;
            else
                if (is_array($dg)) {
                    // resolve ids to names
                    $dgn= array ();
                    $tbl= $this->getFullTableName("documentgroup_names");
                    $ds= $this->db->query("SELECT name FROM $tbl WHERE id IN (" . implode(",", $dg) . ")");
                    while ($row= $this->db->getRow($ds))
                        $dgn[count($dgn)]= $row['name'];
                    // cache docgroup names to session
                    if ($this->isFrontend())
                        $_SESSION['webDocgrpNames']= $dgn;
                    else
                        $_SESSION['mgrDocgrpNames']= $dgn;
                    return $dgn;
                }
    }

    /**
     * Returns an array of document groups that current user is assigned to.
     * This function will first return the web user doc groups when running from
     * frontend otherwise it will return manager user's docgroup.
     *
     * @deprecated
     * @return string|array
     */
    function getDocGroups() {
        return $this->getUserDocGroups();
    } // deprecated

    /**
     * Change current web user's password
     *
     * @todo Make password length configurable, allow rules for passwords and translation of messages
     * @param string $oldPwd
     * @param string $newPwd
     * @return string|boolean Returns true if successful, oterhwise return error
     *                        message
     */
    function changeWebUserPassword($oldPwd, $newPwd) {
        $rt= false;
        if ($_SESSION["webValidated"] == 1) {
            $tbl= $this->getFullTableName("web_users");
            $ds= $this->db->query("SELECT `id`, `username`, `password` FROM $tbl WHERE `id`='" . $this->getLoginUserID() . "'");
            $limit= $this->db->getRecordCount($ds);
            if ($limit == 1) {
                $row= $this->db->getRow($ds);
                if ($row["password"] == md5($oldPwd)) {
                    if (strlen($newPwd) < 6) {
                        return "Password is too short!";
                    }
                    elseif ($newPwd == "") {
                        return "You didn't specify a password for this user!";
                    } else {
                        $this->db->query("UPDATE $tbl SET password = md5('" . $this->db->escape($newPwd) . "') WHERE id='" . $this->getLoginUserID() . "'");
                        // invoke OnWebChangePassword event
                        $this->invokeEvent("OnWebChangePassword", array (
                            "userid" => $row["id"],
                            "username" => $row["username"],
                            "userpassword" => $newPwd
                        ));
                        return true;
                    }
                } else {
                    return "Incorrect password.";
                }
            }
        }
    }

    /**
     * Change current web user's password
     *
     * @deprecated
     * @param string $o
     * @param string $n
     * @return string|boolean
     */
    function changePassword($o, $n) {
        return changeWebUserPassword($o, $n);
    } // deprecated

    /**
     * Returns true if the current web user is a member the specified groups
     *
     * @param array $groupNames
     * @return boolean
     */
    function isMemberOfWebGroup($groupNames= array ()) {
        if (!is_array($groupNames))
            return false;
        // check cache
        $grpNames= isset ($_SESSION['webUserGroupNames']) ? $_SESSION['webUserGroupNames'] : false;
        if (!is_array($grpNames)) {
            $tbl= $this->getFullTableName("webgroup_names");
            $tbl2= $this->getFullTableName("web_groups");
            $sql= "SELECT wgn.name
                    FROM $tbl wgn
                    INNER JOIN $tbl2 wg ON wg.webgroup=wgn.id AND wg.webuser='" . $this->getLoginUserID() . "'";
            $grpNames= $this->db->getColumn("name", $sql);
            // save to cache
            $_SESSION['webUserGroupNames']= $grpNames;
        }
        foreach ($groupNames as $k => $v)
            if (in_array(trim($v), $grpNames))
                return true;
        return false;
    }

    /**
     * Registers Client-side CSS scripts - these scripts are loaded at inside
     * the <head> tag
     *
     * @param string $src
     * @param string $media Default: Empty string
     */
    function regClientCSS($src, $media='') {
        if (empty($src) || isset ($this->loadedjscripts[$src]))
            return '';
        $nextpos= max(array_merge(array(0),array_keys($this->sjscripts)))+1;
        $this->loadedjscripts[$src]['startup']= true;
        $this->loadedjscripts[$src]['version']= '0';
        $this->loadedjscripts[$src]['pos']= $nextpos;
        if (strpos(strtolower($src), "<style") !== false || strpos(strtolower($src), "<link") !== false) {
            $this->sjscripts[$nextpos]= $src;
        } else {
            $this->sjscripts[$nextpos]= "\t" . '<link rel="stylesheet" type="text/css" href="'.$src.'" '.($media ? 'media="'.$media.'" ' : '').'/>';
        }
    }

    /**
     * Registers Startup Client-side JavaScript - these scripts are loaded at inside the <head> tag
     *
     * @param string $src
     * @param array $options Default: 'name'=>'', 'version'=>'0', 'plaintext'=>false
     */
    function regClientStartupScript($src, $options= array('name'=>'', 'version'=>'0', 'plaintext'=>false)) {
        $this->regClientScript($src, $options, true);
    }

    /**
     * Registers Client-side JavaScript these scripts are loaded at the end of the page unless $startup is true
     *
     * @param string $src
     * @param array $options Default: 'name'=>'', 'version'=>'0', 'plaintext'=>false
     * @param boolean $startup Default: false
     * @return string
     */
    function regClientScript($src, $options= array('name'=>'', 'version'=>'0', 'plaintext'=>false), $startup= false) {
        if (empty($src))
            return ''; // nothing to register
        if (!is_array($options)) {
            if (is_bool($options))  // backward compatibility with old plaintext parameter
                $options=array('plaintext'=>$options);
            elseif (is_string($options)) // Also allow script name as 2nd param
                $options=array('name'=>$options);
            else
                $options=array();
        }
        $name= isset($options['name']) ? strtolower($options['name']) : '';
        $version= isset($options['version']) ? $options['version'] : '0';
        $plaintext= isset($options['plaintext']) ? $options['plaintext'] : false;
        $key= !empty($name) ? $name : $src;
        $jquery_core = preg_match('/(^|\/)jquery(-\d+\.\d+(\.\d+)?)?(\.min)?\.js$/i', $src) ? true : false;
        $jquery = (isset($options['jquery']) && $options['jquery']) || $jquery_core ? true : false;
        unset($overwritepos); // probably unnecessary--just making sure

        $useThisVer= true;
        if (isset($this->loadedjscripts[$key])) { // a matching script was found
            // if existing script is a startup script, make sure the candidate is also a startup script
            if ($this->loadedjscripts[$key]['startup'])
                $startup= true;

            if (empty($name)) {
                $useThisVer= false; // if the match was based on identical source code, no need to replace the old one
            } else {
                $useThisVer = version_compare($this->loadedjscripts[$key]['version'], $version, '<');
            }

            if ($useThisVer) {
                if ($startup==true && $this->loadedjscripts[$key]['startup']==false) {
                    // remove old script from the bottom of the page (new one will be at the top)
                    unset($this->jscripts[$this->loadedjscripts[$key]['pos']]);
                } else {
                    // overwrite the old script (the position may be important for dependent scripts)
                    $overwritepos= $this->loadedjscripts[$key]['pos'];
                }
            } else { // Use the original version
                if ($startup==true && $this->loadedjscripts[$key]['startup']==false) {
                    // need to move the exisiting script to the head
                    $version= $this->loadedjscripts[$key][$version];
                    $src= $this->jscripts[$this->loadedjscripts[$key]['pos']];
                    unset($this->jscripts[$this->loadedjscripts[$key]['pos']]);
                } else {
                    return ''; // the script is already in the right place
                }
            }
        }

        if ($useThisVer && $plaintext!=true && (strpos(strtolower($src), "<script") === false))
            $src= "\t" . '<script type="text/javascript" src="' . $src . '"></script>';
            
        if ($jquery) {
            if (!$jquery_core || empty($this->jquery_scripts)) {
                $pos= isset($overwritepos) ? $overwritepos : max(array_merge(array(0),array_keys($this->jquery_scripts)))+1;
                $this->jquery_scripts[$pos]= $src;
            }
        } elseif ($startup) {
            $pos= isset($overwritepos) ? $overwritepos : max(array_merge(array(0),array_keys($this->sjscripts)))+1;
            $this->sjscripts[$pos]= $src;
        } else {
            $pos= isset($overwritepos) ? $overwritepos : max(array_merge(array(0),array_keys($this->jscripts)))+1;
            $this->jscripts[$pos]= $src;
        }
        $this->loadedjscripts[$key]['version']= $version;
        $this->loadedjscripts[$key]['startup']= $startup;
        $this->loadedjscripts[$key]['pos']= $pos;
    }
    
    /**
     * Register jQuery core script
     */
    function regClientJquery() {
    
        static $jquery_included = false;
        
        if (!$jquery_included) {
            $this->regClientStartupScript($this->config['jquery_url'], array('jquery'=>true));
            $jquery_included = true;
        }
    }
    
    /**
     * Register jquery plugin
     *
     * @param string $plugin_name Plugin name, use the name most likely to be used by other scripts (case insensitive)
     * @param string $plugin_file Plugin URL. Relative to plugin directory if $use_plugin_dir is true
     * @param string $plugin_version
     * @param bool $use_plugin_dir See above, defaults to true
     */
   function regClientJqueryPlugin($plugin_name, $plugin_file, $plugin_version = 0, $use_plugin_dir = true) {
   		if ($use_plugin_dir) {
   			$plugin_file = $this->config['jquery_plugin_dir'].$plugin_file;
   		}
       	$this->regClientStartupScript($plugin_file, array('name'=>$plugin_name, $plugin_version, 'plaintext'=>false, 'jquery'=>true));
   }
   
   /**
    * Get jquery <script> tag as HTML.
    *
    * Intended for use in the backend. Use the above methods for the frontend.
    *
    * Returns script tag with full absolute URL, so suitable for all manager pages including any without a <base> tag.
    *
    * @param bool $only_once If true, only return the script tag if we haven't already done so
    */
   function getJqueryTag($only_once = true) {
   
   		static $run_once = false;
   		
   		if (!$run_once || !$only_once) {
   			$jq_url = $this->config['jquery_url'];
   			
   			// Check the file exists. If a remote file, be lazy and do not check - simply use the default packaged file anyway.
   			if ($this->isBackend() && (substr($jq_url, 0, 4) == 'http' || !is_file($this->config['base_path'].$jq_url))) {
   			    $jq_url = $this->config['site_url'].'assets/js/jquery.min.js';
   			}

   			if ($jq_url[0] == '/') {
   				$jq_url = $this->config['site_url'].substr($this->config['jquery_url'], 1);
   			} elseif (substr($jq_url, 0, 4) != 'http') {
   				$jq_url = $this->config['site_url'].$jq_url;
   			}
   			$script_tag = '<script type="text/javascript" src="'.str_replace('&', '&amp;', $jq_url)."\"></script>\n";
   		} else {
   			$script_tag = '';
   		}

		$run_once = true;
		
		return $script_tag; 		
   }

    /**
     * Get jquery plugin <script> tag as HTML.
     *
     * Currently used plugin names:
     *  - jquery-ui-custom-clippermanager The custom jquery-ui file for the Clipper manager
     *  - jquery-ui-timepicker
     *  - jquery-datatables
     *  - jquery-validate (http://jqueryvalidation.org/)
     *
     * @param string $plugin_name Plugin name, use the name most likely to be used by other scripts (case insensitive)
     * @param string $plugin_file Plugin URL. Relative to plugin directory if $use_plugin_dir is true
     * @param bool $use_plugin_dir See above, defaults to true
     */
   function getJqueryPluginTag($plugin_name, $plugin_file, $use_plugin_dir = true, $only_once = true) {

   		static $plugin_names = array();
   		
   		if (!in_array($plugin_name, $plugin_names) || !$only_once) {
   		    $plugin_names[] = $plugin_name;
   			if ($use_plugin_dir) {
   			    $plugin_file = $this->config['site_url'].$this->config['jquery_plugin_dir'].$plugin_file; // Need [(site_url)] because <base> tags are not present in the backend.
            }
            $plugin_file = ($plugin_file[0] == '/') ? $this->config['site_url'].substr($plugin_file, 1) : $plugin_file;
            $script_tag = '<script type="text/javascript" src="'.str_replace('&', '&amp;', $plugin_file)."\"></script>\n";
   		} else {
   			$script_tag = '';
   		}

		return $script_tag; 		
   }

    /**
     * Registers Client-side Startup HTML block
     *
     * @param string $html
     */
    function regClientStartupHTMLBlock($html) {
        $this->regClientScript($html, true, true);
    }

    /**
     * Registers Client-side HTML block
     *
     * @param string $html
     */
    function regClientHTMLBlock($html) {
        $this->regClientScript($html, true);
    }

    /**
     * Returns all registered JavaScripts
     *
     * @return string
     */
    function getRegisteredClientScripts() {
        return implode("\n", $this->jscripts);
    }

    /**
     * Returns all registered startup scripts
     *
     * @return string
     */
    function getRegisteredClientStartupScripts() {
        $output = '';
        if (!empty($this->jquery_scripts)) {
            $pos1 = strpos($this->documentOutput, '<head>') + 6;
            $pos2 = strpos($this->documentOutput, '</head>');
            if ($pos1 !== false && $pos2 !== false) {
                $head = substr($this->documentOutput, $pos1, $pos2 - $pos1);
                // First entry must be the core - look for any version of jquery before adding another
                if (!preg_match('/jquery(-\d+\.\d+(\.\d+)?)?(\.min)?\.js/i', $head)) {
                    $output .= $this->jquery_scripts[1]."\n";
                    if ($this->config['jquery_noconflict']) {
                        $output .= "<script type=\"text/javascript\">jQuery.noConflict()</script>\n";
                    }
                }
                for($i = 2; $i <= sizeof($this->jquery_scripts); ++$i) {
                    // Further entries must be plugins - look for filename, minified or otherwise.
                    $filename = substr($this->jquery_scripts[$i], strpos($this->jquery_scripts[$i], 'src="')+5);
                    $filename = preg_replace('/(\.min)?\.js.*$/', '', $filename);
                    if (strpos($head, $filename.'.js') === false && strpos($head, $filename.'.min.js') === false) {
                        $output .= $this->jquery_scripts[$i]."\n";
                    }
                }
            }
        }
        return $output.implode("\n", $this->sjscripts);
    }
    
    /**
     * Remove unwanted html tags and snippet, settings and tags
     *
     * @param string $html
     * @param string $allowed Default: Empty string
     * @return string
     */
    function stripTags($html, $allowed= "") {
        $t= strip_tags($html, $allowed);
        $t= preg_replace('~\[\*(.*?)\*\]~', "", $t); //tv
        $t= preg_replace('~\[\[(.*?)\]\]~', "", $t); //snippet
        $t= preg_replace('~\[\!(.*?)\!\]~', "", $t); //snippet
        $t= preg_replace('~\[\((.*?)\)\]~', "", $t); //settings
        $t= preg_replace('~\[\+(.*?)\+\]~', "", $t); //placeholders
        $t= preg_replace('~{{(.*?)}}~', "", $t); //chunks

        $t= preg_replace('/(\[\*|\[\[|\[\!|\[\(|\[\+|\{\{|\*\]|\]\]|\!\]|\)\]|\}\})/', '', $t); // All half tags (TimGS)
        
        return $t;
    }

    /**
     * Format alias to be URL-safe. Strip invalid characters.
     *
     * @param string Alias to be formatted
     * @return string Safe alias
     */
    function stripAlias($alias) {
        // let add-ons overwrite the default behavior
        $results = $this->invokeEvent('OnStripAlias', array ('alias'=>$alias));
        if (!empty($results)) {
            // if multiple plugins are registered, only the last one is used
            return end($results);
        } else {
            // default behavior: strip invalid characters and replace spaces with dashes.
            $alias = strip_tags($alias); // strip HTML
            $alias = preg_replace('/[^\.A-Za-z0-9 _-]/', '', $alias); // strip non-alphanumeric characters
            $alias = preg_replace('/\s+/', '-', $alias); // convert white-space to dash
            $alias = preg_replace('/-+/', '-', $alias);  // convert multiple dashes to one
            $alias = trim($alias, '-'); // trim excess
            return $alias;
        }
    }

    /**
     * Add an event listner to a plugin - only for use within the current execution cycle
     *
     * @param string $evtName
     * @param string $pluginName
     * @return boolean|int
     */
    function addEventListener($evtName, $pluginName) {
	    if (!$evtName || !$pluginName)
		    return false;
	    if (!array_key_exists($evtName,$this->pluginEvent))
		    $this->pluginEvent[$evtName] = array();
	    return array_push($this->pluginEvent[$evtName], $pluginName); // return array count
    }

    /**
     * Remove event listner - only for use within the current execution cycle
     *
     * @param string $evtName
     * @return boolean
     */
    function removeEventListener($evtName) {
        if (!$evtName)
            return false;
        unset ($this->pluginEvent[$evtName]);
    }

    /**
     * Remove all event listners - only for use within the current execution cycle
     */
    function removeAllEventListener() {
        unset ($this->pluginEvent);
        $this->pluginEvent= array ();
    }

    /**
     * Invoke an event.
     *
     * @param string $evtName
     * @param array $extParams Parameters available to plugins. Each array key will be the PHP variable name, and the array value will be the variable value.
     * @return boolean|array
     */
    function invokeEvent($evtName, $extParams= array ()) {
        if (!$evtName)
            return false;
        if (!isset ($this->pluginEvent[$evtName]))
            return false;
        $el= $this->pluginEvent[$evtName];
        $results= array ();
        $numEvents= count($el);
        if ($numEvents > 0)
            for ($i= 0; $i < $numEvents; $i++) { // start for loop
                $pluginName= $el[$i];
                $pluginName = stripslashes($pluginName);
                // reset event object
                $e= & $this->Event;
                $e->_resetEventObject();
                $e->name= $evtName;
                $e->activePlugin= $pluginName;

                // get plugin code
                if (isset ($this->pluginCache[$pluginName])) {
                    $pluginCode= $this->pluginCache[$pluginName];
                    $pluginProperties= $this->pluginCache[$pluginName . "Props"];
                } else {
                    $sql= "SELECT `name`, `plugincode`, `properties` FROM " . $this->getFullTableName("site_plugins") . " WHERE `name`='" . $pluginName . "' AND `disabled`=0;";
                    $result= $this->db->query($sql);
                    if ($this->db->getRecordCount($result) == 1) {
                        $row= $this->db->getRow($result);
                        $pluginCode= $this->pluginCache[$row['name']]= $row['plugincode'];
                        $pluginProperties= $this->pluginCache[$row['name'] . "Props"]= $row['properties'];
                    } else {
                        $pluginCode= $this->pluginCache[$pluginName]= null;
                        $pluginProperties= '';
                    }
                }

                // load default params/properties
                $parameter= $this->parseProperties($pluginProperties);
                if (!empty ($extParams))
                    $parameter= array_merge($parameter, $extParams);

                // eval plugin
                $this->evalPlugin($pluginCode, $parameter);
                if ($e->_output != "")
                    $results[]= $e->_output;
                if ($e->_propagate != true)
                    break;
            }
        $e->activePlugin= "";
        return $results;
    }

    /**
     * Parses a resource property string and returns the result as an array
     *
     * @param string $propertyString
     * @return array Associative array in the form property name => property value
     */
    function parseProperties($propertyString) {
        $parameter= array ();
        if (!empty ($propertyString)) {
            $tmpParams= explode("&", $propertyString);
            for ($x= 0; $x < count($tmpParams); $x++) {
                if (strpos($tmpParams[$x], '=', 0)) {
                    $pTmp= explode("=", $tmpParams[$x]);
                    $pvTmp= explode(";", trim($pTmp[1]));
                    if ($pvTmp[1] == 'list' && $pvTmp[3] != "")
                        $parameter[trim($pTmp[0])]= $pvTmp[3]; //list default
                    else
                        if ($pvTmp[1] != 'list' && $pvTmp[2] != "")
                            $parameter[trim($pTmp[0])]= $pvTmp[2];
                }
            }
        }
        return $parameter;
    }

    /**
     * Set PHP error handlers
     * 
     * @return void
     */
    function set_error_handler()
        {
        if (version_compare(PHP_VERSION, '5.3.0') >= 0)
            {
            // PHP 5.3
            set_error_handler(array (&$this, 'phpError'), (error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED) | ($this->config['error_handling_deprecated'] ? E_DEPRECATED | E_USER_DEPRECATED : 0));
            }
        else
            {
            set_error_handler(array (&$this, 'phpError'), error_reporting());
            }
        
        register_shutdown_function(array(&$this, 'fatalErrorCheck'));
        }

    /**
     * PHP error handler set by http://www.php.net/manual/en/function.set-error-handler.php
     *
     * Checks the PHP error and calls messageQuit() unless:
     *	- error_reporting() returns 0, or
     *  - the PHP error level is 0, or
     *  - the PHP error level is 8 (E_NOTICE) and stopOnNotice is false
     *
     * @param int $nr The PHP error level as per http://www.php.net/manual/en/errorfunc.constants.php
     * @param string $text Error message
     * @param string $file File where the error was detected
     * @param string $line Line number within $file
     * @return boolean
     */
    function phpError($nr, $text, $file, $line) {
        if (error_reporting() == 0 || $nr == 0 || ($nr == 8 && $this->stopOnNotice == false)) {
            return true;
        }
        
        if (strpos($file, '/document.parser.class.inc.php') !== false) {
        	$file = 'DocumentParser'.(strpos($file, 'eval()\'d code') === false ? '' : ' eval\'d code').($this->eval_type ? " in {$this->eval_type} {$this->eval_name}" : '');
        }
        
        if (version_compare(PHP_VERSION, '5.3.0') >= 0 && ($nr & (E_DEPRECATED | E_USER_DEPRECATED))) { // TimGS. Handle deprecated functions according to config.
                switch ($this->config['error_handling_deprecated']) {
                        case 1:
                        	if ($this->eval_type) {
	                        	$this->logEvent(29,2,$text.'; File: '.$file.'; Line: '.$line, "{$this->eval_type} {$this->eval_name}");
	                        } else {
	                        	$this->logEvent(29,2,$text.'; File: '.$file.'; Line: '.$line);
	                        }
                        case 0:
                                return true;
                }
        }
        
        if (is_readable($file)) {
            $source= file($file);
            $source= htmlspecialchars($source[$line -1]);
        } else {
            $source= "";
        } //Error $nr in $file at $line: <div><code>$source</code></div>

		if ($this->eval_type) {
        	$this->messageQuitFromElement("{$this->eval_type} {$this->eval_name}", 'PHP Parse Error', '', true, $nr, $file, $source, $text, $line);
        } else {
        	$this->messageQuit('PHP Parse Error', '', true, $nr, $file, $source, $text, $line);
        }
    }

    /**
     * Generate display body for messageQuit()
     * 
     * @param string $msg Default: unspecified error
     * @param string $query Default: Empty string
     * @param boolean $is_error Default: true
     * @param string $nr Default: Empty string
     * @param string $file Default: Empty string
     * @param string $source Default: Empty string
     * @param string $text Default: Empty string
     * @param string $line Default: Empty string
     */
     function messageQuitText($msg= 'unspecified error', $query= '', $is_error= true, $nr= '', $file= '', $source= '', $text= '', $line= '') {
     
        $version= isset ($GLOBALS['version']) ? $GLOBALS['version'] : '';
        $release_date= isset ($GLOBALS['release_date']) ? $GLOBALS['release_date'] : '';
        $parsedMessageString= "
              <html><head><title>".CMS_NAME." Content Manager $version &raquo; $release_date</title>
              <style>TD, BODY { font-size: 11px; font-family:verdana; }</style>
              </head><body>";

        if ($is_error) {
            $parsedMessageString .= "<h3 style='color:red'>&laquo; ".CMS_NAME." Parse Error &raquo;</h3>
                    <table border='0' cellpadding='1' cellspacing='0'>
                    <tr><td colspan='3'>".CMS_NAME." encountered the following error while attempting to parse the requested resource:</td></tr>
                    <tr><td colspan='3'><b style='color:red;'>&laquo; $msg &raquo;</b></td></tr>";
        } else {
            $parsedMessageString .= "<h3 style='color:#003399'>&laquo; ".CMS_NAME." Debug/ stop message &raquo;</h3>
                    <table border='0' cellpadding='1' cellspacing='0'>
                    <tr><td colspan='3'>The ".CMS_NAME." parser recieved the following debug/ stop message:</td></tr>
                    <tr><td colspan='3'><b style='color:#003399;'>&laquo; $msg &raquo;</b></td></tr>";
        }

        if (!empty ($query)) {
            $parsedMessageString .= "<tr><td colspan='3'><b style='color:#999;font-size: 9px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;SQL:&nbsp;<span id='sqlHolder'>$query</span></b></td></tr>";
        }

        if ($text != '') {

            $errortype= array (
                E_ERROR => "Error",
                E_WARNING => "Warning",
                E_PARSE => "Parsing Error",
                E_NOTICE => "Notice",
                E_CORE_ERROR => "Core Error",
                E_CORE_WARNING => "Core Warning",
                E_COMPILE_ERROR => "Compile Error",
                E_COMPILE_WARNING => "Compile Warning",
                E_USER_ERROR => "User Error",
                E_USER_WARNING => "User Warning",
                E_USER_NOTICE => "User Notice",

            );

            $parsedMessageString .= "<tr><td>&nbsp;</td></tr><tr><td colspan='3'><b>PHP error debug</b></td></tr>";

            $parsedMessageString .= "<tr><td valign='top'>&nbsp;&nbsp;Error: </td>";
            $parsedMessageString .= "<td colspan='2'>$text</td><td>&nbsp;</td>";
            $parsedMessageString .= "</tr>";

            $parsedMessageString .= "<tr><td valign='top'>&nbsp;&nbsp;Error type/ Nr.: </td>";
            $parsedMessageString .= "<td colspan='2'>" . $errortype[$nr] . " - $nr</b></td><td>&nbsp;</td>";
            $parsedMessageString .= "</tr>";

            $parsedMessageString .= "<tr><td>&nbsp;&nbsp;File: </td>";
            $parsedMessageString .= "<td colspan='2'>$file</td><td>&nbsp;</td>";
            $parsedMessageString .= "</tr>";

            $parsedMessageString .= "<tr><td>&nbsp;&nbsp;Line: </td>";
            $parsedMessageString .= "<td colspan='2'>$line</td><td>&nbsp;</td>";
            $parsedMessageString .= "</tr>";
            if ($source != '') {
                $parsedMessageString .= "<tr><td valign='top'>&nbsp;&nbsp;Line $line source: </td>";
                $parsedMessageString .= "<td colspan='2'>$source</td><td>&nbsp;</td>";
                $parsedMessageString .= "</tr>";
            }
        }

        $parsedMessageString .= "<tr><td>&nbsp;</td></tr><tr><td colspan='3'><b>Parser timing</b></td></tr>";

        $parsedMessageString .= "<tr><td>&nbsp;&nbsp;MySQL: </td>";
        $parsedMessageString .= "<td><i>[^qt^]</i></td><td>(<i>[^q^] Requests</i>)</td>";
        $parsedMessageString .= "</tr>";

        $parsedMessageString .= "<tr><td>&nbsp;&nbsp;PHP: </td>";
        $parsedMessageString .= "<td><i>[^p^]</i></td><td>&nbsp;</td>";
        $parsedMessageString .= "</tr>";

        $parsedMessageString .= "<tr><td>&nbsp;&nbsp;Total: </td>";
        $parsedMessageString .= "<td><i>[^t^]</i></td><td>&nbsp;</td>";
        $parsedMessageString .= "</tr>";

        $parsedMessageString .= "</table>";
        $parsedMessageString .= "</body></html>";

        $totalTime= ($this->getMicroTime() - $this->tstart);
        $queryTime= $this->queryTime;
        $phpTime= $totalTime - $queryTime;
        $queries= isset ($this->executedQueries) ? $this->executedQueries : 0;
        $queryTime= sprintf("%2.4f s", $queryTime);
        $totalTime= sprintf("%2.4f s", $totalTime);
        $phpTime= sprintf("%2.4f s", $phpTime);

        $parsedMessageString= str_replace("[^q^]", $queries, $parsedMessageString);
        $parsedMessageString= str_replace("[^qt^]", $queryTime, $parsedMessageString);
        $parsedMessageString= str_replace("[^p^]", $phpTime, $parsedMessageString);
        $parsedMessageString= str_replace("[^t^]", $totalTime, $parsedMessageString);
        
        return $parsedMessageString;
        }

    /**
     * Error logging and output.
     * 
     * If error_handling_silent is 0, outputs an error page with detailed informations about the error.
     * Always logs the error using logEvent()
     *
     * @param string $msg Default: unspecified error
     * @param string $query Default: Empty string
     * @param boolean $is_error Default: true
     * @param string $nr Default: Empty string
     * @param string $file Default: Empty string
     * @param string $source Default: Empty string
     * @param string $text Default: Empty string
     * @param string $line Default: Empty string
     */
    function messageQuit($msg= 'unspecified error', $query= '', $is_error= true, $nr= '', $file= '', $source= '', $text= '', $line= '') {

		$parsedMessageString = $this->messageQuitText($msg, $query, $is_error, $nr, $file, $source, $text, $line);

        // Set 500 response header
        header('HTTP/1.1 500 Internal Server Error');

        // Display error
        if (!$this->config['error_handling_silent']) {
        	echo $parsedMessageString;
        }
        ob_end_flush();

        // Log error if a connection to the db exists
        if ($this->db->conn) {
             $this->logEvent(0, 3, $parsedMessageString, 'Parser');
        }

        // Make sure and die!
        exit();
    }

    /**
     * Error logging and output.
     * Takes an $element_name parameter (snippet or plugin name) for extra clarity in the System Events page.
     * 
     * If error_handling_silent is 0, outputs an error page with detailed informations about the error.
     * Always logs the error using logEvent()
     *
     * @param string $element_name Name of snippet or plugin
     * @param string $msg Default: unspecified error
     * @param string $query Default: Empty string
     * @param boolean $is_error Default: true
     * @param string $nr Default: Empty string
     * @param string $file Default: Empty string
     * @param string $source Default: Empty string
     * @param string $text Default: Empty string
     * @param string $line Default: Empty string
     */
    function messageQuitFromElement($element_name, $msg= 'unspecified error', $query= '', $is_error= true, $nr= '', $file= '', $source= '', $text= '', $line= '') {

        if (is_null($element_name)) {
            $element_name = "{$this->eval_type} {$this->eval_name}";
        }

		$parsedMessageString = $this->messageQuitText($msg, $query, $is_error, $nr, $file, $source, $text, $line);

        // Set 500 response header
        header('HTTP/1.1 500 Internal Server Error');

        // Display error
        if (!$this->config['error_handling_silent']) {
        	echo $parsedMessageString;
        }
        ob_end_flush();

        // Log error if a connection to the db exists
        if ($this->db->conn) {
             $this->logEvent(0, 3, $parsedMessageString, $element_name);
        }

        // Make sure and die!
        exit();
    }

    // End of class.

}

/**
 * System Event Class
 */
class SystemEvent {

    var $name;
    var $_propagate;
    var $_output;
    var $activated;
    var $activePlugin;

    /**
     * @param string $name Name of the event
     */
    function __construct($name= '') {
        $this->_resetEventObject();
        $this->name= $name;
    }

    /**
     * Display a message to the user
     *
     * @param string $msg The message
     */
    function alert($msg) {
        global $SystemAlertMsgQueque;
        if ($msg == "")
            return;
        if (is_array($SystemAlertMsgQueque)) {
            if ($this->name && $this->activePlugin)
                $title= "<div><b>" . $this->activePlugin . "</b> - <span style='color:maroon;'>" . $this->name . "</span></div>";
            $SystemAlertMsgQueque[]= "$title<div style='margin-left:10px;margin-top:3px;'>$msg</div>";
        }
    }

    /**
     * Output
     * 
     * @param string $msg 
     */
    function output($msg) {
        $this->_output .= $msg;
    }


    /** 
     * Stop event propogation
     */
    function stopPropagation() {
        $this->_propagate= false;
    }

    function _resetEventObject() {
        unset ($this->returnedValues);
        $this->name= "";
        $this->_output= "";
        $this->_propagate= true;
        $this->activated= false;
    }
}

