<?php

//just dummy module for testing purposes
error_reporting(E_ALL);

$wcpe = new WifiCPE();


//rendering available CPE list
if (wf_CheckGet(array('ajcpelist'))) {
    $wcpe->getCPEListJson();
}

//creating new CPE
if (wf_CheckPost(array('createnewcpe', 'newcpemodelid'))) {
    $newCpeBridge = (wf_CheckPost(array('newcpebridge'))) ? true : false;
    $creationResult = $wcpe->createCPE($_POST['newcpemodelid'], $_POST['newcpeip'], $_POST['newcpemac'], $_POST['newcpelocation'], $newCpeBridge, $_POST['newcpeuplinkapid'], $_POST['newcpegeo']);
    if (empty($creationResult)) {
        rcms_redirect($wcpe::URL_ME);
    } else {
        show_window(__('Something went wrong'), $creationResult);
    }
}

//CPE deletion
if (wf_CheckGet(array('deletecpeid'))) {
    $deletionResult = $wcpe->deleteCPE($_GET['deletecpeid']);
    if (empty($deletionResult)) {
        rcms_redirect($wcpe::URL_ME);
    } else {
        show_window(__('Something went wrong'), $deletionResult);
    }
}

//CPE editing
if (wf_CheckPost(array('editcpe'))) {
    $saveResult = $wcpe->saveCPE();
    if (empty($saveResult)) {
        rcms_redirect($wcpe::URL_ME . '&editcpeid=' . $_POST['editcpe']);
    } else {
        show_window(__('Something went wrong'), $saveResult);
    }
}

//CPE assign deletion
if (wf_CheckGet(array('deleteassignid', 'tocpe'))) {
    $assignDeleteResult = $wcpe->deassignCPEUser($_GET['deleteassignid']);
    if (empty($assignDeleteResult)) {
        rcms_redirect($wcpe::URL_ME . '&editcpeid=' . $_GET['tocpe']);
    } else {
        show_window(__('Something went wrong'), $assignDeleteResult);
    }
}


if (wf_CheckGet(array('editcpeid'))) {
    show_window(__('Edit') . ' ' . __('CPE'), $wcpe->renderCPEEditForm($_GET['editcpeid']));
    show_window(__('Linked users'), $wcpe->renderCPEAssignedUsers($_GET['editcpeid']));
    show_window('', wf_BackLink($wcpe::URL_ME));
} else {
    show_window('', $wcpe->panel());
    show_window(__('Available CPE list'), $wcpe->renderCPEList());
}
?>