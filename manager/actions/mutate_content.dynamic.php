<?php
if(!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE != 'true') exit();

require_once('user_documents_permissions.class.php');

// Get parent docid if specified explicitly in request
if (isset($_REQUEST['pid'])) {
    $pid = intval($_REQUEST['pid']);
} elseif (isset($_POST['parent'])) { // postback
    $pid = intval($_POST['parent']);
} else {
	$pid = null;
}

// check permissions
switch ($_REQUEST['a']) {
    case 27:
        if (!$modx->hasPermission('edit_document')) {
            $e->setError(3);
            $e->dumpError();
        }
        $existing = true;
        break;
    case 85:
    case 72:
    case 4:
        if (!$modx->hasPermission('new_document')) {
            $e->setError(3);
            $e->dumpError();
        } elseif ($pid) {
            // Check permissions on parent
            $udperms = new udperms();
            $udperms->user = $modx->getLoginUserID();
            $udperms->document = $pid;
            $udperms->role = $_SESSION['mgrRole'];
            if (!$udperms->checkPermissions()) {
                $e->setError(3);
                $e->dumpError();
            }
        }
        $existing = false;
        break;
    default:
        $e->setError(3);
        $e->dumpError();
}


// Get document id
if ($existing) {
    $docid = (int)$_REQUEST['id'];
} else {
    $docid = 0;
}

// Check permissions on existing document
if ($existing) {
    //editing an existing document
    // check permissions on the document
    $udperms = new udperms();
    $udperms->user = $modx->getLoginUserID();
    $udperms->document = $docid;
    $udperms->role = $_SESSION['mgrRole'];

    if (!$udperms->checkPermissions()) {
?>
<br /><br />
<div class="sectionHeader"><?php echo $_lang['access_permissions']?></div>
<div class="sectionBody">
    <p><?php echo $_lang['access_permission_denied']?></p>
</div>
<?php
    include(MODX_MANAGER_PATH.'includes/footer.inc.php');
    exit;
    }
}

// Get table names (alphabetical)
$tbl_active_users               = $modx->getFullTableName('active_users');
$tbl_categories                 = $modx->getFullTableName('categories');
$tbl_document_group_names       = $modx->getFullTableName('documentgroup_names');
$tbl_member_groups              = $modx->getFullTableName('member_groups');
$tbl_membergroup_access         = $modx->getFullTableName('membergroup_access');
$tbl_document_groups            = $modx->getFullTableName('document_groups');
$tbl_site_content               = $modx->getFullTableName('site_content');
$tbl_site_templates             = $modx->getFullTableName('site_templates');
$tbl_site_tmplvar_access        = $modx->getFullTableName('site_tmplvar_access');
$tbl_site_tmplvar_contentvalues = $modx->getFullTableName('site_tmplvar_contentvalues');
$tbl_site_tmplvar_templates     = $modx->getFullTableName('site_tmplvar_templates');
$tbl_site_tmplvars              = $modx->getFullTableName('site_tmplvars');

// Check to see the document isn't locked
$sql = 'SELECT internalKey, username FROM '.$tbl_active_users.' WHERE action=27 AND id=\''.$docid.'\'';
$rs = $modx->db->query($sql);
$limit = $modx->db->getRecordCount($rs);
if ($limit > 1) {
    for ($i = 0; $i < $limit; $i++) {
        $lock = $modx->db->getRow($rs);
        if ($lock['internalKey'] != $modx->getLoginUserID()) {
            $msg = sprintf($_lang['lock_msg'], $lock['username'], 'document');
            $e->setError(5, $msg);
            $e->dumpError();
        }
    }
}

// get document groups for current user
if ($_SESSION['mgrDocgroups']) {
    $docgrp = implode(',', $_SESSION['mgrDocgroups']);
}

// Get document content
if (!empty ($docid)) {
    $access = "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0" .
        (!$docgrp ? '' : " OR dg.document_group IN ($docgrp)");
    $sql = 'SELECT DISTINCT sc.* '.
           'FROM '.$tbl_site_content.' AS sc '.
           'LEFT JOIN '.$tbl_document_groups.' AS dg ON dg.document=sc.id '.
           'WHERE sc.id=\''.$docid.'\' AND ('.$access.')';
    $rs = $modx->db->query($sql);
    $limit = $modx->db->getRecordCount($rs);
    if ($limit > 1) {
        $e->setError(6);
        $e->dumpError();
    }
    if ($limit < 1) {
        $e->setError(3);
        $e->dumpError();
    }
    $content = $modx->db->getRow($rs);
} else {
    $content = array();
}

// Get parent docid if not specified explicitly in request
if (is_null($pid)) {
    if (isset($content['parent'])) {
        $pid = intval($content['parent']);
    } else {
        $pid = 0;
    }
}

// Can anyone create a document here?
if (!sizeof($modx->getDocumentAllowedChildTemplates($pid))) {
    $e->setError(3);
    $e->dumpError();
}    

// restore saved form
$formRestored = false;
if ($modx->manager->hasFormValues()) {
    $modx->manager->loadFormValues();
    $formRestored = true;
}

// retain form values if template was changed
// edited to convert pub_date and unpub_date
// sottwell 02-09-2006
if ($formRestored == true || isset ($_REQUEST['newtemplate'])) {
    $content = array_merge($content, $_POST);
    $content['content'] = $_POST['ta'];
    if (empty ($content['pub_date'])) {
        unset ($content['pub_date']);
    } else {
        $content['pub_date'] = $modx->toTimeStamp($content['pub_date']);
    }
    if (empty ($content['unpub_date'])) {
        unset ($content['unpub_date']);
    } else {
        $content['unpub_date'] = $modx->toTimeStamp($content['unpub_date']);
    }
}

// increase menu index if this is a new document
if (!$existing) {
    if (!isset ($auto_menuindex) || $auto_menuindex) {
        $sql = 'SELECT count(*) FROM '.$tbl_site_content.' WHERE parent=\''.$pid.'\'';
        $content['menuindex'] = $modx->db->getValue($sql);
    } else {
        $content['menuindex'] = 0;
    }
}

if (isset ($_POST['which_editor'])) {
    $which_editor = $_POST['which_editor'];
}

// Functionality from template rules plugin by Cipa, now in the core
$template_rules = null;
if ($modx->config['template_rules_tv']) {
    $rs_tr = $modx->db->select('id', $tbl_site_tmplvars, "name = '{$modx->config['template_rules_tv']}'");
    if ($modx->db->getRecordCount($rs_tr)) {
        require_once('template_rules.class.inc.php');
        $template_rules = TemplateRules::getTvValueAndLevel($pid, reset($modx->db->getRow($rs_tr)));
    }
}

// Get allowed templates
if ($template_rules) {
    $allowed_templates_list = TemplateRules::getTemplateList($template_rules);
    if (!$allowed_templates_list) {
        $allowed_templates_list = true; // All templates allowed
    }
} elseif ($pid) {
    $template_id = $modx->db->getValue($modx->db->select('template', $tbl_site_content, "id = $pid"));
    $rs_template = $modx->db->select('default_child_template, restrict_children, allowed_child_templates', $tbl_site_templates, "id=$template_id");
    if ($rs_template && ($row_template = $modx->db->getRow($rs_template)) && $row_template['restrict_children']) {
        $allowed_templates_list = str_replace(' ', '', $row_template['allowed_child_templates']);
        if (!preg_match('/^\d+(,\d+)*$/', $allowed_templates_list)) {
        	$allowed_templates_list = '0';
        }
    } else {
        $allowed_templates_list = true; // All templates allowed
    }
} else {
    $allowed_templates_list = true; // All templates allowed
}

// Get default template
if ($template_rules && !is_null($template_rules_default = TemplateRules::getDefaultTemplate($template_rules))) {
    $default_template = $template_rules_default;
} elseif ($pid && isset($row_template) && $row_template['default_child_template']) { // No need to differentiate between blank template being specified and no value - behaviour is the same
    $default_template = $row_template['default_child_template'];
} else switch($modx->config['auto_template_logic']) {
    case 'sibling':

        if ($sibl = $modx->getDocumentChildren($pid, 1, 0, 'template', '', 'menuindex', 'ASC', 1)) {
            $default_template = $sibl[0]['template'];
            break;
        } else if ($sibl = $modx->getDocumentChildren($pid, 0, 0, 'template', '', 'menuindex', 'ASC', 1)) {
            $default_template = $sibl[0]['template'];
            break;
        }

    case 'parent':

        if ($pid && $parent = $modx->getPageInfo($pid, 0, 'template')) {
            $default_template = $parent['template'];
            break;
        }

    case 'system':
    default:
        // default_template is already set
}

// Get selected template, ensuring it is an integer in the allowed template list
if (isset($_REQUEST['newtemplate'])) {
    $selected_template = (int)$_REQUEST['newtemplate'];
} elseif (isset ($content['template'])) {
    $selected_template = (int)$content['template'];
} else {
    $selected_template = (int)$default_template;
}
if (!preg_match('/\b'.$selected_template.'\b/', $allowed_templates_list)) $selected_template = (int)substr($allowed_templates_list, 0, strpos($allowed_templates_list, ','));
?>

<script>

// save tree folder state
if (parent.tree) parent.tree.saveFolderState();

function changestate(element) {
    currval = eval(element).value;
    if (currval==1) {
        eval(element).value=0;
    } else {
        eval(element).value=1;
    }
    documentDirty=true;
}

function deletedocument() {
    if (confirm("<?php echo $_lang['confirm_delete_resource']?>")==true) {
        document.location.href="index.php?id=" + document.mutate.id.value + "&a=6";
    }
}

function duplicatedocument(){
    if(confirm("<?php echo $_lang['confirm_resource_duplicate']?>")==true) {
        document.location.href="index.php?id=<?php echo $docid; ?>&a=94";
    }
}

var allowParentSelection = false;
var allowLinkSelection = false;

function enableLinkSelection(b) {
    parent.tree.ca = "link";
    var closed = "<?php echo $_style["tree_folder"] ?>";
    var opened = "<?php echo $_style["icons_set_parent"] ?>";
    if (b) {
        document.images["llock"].src = opened;
        allowLinkSelection = true;
    }
    else {
        document.images["llock"].src = closed;
        allowLinkSelection = false;
    }
}

function setLink(lId) {
    if (!allowLinkSelection) {
        window.location.href="index.php?a=3&id="+lId;
        return;
    }
    else {
        documentDirty=true;
        document.mutate.ta.value=lId;
    }
}

function enableParentSelection(b) {
    parent.tree.ca = "parent";
    var closed = "<?php echo $_style["tree_folder"] ?>";
    var opened = "<?php echo $_style["icons_set_parent"] ?>";
    if (b) {
        document.images["plock"].src = opened;
        allowParentSelection = true;
    }
    else {
        document.images["plock"].src = closed;
        allowParentSelection = false;
    }
}

function setParent(pId, pName) {
    if (!allowParentSelection) {
        window.location.href="index.php?a=3&id="+pId;
        return;
    }
    else {
        if (pId==0 || checkParentChildRelation(pId, pName)) {
            documentDirty=true;
            document.mutate.parent.value=pId;
            var elm = document.getElementById('parentName');
            if (elm) {
                elm.innerHTML = (pId + " (" + pName + ")");
            }
        }
    }
}

// check if the selected parent is a child of this document
function checkParentChildRelation(pId, pName) {
    var sp;
    var id = document.mutate.id.value;
    var tdoc = parent.tree.document;
    var pn = (tdoc.getElementById) ? tdoc.getElementById("node"+pId) : tdoc.all["node"+pId];
    if (!pn) return;
    if (pn.id.substr(4)==id) {
        alert("<?php echo $_lang['illegal_parent_self']?>");
        return;
    }
    else {
        while (pn.getAttribute("p")>0) {
            pId = pn.getAttribute("p");
            pn = (tdoc.getElementById) ? tdoc.getElementById("node"+pId) : tdoc.all["node"+pId];
            if (pn.id.substr(4)==id) {
                alert("<?php echo $_lang['illegal_parent_child']?>");
                return;
            }
        }
    }
    return true;
}

var curTemplate = -1;
var curTemplateIndex = 0;
function storeCurTemplate() {
    var dropTemplate = document.getElementById('template');
    if (dropTemplate) {
        for (var i=0; i<dropTemplate.length; i++) {
            if (dropTemplate[i].selected) {
                curTemplate = dropTemplate[i].value;
                curTemplateIndex = i;
            }
        }
    }
}
function templateWarning() {
    var dropTemplate = document.getElementById('template');
    if (dropTemplate) {
        for (var i=0; i<dropTemplate.length; i++) {
            if (dropTemplate[i].selected) {
                newTemplate = dropTemplate[i].value;
                break;
            }
        }
    }
    if (curTemplate == newTemplate) {return;}

    if (confirm('<?php echo $_lang['tmplvar_change_template_msg']?>')) {
        documentDirty=false;
        document.mutate.a.value = <?php echo $action?>;
        document.mutate.newtemplate.value = newTemplate;
        document.mutate.submit();
    } else {
        dropTemplate[curTemplateIndex].selected = true;
    }
}

// Added for RTE selection
function changeRTE() {
    var whichEditor = document.getElementById('which_editor');
    if (whichEditor) {
        for (var i = 0; i < whichEditor.length; i++) {
            if (whichEditor[i].selected) {
                newEditor = whichEditor[i].value;
                break;
            }
        }
    }
    var dropTemplate = document.getElementById('template');
    if (dropTemplate) {
        for (var i = 0; i < dropTemplate.length; i++) {
            if (dropTemplate[i].selected) {
                newTemplate = dropTemplate[i].value;
                break;
            }
        }
    }

    documentDirty=false;
    document.mutate.a.value = <?php echo $action?>;
    document.mutate.newtemplate.value = newTemplate;
    document.mutate.which_editor.value = newEditor;
    document.mutate.submit();
}

/**
 * Snippet properties
 */

var snippetParams = {};     // Snippet Params
var currentParams = {};     // Current Params
var lastsp, lastmod = {};

function showParameters(ctrl) {
    var c,p,df,cp;
    var ar,desc,value,key,dt;

    cp = {};
    currentParams = {}; // reset;

    if (ctrl) {
        f = ctrl.form;
    } else {
        f= document.forms['mutate'];
        ctrl = f.snippetlist;
    }

    // get display format
    df = "";//lastsp = ctrl.options[ctrl.selectedIndex].value;

    // load last modified param values
    if (lastmod[df]) cp = lastmod[df].split("&");
    for (p = 0; p < cp.length; p++) {
        cp[p]=(cp[p]+'').replace(/^\s|\s$/,""); // trim
        ar = cp[p].split("=");
        currentParams[ar[0]]=ar[1];
    }

    // setup parameters
    dp = (snippetParams[df]) ? snippetParams[df].split("&"):[""];
    if (dp) {
        t='<table width="100%" style="margin-bottom:3px;margin-left:14px;background-color:#EEEEEE" cellpadding="2" cellspacing="1"><thead><tr><td width="50%"><?php echo $_lang['parameter']?><\/td><td width="50%"><?php echo $_lang['value']?><\/td><\/tr><\/thead>';
        for (p = 0; p < dp.length; p++) {
            dp[p]=(dp[p]+'').replace(/^\s|\s$/,""); // trim
            ar = dp[p].split("=");
            key = ar[0]     // param
            ar = (ar[1]+'').split(";");
            desc = ar[0];   // description
            dt = ar[1];     // data type
            value = decode((currentParams[key]) ? currentParams[key]:(dt=='list') ? ar[3] : (ar[2])? ar[2]:'');
            if (value!=currentParams[key]) currentParams[key] = value;
            value = (value+'').replace(/^\s|\s$/,""); // trim
            if (dt) {
                switch(dt) {
                    case 'int':
                        c = '<input type="text" name="prop_'+key+'" value="'+value+'" size="30" onchange="setParameter(\''+key+'\',\''+dt+'\',this)" \/>';
                        break;
                    case 'list':
                        c = '<select name="prop_'+key+'" height="1" style="width:168px" onchange="setParameter(\''+key+'\',\''+dt+'\',this)">';
                        ls = (ar[2]+'').split(",");
                        if (currentParams[key]==ar[2]) currentParams[key] = ls[0]; // use first list item as default
                        for (i=0;i<ls.length;i++) {
                            c += '<option value="'+ls[i]+'"'+((ls[i]==value)? ' selected="selected"':'')+'>'+ls[i]+'<\/option>';
                        }
                        c += '<\/select>';
                        break;
                    default:  // string
                        c = '<input type="text" name="prop_'+key+'" value="'+value+'" size="30" onchange="setParameter(\''+key+'\',\''+dt+'\',this)" \/>';
                        break;

                }
                t +='<tr><td bgcolor="#FFFFFF" width="50%">'+desc+'<\/td><td bgcolor="#FFFFFF" width="50%">'+c+'<\/td><\/tr>';
            };
        }
        t+='<\/table>';
        td = (document.getElementById) ? document.getElementById('snippetparams'):document.all['snippetparams'];
        td.innerHTML = t;
    }
    implodeParameters();
}

function setParameter(key,dt,ctrl) {
    var v;
    if (!ctrl) return null;
    switch (dt) {
        case 'int':
            ctrl.value = parseInt(ctrl.value);
            if (isNaN(ctrl.value)) ctrl.value = 0;
            v = ctrl.value;
            break;
        case 'list':
            v = ctrl.options[ctrl.selectedIndex].value;
            break;
        default:
            v = ctrl.value+'';
            break;
    }
    currentParams[key] = v;
    implodeParameters();
}

function resetParameters() {
    document.mutate.params.value = "";
    lastmod[lastsp]="";
    showParameters();
}
// implode parameters
function implodeParameters() {
    var v, p, s = '';
    for (p in currentParams) {
        v = currentParams[p];
        if (v) s += '&'+p+'='+ encode(v);
    }
    //document.forms['mutate'].params.value = s;
    if (lastsp) lastmod[lastsp] = s;
}

function encode(s) {
    s = s+'';
    s = s.replace(/\=/g,'%3D'); // =
    s = s.replace(/\&/g,'%26'); // &
    return s;
}

function decode(s) {
    s = s+'';
    s = s.replace(/\%3D/g,'='); // =
    s = s.replace(/\%26/g,'&'); // &
    return s;
}
/* ]]> */
</script>


<?php 
			
/*******************************
 * Document Access Permissions */
if ($use_udperms == 1) {
    $groupsarray = array();
    $sql = '';

    $documentId = $existing ? $docid : $pid;
    if ($documentId > 0) {
        // Load up, the permissions from the parent (if new document) or existing document
        $sql = 'SELECT id, document_group FROM '.$tbl_document_groups.' WHERE document=\''.$documentId.'\'';
        $rs = $modx->db->query($sql);
        while ($currentgroup = $modx->db->getRow($rs))
            $groupsarray[] = $currentgroup['document_group'].','.$currentgroup['id'];

        // Load up the current permissions and names
        $sql = 'SELECT dgn.*, groups.id AS link_id '.
               'FROM '.$tbl_document_group_names.' AS dgn '.
               'LEFT JOIN '.$tbl_document_groups.' AS groups ON groups.document_group = dgn.id '.
               '  AND groups.document = '.$documentId.' '.
               'ORDER BY name';
    } else {
        // Just load up the names, we're starting clean
        $sql = 'SELECT *, NULL AS link_id FROM '.$tbl_document_group_names.' ORDER BY name';
    }

    // retain selected doc groups between post
    if (isset($_POST['docgroups']))
        $groupsarray = array_merge($groupsarray, $_POST['docgroups']);

    // Query the permissions and names from above
    $rs = $modx->db->query($sql);
    $limit = $modx->db->getRecordCount($rs);

    $isManager = $modx->hasPermission('access_permissions');
    $isWeb     = $modx->hasPermission('web_access_permissions');

    // Setup Basic attributes for each Input box
    $inputAttributes = array(
        'type' => 'checkbox',
        'class' => 'checkbox',
        'name' => 'docgroups[]',
        'onclick' => 'makePublic(false);',
    );
    $permissions = array(); // New Permissions array list (this contains the HTML)
    $permissions_yes = 0; // count permissions the current mgr user has
    $permissions_no = 0; // count permissions the current mgr user doesn't have

    // Loop through the permissions list
    for ($i = 0; $i < $limit; $i++) {
        $row = $modx->db->getRow($rs);

        // Create an inputValue pair (group ID and group link (if it exists))
        $inputValue = $row['id'].','.($row['link_id'] ? $row['link_id'] : 'new');
        $inputId    = 'group-'.$row['id'];

        $checked    = in_array($inputValue, $groupsarray);
        if ($checked) $notPublic = true; // Mark as private access (either web or manager)

        // Skip the access permission if the user doesn't have access...
        if ((!$isManager && $row['private_memgroup'] == '1') || (!$isWeb && $row['private_webgroup'] == '1'))
            continue;

        // Setup attributes for this Input box
        $inputAttributes['id']    = $inputId;
        $inputAttributes['value'] = $inputValue;
        if ($checked)
                $inputAttributes['checked'] = 'checked';
        else    unset($inputAttributes['checked']);

        // Create attribute string list
        $inputString = array();
        foreach ($inputAttributes as $k => $v) $inputString[] = $k.'="'.$v.'"';

        // Make the <input> HTML
        $inputHTML = '<input '.implode(' ', $inputString).' />';

        // does user have this permission?
        $sql = "SELECT COUNT(mg.id) FROM {$tbl_membergroup_access} mga, {$tbl_member_groups} mg
 WHERE mga.membergroup = mg.user_group
 AND mga.documentgroup = {$row['id']}
 AND mg.member = {$_SESSION['mgrInternalKey']};";
        $rsp = $modx->db->query($sql);
        $count = $modx->db->getValue($rsp);
        if($count > 0) {
            ++$permissions_yes;
        } else {
            ++$permissions_no;
        }
        $permissions[] = "\t\t".'<li>'.$inputHTML.'<label for="'.$inputId.'">'.$row['name'].'</label></li>';
    }
    // if mgr user doesn't have access to any of the displayable permissions, forget about them and make doc public
    if($_SESSION['mgrRole'] != 1 && ($permissions_yes == 0 && $permissions_no > 0)) {
        $permissions = array();
    }
    ?>
			    
<form name="mutate" id="mutate" class="content" method="post" enctype="multipart/form-data" action="index.php">

	
	<?php
	// invoke OnDocFormPrerender event
	$evtOut = $modx->invokeEvent('OnDocFormPrerender', array(
	    'id' => $docid,
	    'parent' => $pid,
	    'template' => $selected_template
	));
	if (is_array($evtOut))
	    echo implode('', $evtOut);
	?>
	<input type="hidden" name="a" value="5" />
	<input type="hidden" name="id" value="<?php echo $content['id']?>" />
	<input type="hidden" name="mode" value="<?php echo (int) $_REQUEST['a']?>" />
	<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo isset($upload_maxsize) ? $upload_maxsize : 1048576?>" />
	<input type="hidden" name="refresh_preview" value="0" />
	<input type="hidden" name="newtemplate" value="" />
	
	<fieldset id="create_edit">
	    <h1><?php if ($existing){ echo $_lang['edit_resource_title']; } else { echo $_lang['create_resource_title'];}?></h1>
	
		<div id="actions">
		      <ul class="actionButtons">
		          <li id="Button1">
		            <a href="#" onclick="documentDirty=false; document.mutate.save.click();">
		              <img alt="icons_save" src="<?php echo $_style["icons_save"]?>" /> <?php echo $_lang['save']?>
		            </a><span class="and"> + </span>
		            <select id="stay" name="stay">
		              <?php if ($modx->hasPermission('new_document')) { ?>
		              <option id="stay1" value="1" <?php echo $_REQUEST['stay']=='1' ? ' selected=""' : ''?> ><?php echo $_lang['stay_new']?></option>
		              <?php } ?>
		              <option id="stay2" value="2" <?php echo $_REQUEST['stay']=='2' ? ' selected="selected"' : ''?> ><?php echo $_lang['stay']?></option>
		              <option id="stay3" value=""  <?php echo $_REQUEST['stay']=='' ? ' selected=""' : ''?>  ><?php echo $_lang['close']?></option>
		            </select>
		          </li>
		          <?php
		            if ($_REQUEST['a'] == '4' || $_REQUEST['a'] == '72') { ?>
		          <li id="Button2" class="disabled"><a href="#" onclick="deletedocument();"><img src="<?php echo $_style["icons_delete_document"] ?>" alt="icons_delete_document" /> <?php echo $_lang['delete']?></a></li>
		          <?php } else { ?>
		          <li id="Button6"><a href="#" onclick="duplicatedocument();"><img src="<?php echo $_style["icons_resource_duplicate"] ?>" alt="icons_resource_duplicate" /> <?php echo $_lang['duplicate']?></a></li>
		          <li id="Button3"><a href="#" onclick="deletedocument();"><img src="<?php echo $_style["icons_delete_document"] ?>" alt="icons_delete_document" /> <?php echo $_lang['delete']?></a></li>
		          <?php } ?>
		          <li id="Button4"><a href="#" onclick="documentDirty=false;<?php echo $docid==0 ? "document.location.href='index.php?a=2';" : "document.location.href='index.php?a=3&amp;id=$docid';"?>"><img alt="icons_cancel" src="<?php echo $_style["icons_cancel"] ?>" /> <?php echo $_lang['cancel']?></a></li>
		          <li id="Button5"><a href="#" onclick="window.open('<?php echo $modx->makeUrl($docid); ?>','previeWin');"><img alt="icons_preview_resource" src="<?php echo $_style["icons_preview_resource"] ?>" /> <?php echo $_lang['preview']?></a></li>
		      </ul>
		</div>
		
		<!-- start main wrapper -->
		
		
		<div class="sectionBody">
		
			<div id="mutate-content-tabs" class="js-tabs">
				<ul>
					<li><a href="#tabGeneral"><?php echo $_lang['settings_general']?></a></li>
					<li><a href="#tabSettings"><?php echo $_lang['settings_page_settings']?></a></li>
					<?php if ($use_udperms == 1 && !empty($permissions)) { //TODO: hide tab when permissions are empty either in php either in js. js looks simple in this case - detect empty tab content and remove tab?>
					<li><a href="#tabAccess"><?php echo $_lang['access_permissions']?></a></li>
					<?php } ?>
				</ul>
				
				<div id="tabGeneral">
		
        <table>
            <tr><td width="100"><span class="warning"><?php echo $_lang['resource_title']?></span></td>
                <td><input name="pagetitle" type="text" maxlength="255" value="<?php echo htmlspecialchars(stripslashes($content['pagetitle']))?>" class="inputBox" onchange="documentDirty=true;" spellcheck="true" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_title_help']?>" class="tooltip"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['long_title']?></span></td>
                <td><input name="longtitle" type="text" maxlength="255" value="<?php echo htmlspecialchars(stripslashes($content['longtitle']))?>" class="inputBox" onchange="documentDirty=true;" spellcheck="true" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_long_title_help']?>" class="tooltip"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['resource_description']?></span></td>
                <td><input name="description" type="text" maxlength="255" value="<?php echo htmlspecialchars(stripslashes($content['description']))?>" class="inputBox" onchange="documentDirty=true;" spellcheck="true" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_description_help']?>" class="tooltip"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['resource_alias']?></span></td>
                <td><input name="alias" type="text" maxlength="100" value="<?php echo stripslashes($content['alias'])?>" class="inputBox" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_alias_help']?>" class="tooltip"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['link_attributes']?></span></td>
                <td><input name="link_attributes" type="text" maxlength="255" value="<?php echo htmlspecialchars(stripslashes($content['link_attributes']))?>" class="inputBox" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['link_attributes_help']?>" class="tooltip"/></td></tr>

<?php if ($content['type'] == 'reference' || $_REQUEST['a'] == '72') { // Web Link specific ?>

            <tr><td><span class="warning"><?php echo $_lang['weblink']?></span> <img name="llock" src="<?php echo $_style["tree_folder"] ?>" alt="tree_folder" onclick="enableLinkSelection(!allowLinkSelection);" style="cursor:pointer;" /></td>
                <td><input name="ta" type="text" maxlength="255" value="<?php echo !empty($content['content']) ? stripslashes($content['content']) : "http://"?>" class="inputBox" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_weblink_help']?>" class="tooltip"/></td></tr>

<?php } ?>

            <tr><td width="100"><span class="warning"><?php echo $_lang['resource_summary']?></span></td>
                <td><textarea name="introtext" class="inputBox" rows="3" cols="" onchange="documentDirty=true;"><?php echo htmlspecialchars(stripslashes($content['introtext']))?></textarea>
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_summary_help']?>" class="tooltip" spellcheck="true"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['page_data_template']?></span></td>
                <td>
                   <select id="template" name="template" class="inputBox" onchange="templateWarning();" style="width:308px">
                    <?php
                    if ($allowed_templates_list === true || preg_match('/\b0\b/', $allowed_templates_list)) {
                        echo '<option value="0">(blank)</option>';
                    }

                // Get requested/existing/default template.
                // Note that this may not be an 'allowed' template.
                if (isset($_REQUEST['newtemplate'])) {
                    $selected_template_if_allowed = $_REQUEST['newtemplate'];
                } elseif (isset($content['template'])) {
                    $selected_template_if_allowed = $content['template'];
                } else {
                   $selected_template_if_allowed = $default_template;
                }

                $sql = 'SELECT t.templatename, t.id, c.category
                            FROM '.$tbl_site_templates.' t
                            LEFT JOIN '.$tbl_categories.' c ON t.category = c.id
                            '.($allowed_templates_list !== true ? 'WHERE t.id IN ('.$allowed_templates_list.')' : '').'
                            ORDER BY c.category, t.templatename ASC';
                $rs = $modx->db->query($sql);
                
                $selected_template = null;
                $currentCategory = '';
                while ($row = $modx->db->getRow($rs)) {

                    if (!$selected_template) {
                        $selected_template = $row['id']; // The first template in the dropdown is the selected one if the requested/existing/default template is not allowed.
                    }

                    $thisCategory = $row['category'];
                    if($thisCategory == null) {
                        $thisCategory = $_lang["no_category"];
                    }
                    if($thisCategory != $currentCategory) {
                        if($closeOptGroup) {
                            echo "\t\t\t\t\t</optgroup>\n";
                        }
                        echo "\t\t\t\t\t<optgroup label=\"$thisCategory\">\n";
                        $closeOptGroup = true;
                    } else {
                        $closeOptGroup = false;
                    }

                    if ($row['id'] == $selected_template_if_allowed) {
                        // Requested/existing/default template is allowed
                        $selectedtext = ' selected="selected"';
                        $selected_template = $row['id'];
                    } else {
                        $selectedtext = '';
                    }

                    echo "\t\t\t\t\t".'<option value="'.$row['id'].'"'.$selectedtext.'>'.$row['templatename']."</option>\n";

                    $currentCategory = $thisCategory;
                }
                if($thisCategory != '') {
                    echo "\t\t\t\t\t</optgroup>\n";
                }
?>
                </select> &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_template_help']?>" class="tooltip"/></td></tr>
            <tr><td style="width:100px;"><span class="warning"><?php echo $_lang['resource_opt_menu_title']?></span></td>
                <td><input name="menutitle" type="text" maxlength="255" value="<?php echo htmlspecialchars(stripslashes($content['menutitle']))?>" class="inputBox" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_menu_title_help']?>" class="tooltip"/></td></tr>
            <tr><td style="width:100px;"><span class="warning"><?php echo $_lang['resource_opt_menu_index']?></span></td>
                <td><table style="width:333px;"><tr>
                    <td><input name="menuindex" type="text" maxlength="3" value="<?php echo $content['menuindex']?>" class="inputBox" style="width:30px;" onchange="documentDirty=true;" /><input type="button" value="&lt;" onclick="var elm = document.mutate.menuindex;var v=parseInt(elm.value+'')-1;elm.value=v>0? v:0;elm.focus();documentDirty=true;" /><input type="button" value="&gt;" onclick="var elm = document.mutate.menuindex;var v=parseInt(elm.value+'')+1;elm.value=v>0? v:0;elm.focus();documentDirty=true;" />
                    &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_menu_index_help']?>" class="tooltip"/></td>
                    <td align="right" style="text-align:right;"><span class="warning"><?php echo $_lang['resource_opt_show_menu']?></span>&nbsp;<input name="hidemenucheck" type="checkbox" class="checkbox" <?php echo $content['hidemenu']!=1 ? 'checked="checked"':''?> onclick="changestate(document.mutate.hidemenu);" /><input type="hidden" name="hidemenu" class="hidden" value="<?php echo ($content['hidemenu']==1) ? 1 : 0?>" />
                    &nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_show_menu_help']?>" class="tooltip"/></td>
                </tr></table></td></tr>

            <tr><td colspan="2"><div class="split"></div></td></tr>

            <tr><td><span class="warning"><?php echo $_lang['resource_parent']?></span></td>
                <td>
                <?php
                if ($pid) {
                    $parentname = $modx->db->getValue("SELECT pagetitle FROM $tbl_site_content WHERE id='$pid'");
                } else {
                	$parentname = $site_name;
                }
                ?>&nbsp;<img alt="tree_folder" name="plock" src="<?php echo $_style["tree_folder"] ?>" onclick="enableParentSelection(!allowParentSelection);" style="cursor:pointer;" /> <b><span id="parentName"><?php echo $pid; ?> (<?php echo $parentname?>)</span></b>
    &nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_parent_help']?>" class="tooltip"/>
                <input type="hidden" name="parent" value="<?php echo $pid; ?>" onchange="documentDirty=true;" />
                </td></tr>
        </table>

<?php if ($content['type'] == 'document' || $_REQUEST['a'] == '4') { ?>
        <!-- Content -->
            <div class="sectionHeader" id="content_header"><?php echo $_lang['resource_content']?></div>
            <div class="sectionBody" id="content_body">
<?php
            if (($content['richtext'] == 1 || $_REQUEST['a'] == '4') && $use_editor == 1) {
                $htmlContent = $content['content'];
?>
                <div style="width:100%">
                    <textarea id="ta" name="ta" cols="" rows="" style="width:100%; height: 400px;" onchange="documentDirty=true;"><?php echo htmlspecialchars($htmlContent)?></textarea>
                    <span class="warning"><?php echo $_lang['which_editor_title']?></span>

                    <select id="which_editor" name="which_editor" onchange="changeRTE();">
                        <option value="none"><?php echo $_lang['none']?></option>
<?php
                        // invoke OnRichTextEditorRegister event
                        $evtOut = $modx->invokeEvent("OnRichTextEditorRegister");
                        if (is_array($evtOut)) {
                            for ($i = 0; $i < count($evtOut); $i++) {
                                $editor = $evtOut[$i];
                                echo "\t\t\t",'<option value="',$editor,'"',($which_editor == $editor ? ' selected="selected"' : ''),'>',$editor,"</option>\n";
                            }
                        }
?>
                        </select>
                </div>
<?php
                $replace_richtexteditor = array(
                    'ta',
                );
            } else {
                echo "\t".'<div style="width:100%"><textarea class="phptextarea" id="ta" name="ta" style="width:100%; height: 400px;" onchange="documentDirty=true;">',htmlspecialchars($content['content']),'</textarea></div>'."\n";
            }
?>
            </div><!-- end .sectionBody -->
<?php } ?>

<?php if (($content['type'] == 'document' || $_REQUEST['a'] == '4') || ($content['type'] == 'reference' || $_REQUEST['a'] == 72)) { ?>
        <!-- Template Variables -->
            <div class="sectionHeader" id="tv_header"><?php echo $_lang['settings_templvars']?></div>
            <div class="sectionBody tmplvars" id="tv_body">
<?php
                $sql = 'SELECT DISTINCT tv.*, IF(tvc.value!=\'\',tvc.value,tv.default_text) as value '.
                       'FROM '.$tbl_site_tmplvars.' AS tv '.
                       'INNER JOIN '.$tbl_site_tmplvar_templates.' AS tvtpl ON tvtpl.tmplvarid = tv.id '.
                       'LEFT JOIN '.$tbl_site_tmplvar_contentvalues.' AS tvc ON tvc.tmplvarid=tv.id AND tvc.contentid=\''.$docid.'\' '.
                       'LEFT JOIN '.$tbl_site_tmplvar_access.' AS tva ON tva.tmplvarid=tv.id '.
                       'WHERE tvtpl.templateid=\''.$selected_template.'\' AND (1=\''.$_SESSION['mgrRole'].'\' OR ISNULL(tva.documentgroup)'.
                       (!$docgrp ? '' : ' OR tva.documentgroup IN ('.$docgrp.')').
                       ') ORDER BY tvtpl.rank,tv.rank, tv.id';
                $rs = $modx->db->query($sql);
                $limit = $modx->db->getRecordCount($rs);
                if ($limit > 0) {
                    echo "\t".'<table>'."\n";
                    require_once(MODX_MANAGER_PATH.'includes/tmplvars.inc.php');
                    require_once(MODX_MANAGER_PATH.'includes/tmplvars.commands.inc.php');
                    for ($i = 0; $i < $limit; $i++) {
                        // Go through and display all Template Variables
                        $row = $modx->db->getRow($rs);
                        if ($row['type'] == 'richtext' || $row['type'] == 'htmlarea') {
                            // Add richtext editor to the list
                            if (is_array($replace_richtexteditor)) {
                                $replace_richtexteditor = array_merge($replace_richtexteditor, array(
                                    "tv" . $row['id'],
                                ));
                            } else {
                                $replace_richtexteditor = array(
                                    "tv" . $row['id'],
                                );
                            }
                        }
                        // splitter
                        if ($i > 0 && $i < $limit)
                            echo "\t\t",'<tr><td colspan="2"><div class="split"></div></td></tr>',"\n";

                        // post back value
                        if(array_key_exists('tv'.$row['id'], $_POST)) {
                            if($row['type'] == 'listbox-multiple') {
                                $tvPBV = implode('||', $_POST['tv'.$row['id']]);
                            } else {
                                $tvPBV = $_POST['tv'.$row['id']];
                            }
                        } else {
                            $tvPBV = $row['value'];
                        }

                        $zindex = $row['type'] == 'date' ? '100' : '500';
                        echo "\t\t",'<tr><td width="150"><span class="warning">',$row['caption'],"</span>\n",
                             "\t\t\t",'<br /><span class="comment">',$row['description'],"</span></td>\n",
                             "\t\t\t",'<td style="position:relative;',($row['type'] == 'date' ? 'z-index:{$zindex};' : ''),'">',"\n",
                             "\t\t\t",renderFormElement($row['type'], $row['id'], $row['default_text'], $row['elements'], $tvPBV, '', $row),"\n",
                             "\t\t</td></tr>\n";
                    }
                    echo "\t</table>\n";
                } else {
                    // There aren't any Template Variables
                    echo "\t<p>".$_lang['tmplvars_novars']."</p>\n";
                }
            ?>
            </div>
            <!-- end .sectionBody .tmplvars -->
        <?php } ?>
    
    </div><!-- end #tabGeneral -->
				
				<div id="tabSettings">
		
		<table>

        <?php $mx_can_pub = $modx->hasPermission('publish_document') ? '' : 'disabled="disabled" '; ?>
            <tr>
                <td><span class="warning"><?php echo $_lang['resource_opt_published']?></span></td>
                <td><input <?php echo $mx_can_pub ?>name="publishedcheck" type="checkbox" class="checkbox" <?php echo (isset($content['published']) && $content['published']==1) || (!isset($content['published']) && $publish_default==1) ? "checked" : ''?> onclick="changestate(document.mutate.published);" />
                <input type="hidden" name="published" value="<?php echo (isset($content['published']) && $content['published']==1) || (!isset($content['published']) && $publish_default==1) ? 1 : 0?>" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_published_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['page_data_publishdate']?></span></td>
                <td><input id="pub_date" <?php echo $mx_can_pub ?>name="pub_date" class="DatePicker" value="<?php echo $content['pub_date']=="0" || !isset($content['pub_date']) ? '' : $modx->toDateFormat($content['pub_date'])?>" onblur="documentDirty=true;" />
                <a href="javascript:void(0);" onclick="javascript:document.mutate.pub_date.value=''; return true;" onmouseover="window.status='<?php echo $_lang['remove_date']?>'; return true;" onmouseout="window.status=''; return true;" style="cursor:pointer; cursor:hand;">
                <img src="<?php echo $_style["icons_cal_nodate"] ?>" width="16" height="16" border="0" alt="<?php echo $_lang['remove_date']?>" /></a>
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_publishdate_help']?>" class="tooltip"/>
                </td>
            </tr>
            <tr>
                <td></td>
                <td style="color: #555;font-size:10px"><em> <?php echo $modx->config['date_format']; ?> <?php echo $modx->config['time_format']; ?></em></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['page_data_unpublishdate']?></span></td>
                <td><input id="unpub_date" <?php echo $mx_can_pub ?>name="unpub_date" class="DatePicker" value="<?php echo $content['unpub_date']=="0" || !isset($content['unpub_date']) ? '' : $modx->toDateFormat($content['unpub_date'])?>" onblur="documentDirty=true;" />
                <a onclick="document.mutate.unpub_date.value=''; return true;" onmouseover="window.status='<?php echo $_lang['remove_date']?>'; return true;" onmouseout="window.status=''; return true;" style="cursor:pointer; cursor:hand">
                <img src="<?php echo $_style["icons_cal_nodate"] ?>" width="16" height="16" border="0" alt="<?php echo $_lang['remove_date']?>" /></a>
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_unpublishdate_help']?>" class="tooltip"/>
                </td>
            </tr>
            <tr>
                <td></td>
                <td style="color: #555;font-size:10px"><em> <?php echo $modx->config['date_format']; ?> <?php echo $modx->config['time_format']; ?></em></td>
            </tr>
            <tr>
              <td colspan="2"><div class='split'></div></td>
            </tr>

<?php

if ($_SESSION['mgrRole'] == 1 || !$existing || $_SESSION['mgrInternalKey'] == $content['createdby']) {
?>
            <tr><td><span class="warning"><?php echo $_lang['resource_type']?></span></td>
                <td><select name="type" class="inputBox" onchange="documentDirty=true;" style="width:200px">

                    <option value="document"<?php echo (($content['type'] == "document" || $_REQUEST['a'] == '85' || $_REQUEST['a'] == '4') ? ' selected="selected"' : "");?> ><?php echo $_lang["resource_type_webpage"];?></option>
                    <option value="reference"<?php echo (($content['type'] == "reference" || $_REQUEST['a'] == '72') ? ' selected="selected"' : "");?> ><?php echo $_lang["resource_type_weblink"];?></option>
                    </select>
                    &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_type_message']?>" class="tooltip"/></td></tr>

            <tr><td><span class="warning"><?php echo $_lang['page_data_contentType']?></span></td>
                <td><select name="contentType" class="inputBox" onchange="documentDirty=true;" style="width:200px">
            <?php
                if (!$content['contentType'])
                    $content['contentType'] = 'text/html';
                $custom_contenttype = (isset ($custom_contenttype) ? $custom_contenttype : "text/html,text/plain,text/xml");
                $ct = explode(",", $custom_contenttype);
                for ($i = 0; $i < count($ct); $i++) {
                    echo "\t\t\t\t\t".'<option value="'.$ct[$i].'"'.($content['contentType'] == $ct[$i] ? ' selected="selected"' : '').'>'.$ct[$i]."</option>\n";
                }
            ?>
                </select>
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_contentType_help']?>" class="tooltip"/></td></tr>
            <tr><td><span class="warning"><?php echo $_lang['resource_opt_contentdispo']?></span></td>
                <td><select name="content_dispo" size="1" onchange="documentDirty=true;" style="width:200px">
                    <option value="0"<?php echo !$content['content_dispo'] ? ' selected="selected"':''?>><?php echo $_lang['inline']?></option>
                    <option value="1"<?php echo $content['content_dispo']==1 ? ' selected="selected"':''?>><?php echo $_lang['attachment']?></option>
                </select>
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_contentdispo_help']?>" class="tooltip"/></td></tr>

            <tr>
              <td colspan="2"><div class='split'></div></td>
            </tr>
<?php
} else {
    if ($content['type'] != 'reference' && $_REQUEST['a'] != '72') {
        // non-admin managers creating or editing a document resource
?>
            <input type="hidden" name="contentType" value="<?php echo isset($content['contentType']) ? $content['contentType'] : "text/html"?>" />
            <input type="hidden" name="type" value="document" />
            <input type="hidden" name="content_dispo" value="<?php echo isset($content['content_dispo']) ? $content['content_dispo'] : '0'?>" />
<?php
    } else {
        // non-admin managers creating or editing a reference (weblink) resource
?>
            <input type="hidden" name="type" value="reference" />
            <input type="hidden" name="contentType" value="text/html" />
<?php
    }
}//if mgrRole
?>

            <tr>
                <td width="150"><span class="warning"><?php echo $_lang['resource_opt_folder']?></span></td>
                <td><input name="isfoldercheck" type="checkbox" class="checkbox" <?php echo ($content['isfolder']==1||$_REQUEST['a']=='85') ? "checked" : ''?> onclick="changestate(document.mutate.isfolder);" />
                <input type="hidden" name="isfolder" value="<?php echo ($content['isfolder']==1||$_REQUEST['a']=='85') ? 1 : 0?>" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_folder_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['resource_opt_richtext']?></span></td>
                <td><input name="richtextcheck" type="checkbox" class="checkbox" <?php echo $content['richtext']==0 && $existing ? '' : "checked"?> onclick="changestate(document.mutate.richtext);" />
                <input type="hidden" name="richtext" value="<?php echo $content['richtext']==0 && $existing ? 0 : 1?>" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_richtext_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td width="150"><span class="warning"><?php echo $_lang['track_visitors_title']?></span></td>
                <td><input name="donthitcheck" type="checkbox" class="checkbox" <?php echo ($content['donthit']!=1) ? 'checked="checked"' : ''?> onclick="changestate(document.mutate.donthit);" /><input type="hidden" name="donthit" value="<?php echo ($content['donthit']==1) ? 1 : 0?>" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_trackvisit_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['page_data_searchable']?></span></td>
                <td><input name="searchablecheck" type="checkbox" class="checkbox" <?php echo (isset($content['searchable']) && $content['searchable']==1) || (!isset($content['searchable']) && $search_default==1) ? "checked" : ''?> onclick="changestate(document.mutate.searchable);" /><input type="hidden" name="searchable" value="<?php echo (isset($content['searchable']) && $content['searchable']==1) || (!isset($content['searchable']) && $search_default==1) ? 1 : 0?>" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_searchable_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['page_data_cacheable']?></span></td>
                <td><input name="cacheablecheck" type="checkbox" class="checkbox" <?php echo (isset($content['cacheable']) && $content['cacheable']==1) || (!isset($content['cacheable']) && $cache_default==1) ? "checked" : ''?> onclick="changestate(document.mutate.cacheable);" />
                <input type="hidden" name="cacheable" value="<?php echo (isset($content['cacheable']) && $content['cacheable']==1) || (!isset($content['cacheable']) && $cache_default==1) ? 1 : 0?>" onchange="documentDirty=true;" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['page_data_cacheable_help']?>" class="tooltip"/></td>
            </tr>
            <tr>
                <td><span class="warning"><?php echo $_lang['resource_opt_emptycache']?></span></td>
                <td><input name="syncsitecheck" type="checkbox" class="checkbox" checked="checked" onclick="changestate(document.mutate.syncsite);" />
                <input type="hidden" name="syncsite" value="1" />
                &nbsp;&nbsp;<img src="<?php echo $_style["icons_tooltip"]?>" title="<?php echo $_lang['resource_opt_emptycache_help']?>" class="tooltip"/></td>
            </tr>
        </table>
    
    
    </div> <!-- end tabSettings -->
				
				<?php
			    // See if the Access Permissions section is worth displaying...
			    if (!empty($permissions)) {
			        // Add the "All Document Groups" item if we have rights in both contexts
			        if ($isManager && $isWeb)
			            array_unshift($permissions,"\t\t".'<li><input type="checkbox" class="checkbox" name="chkalldocs" id="groupall"'.(!$notPublic ? ' checked="checked"' : '').' onclick="makePublic(true);" /><label for="groupall" class="warning">' . $_lang['all_doc_groups'] . '</label></li>');
			        // Output the permissions list...
			?>
			<!-- Access Permissions -->
			<div id="tabAccess">

			    <script>
			        function makePublic(b) {
			            var notPublic = false;
			            var f = document.forms['mutate'];
			            var chkpub = f['chkalldocs'];
			            var chks = f['docgroups[]'];
			            if (!chks && chkpub) {
			                chkpub.checked=true;
			                return false;
			            } else if (!b && chkpub) {
			                if (!chks.length) notPublic = chks.checked;
			                else for (i = 0; i < chks.length; i++) if (chks[i].checked) notPublic = true;
			                chkpub.checked = !notPublic;
			            } else {
			                if (!chks.length) chks.checked = (b) ? false : chks.checked;
			                else for (i = 0; i < chks.length; i++) if (b) chks[i].checked = false;
			                chkpub.checked = true;
			            }
			        }
			    </script>
			    <p><?php echo $_lang['access_permissions_docs_message']?></p>
			    <ul>
			    <?php echo implode("\n", $permissions)."\n"; ?>
			    </ul>
			</div><!--div class="tab-page" id="tabAccess"-->
			<?php
			    } // !empty($permissions)
			    elseif($_SESSION['mgrRole'] != 1 && ($permissions_yes == 0 && $permissions_no > 0) && ($_SESSION['mgrPermissions']['access_permissions'] == 1 || $_SESSION['mgrPermissions']['web_access_permissions'] == 1)) {
			?>
			    <div id="tabAccess"><p><?php echo $_lang["access_permissions_docs_collision"];?></p></div>
			<?php
			
			    }
			}
			/* End Document Access Permissions *
			 ***********************************/
			?>
			
			
				<input type="submit" name="save" style="display:none" />
				<?php
				
				// invoke OnDocFormRender event
				$evtOut = $modx->invokeEvent('OnDocFormRender', array(
				    'id' => $docid,
				    'parent' => $pid,
				    'template' => $selected_template
				));
				if (is_array($evtOut)) echo implode('', $evtOut);
				?>
			
			
			</div> <!-- end tabs -->
			
			
		</div><!--div class="sectionBody"-->
		
	
	</fieldset>
</form>



<script>
    storeCurTemplate();
</script>
<?php
    if (($content['richtext'] == 1 || $_REQUEST['a'] == '4' || $_REQUEST['a'] == '72') && $use_editor == 1) {
        if (is_array($replace_richtexteditor)) {
            // invoke OnRichTextEditorInit event
            $evtOut = $modx->invokeEvent('OnRichTextEditorInit', array(
                'editor' => $which_editor,
                'elements' => $replace_richtexteditor
            ));
            if (is_array($evtOut))
                echo implode('', $evtOut);
        }
    }
?>
