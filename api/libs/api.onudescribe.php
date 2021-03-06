<?php

class OnuDescribe {

    /**
     * Contains system alter config as key=>value
     *
     * @var array
     */
    protected $altCfg = array();

    /**
     * Contains user's onu data from pononu table
     * 
     * @var array
     */
    protected $onuData = array();

    /**
     * Contains OLT data (where user's onu is linked to OLT)
     * 
     * @var array
     */
    protected $oltData = array();

    /**
     * Contains OLT snmp template file name
     * 
     * @var array
     */
    protected $oltSnmptemplate = array();

    /**
     * Contain's OLT switch ID
     * 
     * @var int
     */
    protected $oltId = '';

    /**
     * User's login
     * 
     * @var string
     */
    protected $login = '';

    /**
     * Placeholder for snmp class
     * 
     * @var pointer
     */
    protected $snmp = '';

    public function __construct($login = '') {
        if (!empty($login)) {
            $this->LoadAlter();
            $this->login = $login;
            $this->GetOnuData($login);
            $this->snmp = new SNMPHelper;
            if (!empty($this->oltId)) {
                $this->GetOltData($this->oltId);
            }
            if (!empty($this->oltData)) {
                $this->GetOltModelData($this->oltData['modelid']);
            }
        }
    }

    /**
     * load alter.ini config     
     * 
     * @return void
     */
    protected function LoadAlter() {
        global $ubillingConfig;
        $this->altCfg = $ubillingConfig->getAlter();
    }

    /**
     * Get onu data mac and olt ID to which onu is linked
     * 
     * @param string $login 
     */
    protected function GetOnuData($login) {
        $query = "SELECT * FROM `pononu` WHERE `login` = '$login'";
        $data = simple_query($query);
        if (!empty($data)) {
            $this->oltId = $data['oltid'];
            $this->onuData = $data;
        }
    }

    /**
     * Loads data from table `switches` to $oltData var (filter by OLT switch ID)
     * 
     * @param int $oltID
     */
    protected function GetOltData($oltID) {
        $query = "SELECT * FROM `switches` WHERE `id`='$oltID'";
        $data = simple_query($query);
        if (!empty($data)) {
            $this->oltData = $data;
        }
    }

    /**
     * Loads data from table `switchmodels` to $oltSnmptemplate (filter by OLT switch model id)
     * 
     * @param int $modelID
     */
    protected function GetOltModelData($modelID) {
        $query = "SELECT * FROM `switchmodels` WHERE `id`='$modelID'";
        $data = simple_query($query);
        if (!empty($data)) {
            $this->oltSnmptemplate = $data['snmptemplate'];
        }
    }

    /**
     * Format heximal mac address to decimal or show error
     * 
     * @param string $macOnu 
     * 
     * @return string
     */
    protected function MacHexToDec($macOnu) {
        if (check_mac_format($macOnu)) {
            $res = array();
            $args = explode(":", $macOnu);
            foreach ($args as $each) {
                $res[] = hexdec($each);
            }
            $string = implode(".", $res);
            return ($string);
        } else {
            show_error("Wrong mac format (shoud be XX:XX:XX:XX:XX:XX)");
        }
    }

    public function GetOnuDescription() {
        if (!empty($this->onuData) AND ! empty($this->oltData) AND ! empty($this->oltSnmptemplate)) {
            $eponInt = '';
            $macOnu = $this->onuData['mac'];
            $decMacOnu = $this->MacHexToDec($macOnu);
            if (!file_exists(CONFIG_PATH . "/snmptemplates/" . $this->oltSnmptemplate)) {
                return false;
            }
            $snmpData = rcms_parse_ini_file(CONFIG_PATH . "/snmptemplates/" . $this->oltSnmptemplate, true);
            if (!isset($snmpData['onu']['DESCRIPTION'])) {
                return false;
            }
            if (!isset($snmpData['vlan']['SAVE'])) {
                return false;
            }
            if (!isset($snmpData['onu']['EPONINDEX'])) {
                return false;
            }
            if (!isset($snmpData['onu']['IFINDEX'])) {
                return false;
            }
            if ($snmpData['vlan']['VLANMODE'] == 'BDCOM_B') {
                $ifIndexOid = $snmpData['onu']['IFINDEX'] . '.' . $decMacOnu;
                $ifIndexFull = snmp2_get($this->oltData['ip'], $this->oltData['snmp'], $ifIndexOid);
                $ifIndex = trim(str_replace(array($ifIndexOid, 'INTEGER:', '= '), '', $ifIndexFull));
                if (!empty($ifIndex)) {
                    $eponIntBare = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['EPONINDEX'] . '.' . $ifIndex);
                    $eponInt = trim(str_replace(array($snmpData['onu']['EPONINDEX'] . '.' . $ifIndex, ' = INTEGER: '), '', $eponIntBare));
                }
                if (!empty($eponInt)) {
                    $descriptionOid = $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu;
                    $checkResult = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $descriptionOid, FALSE);
                    $Result = trim(str_replace(array($descriptionOid, ' = STRING: '), '', $checkResult));
                    if (!empty($Result)) {
                        return $Result;
                    }
                }
            }
            if ($snmpData['vlan']['VLANMODE'] == 'BDCOM_C') {
                $allOnuOid = $snmpData['signal']['MACINDEX'];
                snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
                $allOnu = @snmp2_real_walk($this->oltData['ip'], $this->oltData['snmp'], $allOnuOid);
                $searchArray = array();
                if (!empty($allOnu)) {
                    foreach ($allOnu as $eachIndex => $eachOnu) {
                        $eachIndex = trim(str_replace($allOnuOid . '.', '', $eachIndex));
                        $eachOnu = strtolower(trim(str_replace($snmpData['signal']['MACVALUE'], '', $eachOnu)));
                        $eachOnuMacArray = explode(" ", $eachOnu);
                        $eachOnuMac = implode(":", $eachOnuMacArray);
                        $searchArray[$eachOnuMac] = $eachIndex;
                    }
                    if (!empty($searchArray) and isset($searchArray[$macOnu])) {
                        $ifIndex = $searchArray[$macOnu];
                        $eponIntBare = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['EPONINDEX'] . '.' . $ifIndex);
                        $eponInt = trim(str_replace(array($snmpData['onu']['EPONINDEX'] . '.' . $ifIndex, ' = INTEGER: '), '', $eponIntBare));
                    }
                    if (!empty($eponInt)) {
                        $descriptionOid = $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu;
                        $checkResult = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $descriptionOid, FALSE);
                        $Result = trim(str_replace(array($descriptionOid, ' = STRING: '), '', $checkResult));
                        if (!empty($Result)) {
                            return $Result;
                        }
                    }
                }
            }
        }
    }

    public function DescribeOnu($description) {
        if (!empty($this->onuData) AND ! empty($this->oltData) AND ! empty($this->oltSnmptemplate)) {
            $eponInt = '';
            $macOnu = $this->onuData['mac'];
            $decMacOnu = $this->MacHexToDec($macOnu);
            if (!file_exists(CONFIG_PATH . "/snmptemplates/" . $this->oltSnmptemplate)) {
                return false;
            }
            $snmpData = rcms_parse_ini_file(CONFIG_PATH . "/snmptemplates/" . $this->oltSnmptemplate, true);
            if (!isset($snmpData['onu']['DESCRIPTION'])) {
                return false;
            }
            if (!isset($snmpData['vlan']['SAVE'])) {
                return false;
            }
            if (!isset($snmpData['onu']['EPONINDEX'])) {
                return false;
            }
            if (!isset($snmpData['onu']['IFINDEX'])) {
                return false;
            }
            if ($snmpData['vlan']['VLANMODE'] == 'BDCOM_B') {
                $ifIndexOid = $snmpData['onu']['IFINDEX'] . '.' . $decMacOnu;
                $ifIndexFull = snmp2_get($this->oltData['ip'], $this->oltData['snmp'], $ifIndexOid);
                $ifIndex = trim(str_replace(array($ifIndexOid, 'INTEGER:', '= '), '', $ifIndexFull));
                if (!empty($ifIndex)) {
                    $eponIntBare = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['EPONINDEX'] . '.' . $ifIndex);
                    $eponInt = trim(str_replace(array($snmpData['onu']['EPONINDEX'] . '.' . $ifIndex, ' = INTEGER: '), '', $eponIntBare));
                }
                if (!empty($eponInt)) {
                    $describeData[] = array(
                        'oid' => $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu,
                        'type' => 's',
                        'value' => '"' . addcslashes($description, '_') . '"',
                    );
                    $describeData[] = array(
                        'oid' => $snmpData['vlan']['SAVE'],
                        'type' => 'i',
                        'value' => '1'
                    );
                    $checkResult = $this->snmp->set($this->oltData['ip'], $this->oltData['snmpwrite'], $describeData);
                    $checkResult .= $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu, FALSE);
                    if (!empty($checkResult)) {
                        return $checkResult;
                    }
                }
                return false;
            }

            if ($snmpData['vlan']['VLANMODE'] == 'BDCOM_C') {
                $allOnuOid = $snmpData['signal']['MACINDEX'];
                snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
                $allOnu = @snmp2_real_walk($this->oltData['ip'], $this->oltData['snmp'], $allOnuOid);
                $searchArray = array();
                if (!empty($allOnu)) {
                    foreach ($allOnu as $eachIndex => $eachOnu) {
                        $eachIndex = trim(str_replace($allOnuOid . '.', '', $eachIndex));
                        $eachOnu = strtolower(trim(str_replace($snmpData['signal']['MACVALUE'], '', $eachOnu)));
                        $eachOnuMacArray = explode(" ", $eachOnu);
                        $eachOnuMac = implode(":", $eachOnuMacArray);
                        $searchArray[$eachOnuMac] = $eachIndex;
                    }
                    if (!empty($searchArray) and isset($searchArray[$macOnu])) {
                        $ifIndex = $searchArray[$macOnu];
                        $eponIntBare = $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['EPONINDEX'] . '.' . $ifIndex);
                        $eponInt = trim(str_replace(array($snmpData['onu']['EPONINDEX'] . '.' . $ifIndex, ' = INTEGER: '), '', $eponIntBare));
                    }
                    if (!empty($eponInt)) {
                        $describeData[] = array(
                            'oid' => $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu,
                            'type' => 's',
                            'value' => '"' . addcslashes($description, '_') . '"',
                        );
                        $describeData[] = array(
                            'oid' => $snmpData['vlan']['SAVE'],
                            'type' => 'i',
                            'value' => '1'
                        );
                        $checkResult = $this->snmp->set($this->oltData['ip'], $this->oltData['snmpwrite'], $describeData);
                        $checkResult .= $this->snmp->walk($this->oltData['ip'], $this->oltData['snmp'], $snmpData['onu']['DESCRIPTION'] . '.' . $eponInt . '.' . $decMacOnu, FALSE);
                        if (!empty($checkResult)) {
                            return $checkResult;
                        }
                    }
                }
                return false;
            }
        }
    }

    public function DescribeForm($login) {
        $DescriptionInputId = wf_InputId();
        $Inputs = wf_delimiter();
        $Inputs .= wf_tag('input', false, '', 'type="text" name="onuDescription" value="' . $login . '" id="' . $DescriptionInputId . '" size="60" style="margin-left: 30px;"');
        $Inputs .= wf_tag('label', false, '', 'for ="' . $DescriptionInputId . '"') . __('Description') . wf_tag('label', true);
        $Inputs .= wf_delimiter();
        $Inputs .= wf_SubmitClassed('true', 'vlanButton', 'DescribeOnu', __('Change onu description'));
        $Form = wf_Form("", 'POST', $Inputs);
        return($Form);
    }

}
