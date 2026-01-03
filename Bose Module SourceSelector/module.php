<?php

class BoseModuleSourceSelector extends IPSModule {

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

        $this->RegisterTimer("GetSource", 1000, 'if (strlen(IPS_GetProperty(' . $this->InstanceID . ', "modulename")) > 0) BOSE_SendCommand(' . $this->InstanceID . ', \'GA"\' . IPS_GetProperty(' . $this->InstanceID . ', "modulename") . \'">1\');');
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
    }

    public function SetSource($source) {
        if ($source < 1 || $source > intval(IPS_GetProperty($this->InstanceID, "sourcecount"))) {
            echo("Dieses Modul besitzt nur " . IPS_GetProperty($this->InstanceID, "sourcecount") . " Eingänge.");
            return false;
        }

        $this->SendCommand('SA"' . IPS_GetProperty($this->InstanceID, "modulename") . '">1=' . $source);
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
        if ($data["IndexCount"] > 1 || $data["Index1"] != 1)
            return;

        if ($data["Index1"] == 1) {
            $this->SetValue("Source", floatval($data["Value"]));
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

        $this->SetValue($Ident, $Value);

        return true;
    }
}