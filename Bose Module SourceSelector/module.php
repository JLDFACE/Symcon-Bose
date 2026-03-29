<?php

class BoseModuleSourceSelector extends IPSModule {


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
        $cmd = 'GA"' . $moduleName . '">1';
        $prefix = $enable ? 'SUB "' : 'UNS "';
        $this->SendCommand($prefix . $cmd . '"');
    }

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        $profileSource = "BoseSource";

        if (!IPS_VariableProfileExists($profileSource)) {
            IPS_CreateVariableProfile($profileSource, 1);
        }

        IPS_SetVariableProfileText($profileSource, "Eingang ", "");
        IPS_SetVariableProfileValues($profileSource, 1, 16, 1);

        for ($i = 1; $i <= 16; $i++)
            IPS_SetVariableProfileAssociation($profileSource, $i, "$i", "Plug", -1);

        $this->RegisterVariableInteger("Source", "Quelle", $profileSource, 1);
        $this->EnableAction("Source");

        $this->RegisterPropertyString("modulename", "");
        $this->RegisterPropertyInteger("sourcecount", 1);
        $this->SetBuffer("LastModuleName", "");

        $this->RegisterTimer("PollSource", 30000, "BOSE_PollSource(" . $this->InstanceID . ");");
        $this->RegisterTimer("BurstPoll", 0, "BOSE_BurstPoll(" . $this->InstanceID . ");");
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->SetTimerInterval("PollSource", 30000);
        $this->SetTimerInterval("BurstPoll", 0);

        $moduleName = (string)$this->ReadPropertyString("modulename");
        $lastModuleName = (string)$this->GetBuffer("LastModuleName");
        if ($lastModuleName !== $moduleName) {
            $this->SetSubscriptions($lastModuleName, false);
            $this->SetSubscriptions($moduleName, true);
            $this->SetBuffer("LastModuleName", $moduleName);
        }
    }

    public function PollSource()
    {
        $module = $this->ReadPropertyString("modulename");
        if (strlen($module) == 0) {
            return;
        }

        $this->SendCommand('GA"' . $module . '">1');
    }

    private function StartBurstPolling($seconds)
    {
        $this->SetBuffer("BurstUntil", (string)(time() + (int)$seconds));
        $this->SetTimerInterval("BurstPoll", 500);
    }

    public function BurstPoll()
    {
        $until = (int)$this->GetBuffer("BurstUntil");
        if ($until === 0 || time() > $until) {
            $this->SetTimerInterval("BurstPoll", 0);
            $this->SetBuffer("BurstUntil", "0");
            return;
        }

        $this->PollSource();
    }


    public function SetSource(int $source) {
        if ($source < 1 || $source > intval($this->ReadPropertyInteger("sourcecount"))) {
            echo("Dieses Modul besitzt nur " . $this->ReadPropertyInteger("sourcecount") . " Eingänge.");
            return false;
        }

        $this->SendCommand('SA"' . $this->ReadPropertyString("modulename") . '">1=' . $source);
    
        $this->StartBurstPolling(3);
}

    public function SendCommand(string $msg) {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => $msg . "\r"
        ]));
    }

    // Empfangene Daten vom Parent (RX Paket) vom Typ Simpel
    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString, true);
        $data = $data["Buffer"];

        if (!isset($data["moduleName"]))
            return;

        // Check if module name equals instance module name
        if ($this->ReadPropertyString("modulename") != $data["moduleName"])
            return;

        // Check index ranges
        if ($data["IndexCount"] > 1 || $data["Index1"] != 1)
            return;

        if ($data["Index1"] == 1) {
            $this->SetValueIfChanged("Source", intval($data["Value"]));
        }

        // Im Meldungsfenster zu Debug zwecken ausgeben
//        $this->LogMessage(print_r($data, true), KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value) {
        if ($Ident == "Source") {
            $this->SetSource($Value);
        } else {
            return false;
        }

        $this->SetValueIfChanged($Ident, $Value);

        return true;
    }
}
