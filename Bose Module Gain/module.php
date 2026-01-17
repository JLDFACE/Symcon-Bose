<?php

class BoseModuleGain extends IPSModule {


    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === 0) {
            return;
        }
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float)$old, 3) === round((float)$value, 3)) {
                return;
            }
        } else {
            if ($old === $value) {
                return;
            }
        }
        $this->SetValue($ident, $value);
    }

    private function SetSubscriptions($moduleName, $enable)
    {
        if ($moduleName === "") {
            return;
        }
        $cmd1 = 'GA"' . $moduleName . '">1';
        $cmd2 = 'GA"' . $moduleName . '">2';
        $prefix = $enable ? 'SUB "' : 'UNS "';
        $this->SendCommand($prefix . $cmd1 . '"');
        $this->SendCommand($prefix . $cmd2 . '"');
    }

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        $profileLevelDB = "BoseGainLevelDB";
        $profileLevelPercent = "BoseGainLevelPercent";
        $profileMute = "BoseMuteStatus";

        if (!IPS_VariableProfileExists($profileLevelDB)) {
            IPS_CreateVariableProfile($profileLevelDB, 2);
        }

        IPS_SetVariableProfileText($profileLevelDB, "", "dB");
        IPS_SetVariableProfileValues($profileLevelDB, -60.5, 0, 0.5);
        IPS_SetVariableProfileDigits($profileLevelDB, 1);
        IPS_SetVariableProfileIcon($profileLevelDB, "Speaker");

        if (!IPS_VariableProfileExists($profileLevelPercent)) {
            IPS_CreateVariableProfile($profileLevelPercent, 1);
        }

        IPS_SetVariableProfileText($profileLevelPercent, "", "%");
        IPS_SetVariableProfileIcon($profileLevelPercent, "Speaker");

        if (!IPS_VariableProfileExists($profileMute)) {
            IPS_CreateVariableProfile($profileMute, 0);
        }

        IPS_SetVariableProfileText($profileMute, "", "");
        IPS_SetVariableProfileAssociation($profileMute, 0, "Ton Aus", "Power", 0xff0000);
        IPS_SetVariableProfileAssociation($profileMute, 1, "Ton Ein", "Power", 0x00ff00);

        $this->RegisterVariableFloat("Level", "Level dB", $profileLevelDB, 1);
        $this->EnableAction("Level");

        $this->RegisterVariableBoolean("Mute", "Status", $profileMute, 2);
        $this->EnableAction("Mute");

        $this->RegisterVariableInteger("LevelPercent", "Level %", "~Intensity.100", 3);
        $this->EnableAction("LevelPercent");

        $this->RegisterPropertyString("modulename", "");
        $this->SetBuffer("LastModuleName", "");

        $this->RegisterTimer("PollGain", 30000, "BOSE_PollGain(" . $this->InstanceID . ");");
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->SetTimerInterval("PollGain", 30000);

        $moduleName = (string)IPS_GetProperty($this->InstanceID, "modulename");
        $lastModuleName = (string)$this->GetBuffer("LastModuleName");
        if ($lastModuleName !== $moduleName) {
            $this->SetSubscriptions($lastModuleName, false);
            $this->SetSubscriptions($moduleName, true);
            $this->SetBuffer("LastModuleName", $moduleName);
        }
    }

    public function PollGain()
    {
        $module = IPS_GetProperty($this->InstanceID, "modulename");
        if (strlen($module) == 0) {
            return;
        }

        // Index 1 = Level, Index 2 = Mute
        $this->SendCommand('GA"' . $module . '">1');
        $this->SendCommand('GA"' . $module . '">2');
    }


    public function SetLevel($level) {
        if ($level < -60.5 || $level > 12.0) {
            echo ("Das Level muss mindestens -60.5dB oder maximal +12.0dB betragen.");
            return false;
        }

        $this->SendCommand('SA"' . IPS_GetProperty($this->InstanceID, "modulename") . '">1=' . round($level, 1));
        return true;
    }

    public function SetLevelPercent($level) {
        if ($level < 0 || $level > 100) {
            echo ("Das Level muss mindestens 0% oder maximal 100% betragen.");
            return false;
        }

        return $this->SetLevel((100 - $level) / 100 * -60.5);
    }

    public function SetActive($active) {
        $this->SendCommand('SA"' . IPS_GetProperty($this->InstanceID, "modulename") . '">2=' . (($active) ? 'F' : 'O'));
    }

    public function SendCommand($msg) {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => utf8_encode($msg . "\r")
        ]));
    }

    // Empfangene Daten vom Parent (RX Paket) vom Typ Simpel
    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString, true);
        $data = $data["Buffer"];

        // Check if module name equals instance module name
        if (IPS_GetProperty($this->InstanceID, "modulename") != $data["moduleName"])
            return;

        // Check index ranges
        if ($data["IndexCount"] > 1 || $data["Index1"] < 1 || $data["Index1"] > 2)
            return;

        $index1 = intval($data["Index1"]);

        if ($index1 == 1) {
            $this->SetValueIfChanged("Level", floatval($data["Value"]));
            //print_r(intval(100 - ($data["Value"] / -60.5 * 100)));
            $this->SetValueIfChanged("LevelPercent", intval(100 - ($data["Value"] / -60.5 * 100)));
        } else if ($index1 == 2) {
            $this->SetValueIfChanged("Mute", $data["Value"] == "F");
        }

        // Im Meldungsfenster zu Debug zwecken ausgeben
//        $this->LogMessage(print_r($data, true), KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value) {
        if ($Ident == "Level") {
            $Value = round($Value, 1);
            if (!$this->SetLevel(floatval($Value)))
                return false;

            $this->SetValueIfChanged("LevelPercent", intval(100 - ($Value / -60.5 * 100)));
        } else if ($Ident == "Mute") {
            $this->SetActive($Value);
        } else if ($Ident == "LevelPercent") {
            if (!$this->SetLevelPercent($Value))
                return false;

            $this->SetValueIfChanged("Level", -60.5 + 60.5 * ($Value / 100));
        }

        $this->SetValueIfChanged($Ident, $Value);

        return true;
    }
}
