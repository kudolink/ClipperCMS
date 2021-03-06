<?php
if(!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE != 'true') exit();

$config_check_warningspresent = 0;

if (is_writable("includes/config.inc.php")){
    // Warn if world writable
    if(@fileperms('includes/config.inc.php') & 0x0002) {
      $config_check_warningspresent = 1;
      $config_check_warnings[] = array($_lang['configcheck_configinc']);
    }
}

if (file_exists("../install/")) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_installer']);
}

if (ini_get('register_globals')==TRUE) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_register_globals']);
}

if (isset($clipper_config['locale_lc_all'])) {
    $config_check_locales = setlocale(LC_ALL, 0);
    if (strpos($config_check_locales, ';') !== false) {
        // Locales differ across categories
        $config_check_locales = explode(';', $config_check_locales);
        foreach($config_check_locales as $locale) {
            $l = explode('=', $locale);
            if ($clipper_config['locale_lc_all'] != $l[1]
            		&& !(($clipper_config['locale_lc_all'] == 'POSIX' && $l[1] == 'C') || ($clipper_config['locale_lc_all'] == 'C' && $l[1] == 'POSIX'))
            		&& (!isset($clipper_config['locale_lc_numeric']) || $l[0] != 'LC_NUMERIC')) {
                $config_check_warnings[] = array($_lang['configcheck_locale_LC_ALL_warning']);
                break;
            }
        }
    } elseif ($config_check_locales != $clipper_config['locale_lc_all']) {
        $config_check_warnings[] = array($_lang['configcheck_locale_LC_ALL_warning']);
    }
}

if (isset($clipper_config['locale_lc_numeric']) && setlocale(LC_NUMERIC, 0) != $clipper_config['locale_lc_numeric']) {
    $config_check_warnings[] = array($_lang['configcheck_locale_LC_NUMERIC_warning']);
}

if (!extension_loaded('gd') || !extension_loaded('zip')) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_php_gdzip']);
}

if(!isset($modx->config['_hide_configcheck_validate_referer']) || $modx->config['_hide_configcheck_validate_referer'] !== '1') {
    if(isset($_SESSION['mgrPermissions']['settings']) && $_SESSION['mgrPermissions']['settings'] == '1') {
        if ($modx->db->getValue('SELECT COUNT(setting_value) FROM '.$modx->getFullTableName('system_settings').' WHERE setting_name=\'validate_referer\' AND setting_value=\'0\'')) {
            $config_check_warningspresent = 1;
            $config_check_warnings[] = array($_lang['configcheck_validate_referer']);
        }
    }
}

// check for Template Switcher plugin
if(!isset($modx->config['_hide_configcheck_templateswitcher_present']) || $modx->config['_hide_configcheck_templateswitcher_present'] !== '1') {
    if(isset($_SESSION['mgrPermissions']['edit_plugin']) && $_SESSION['mgrPermissions']['edit_plugin'] == '1') {
        $sql = "SELECT name, disabled FROM ".$modx->getFullTableName('site_plugins')." WHERE name IN ('TemplateSwitcher', 'Template Switcher', 'templateswitcher', 'template_switcher', 'template switcher') OR plugincode LIKE '%TemplateSwitcher%'";
        $rs = $modx->db->query($sql);
        $row = $modx->db->getRow($rs, 'assoc');
        if($row && $row['disabled'] == 0) {
            $config_check_warningspresent = 1;
            $config_check_warnings[] = array($_lang['configcheck_templateswitcher_present']);
            $tplName = $row['name'];
            $script = <<<JS
<script>
function deleteTemplateSwitcher(){
    if(confirm('{$_lang["confirm_delete_plugin"]}')) {
        var myAjax = new Ajax('index.php?a=118', {
            method: 'post',
            data: 'action=updateplugin&key=_delete_&lang=$tplName'
        });
        myAjax.addEvent('onComplete', function(resp){
            fieldset = $('templateswitcher_present_warning_wrapper').getParent().getParent();
            var sl = new Fx.Slide(fieldset);
            sl.slideOut();
        });
        myAjax.request();
    }
}
function disableTemplateSwitcher(){
    var myAjax = new Ajax('index.php?a=118', {
        method: 'post',
        data: 'action=updateplugin&lang={$tplName}&key=disabled&value=1'
    });
    myAjax.addEvent('onComplete', function(resp){
        fieldset = $('templateswitcher_present_warning_wrapper').getParent().getParent();
        var sl = new Fx.Slide(fieldset);
        sl.slideOut();
    });
    myAjax.request();
}
</script>

JS;
        $modx->regClientScript($script);
        }
    }
}

if ($modx->db->getValue('SELECT published FROM '.$modx->getFullTableName('site_content').' WHERE id='.$unauthorized_page) == 0) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_unauthorizedpage_unpublished']);
}

if ($modx->db->getValue('SELECT published FROM '.$modx->getFullTableName('site_content').' WHERE id='.$error_page) == 0) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_errorpage_unpublished']);
}

if ($modx->db->getValue('SELECT privateweb FROM '.$modx->getFullTableName('site_content').' WHERE id='.$unauthorized_page) == 1) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_unauthorizedpage_unavailable']);
}

if ($modx->db->getValue('SELECT privateweb FROM '.$modx->getFullTableName('site_content').' WHERE id='.$error_page) == 1) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_errorpage_unavailable']);
}

if (!function_exists('checkSiteCache')) {
    function checkSiteCache() {
        global $modx;
        $checked= true;
        if (file_exists($modx->config['base_path'] . 'assets/cache/siteCache.idx.php')) {
            $checked= @include_once ($modx->config['base_path'] . 'assets/cache/siteCache.idx.php');
        }
        return $checked;
    }
}

if (!is_writable("../assets/cache/")) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_cache']);
}

if (!checkSiteCache()) {
    $config_check_warningspresent = 1;
    $config_check_warnings[]= array($lang['configcheck_sitecache_integrity']);
}

if (!is_writable("../assets/images/")) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_images']);
}

if (count($_lang)!=$length_eng_lang) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_lang_difference']);
}

if (!$modx->config['error_handling_silent']) {
    $config_check_warningspresent = 1;
    $config_check_warnings[] = array($_lang['configcheck_error_handling_silent']);
}

// clear file info cache
clearstatcache();

if ($config_check_warningspresent==1) {

$config_check_results = "<h3>".$_lang['configcheck_notok']."</h3>";

for ($i=0;$i<count($config_check_warnings);$i++) {
    switch ($config_check_warnings[$i][0]) {
        case $_lang['configcheck_configinc'];
            $config_check_warnings[$i][1] = $_lang['configcheck_configinc_msg'];
            if(!$_SESSION["mgrConfigCheck"]) $modx->logEvent(0,2,$config_check_warnings[$i][1],$_lang['configcheck_configinc']);
            break;
        case $_lang['configcheck_installer'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_installer_msg'];
            if(!$_SESSION["mgrConfigCheck"]) $modx->logEvent(0,2,$config_check_warnings[$i][1],$_lang['configcheck_installer']);
            break;
        case $_lang['configcheck_cache'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_cache_msg'];
            if(!$_SESSION["mgrConfigCheck"]) $modx->logEvent(0,2,$config_check_warnings[$i][1],$_lang['configcheck_cache']);
            break;
        case $_lang['configcheck_images'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_images_msg'];
            if(!$_SESSION["mgrConfigCheck"]) $modx->logEvent(0,2,$config_check_warnings[$i][1],$_lang['configcheck_images']);
            break;
        case $_lang['configcheck_lang_difference'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_lang_difference_msg'];
            break;
        case $_lang['configcheck_register_globals'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_register_globals_msg'];
            break;
        case $_lang['configcheck_php_gdzip'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_php_gdzip_msg'];
            break;
        case $_lang['configcheck_unauthorizedpage_unpublished'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_unauthorizedpage_unpublished_msg'];
            break;
        case $_lang['configcheck_errorpage_unpublished'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_errorpage_unpublished_msg'];
            break;
        case $_lang['configcheck_unauthorizedpage_unavailable'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_unauthorizedpage_unavailable_msg'];
            break;
        case $_lang['configcheck_errorpage_unavailable'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_errorpage_unavailable_msg'];
            break;
        case $_lang['configcheck_validate_referer'] :
            $msg = $_lang['configcheck_validate_referer_msg'];
            $msg .= '<br />' . sprintf($_lang["configcheck_hide_warning"], 'validate_referer');
            $config_check_warnings[$i][1] = "<span id=\"validate_referer_warning_wrapper\">{$msg}</span>\n";
            break;
        case $_lang['configcheck_templateswitcher_present'] :
            $msg = $_lang["configcheck_templateswitcher_present_msg"];
            if(isset($_SESSION['mgrPermissions']['save_plugin']) && $_SESSION['mgrPermissions']['save_plugin'] == '1') {
                $msg .= '<br />' . $_lang["configcheck_templateswitcher_present_disable"];
            }
            if(isset($_SESSION['mgrPermissions']['delete_plugin']) && $_SESSION['mgrPermissions']['delete_plugin'] == '1') {
                $msg .= '<br />' . $_lang["configcheck_templateswitcher_present_delete"];
            }
            $msg .= '<br />' . sprintf($_lang["configcheck_hide_warning"], 'templateswitcher_present');
            $config_check_warnings[$i][1] = "<span id=\"templateswitcher_present_warning_wrapper\">{$msg}</span>\n";
            break;
        case $_lang['configcheck_error_handling_silent'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_error_handling_silent_msg'];
            break;
        case $_lang['configcheck_locale_LC_ALL_warning'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_locale_LC_ALL_warning_msg'];
            break;
        case $_lang['configcheck_locale_LC_NUMERIC_warning'] :
            $config_check_warnings[$i][1] = $_lang['configcheck_locale_LC_NUMERIC_warning_msg'];
            break;
        default :
            $config_check_warnings[$i][1] = $_lang['configcheck_default_msg'];
    }

    $config_check_results .= "
            <div>
            <p><strong>{$_lang['configcheck_warning']}</strong> '{$config_check_warnings[$i][0]}'</p>
            <p style=\"padding-left:1em\"><em>{$_lang['configcheck_what']}</em><br />
            {$config_check_warnings[$i][1]} ".($_SESSION['mgrRole']!=1 ? $_lang['configcheck_admin'] : '')."</p>
            </div>";

        if ($i!=count($config_check_warnings)-1) {
            $config_check_results .= "<br />";
        }
    }
    $_SESSION["mgrConfigCheck"]=true;
} else {
    $config_check_results = $_lang['configcheck_ok'];
}

