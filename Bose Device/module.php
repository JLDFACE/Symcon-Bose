<?php
class BoseDevice extends IPSModule
{
    private static $errorCodes = [
        "01" => "Invalid Module Name (no match found or duplicate name)",
        "02" => "Illegal Index (index value or quantity incorrect for specified module)",
        "03" => "Value if out-of-range (value is not permitted for the specified parameter)",
        "99" => "Unknown error"
    ];


    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $profileName = "BoseOnlineStatus";

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, 0);
        }

        IPS_SetVariableProfileAssociation($profileName, true, "Online", "", 0x00ff00);
        IPS_SetVariableProfileAssociation($profileName, false, "Offline", "", 0xff0000);

        $profileName = "BoseParameterSet";

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, 1);
        }

        IPS_SetVariableProfileText($profileName, "", "");
        IPS_SetVariableProfileValues($profileName, 0, 32, 1);
        IPS_SetVariableProfileAssociation($profileName, 0, "Kein Set ausgewählt", "Database", -1);

        for ($i = 1; $i <= 32; $i++) {
            IPS_SetVariableProfileAssociation($profileName, $i, "Set $i", "Database", -1);
        }

        $this->RegisterVariableBoolean("OnlineStatus", "Status", "BoseOnlineStatus", 0);
        $this->RegisterVariableInteger("ParameterSet", "Parameter Set", "BoseParameterSet", 1);
        $this->EnableAction("ParameterSet");

        $this->RegisterTimer("CheckOnlineStatus", 5000, "BOSE_RefreshOnlineStatus(" . $this->InstanceID . ");");
        $this->RegisterTimer("GetLastParameterSet", 10000, 'BOSE_SendCommand(' . $this->InstanceID . ', \'GS\');');
        $this->RegisterTimer("CommandTimeoutCheck", 30000, 'BOSE_CommandTimeoutCheck(' . $this->InstanceID . ');');
$this->RegisterTimer("FlushPending", 500, "BOSE_FlushPending(" . $this->InstanceID . ");");

        $this->SetBuffer("pingTimeouts", 0);
        $this->SetBuffer("LastDeviceResponse", 0);
        $this->SetBuffer("ReconnectBackoffUntil", 0);
        $this->SetBuffer("ClosedByPing", 0);
        $this->SetBuffer("PortHoldoffUntil", 0);
        $this->SetBuffer("PendingCommand", "");

    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
    }

    public function Destroy()
    {
        parent::Destroy();

        $this->SetTimerInterval("CheckOnlineStatus", 0);
    }

    public function RefreshOnlineStatus()
    {
        $pingTimeouts = (int)$this->GetBuffer("pingTimeouts");
        $connId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $host = IPS_GetProperty($connId, "Host");

        if (strlen($host) > 0) {
            $status = Sys_Ping($host, 1000);
            if ($status) {
                $pingTimeouts = 0;
            } else {
                $pingTimeouts++;
            }
        } else {
            $pingTimeouts = 4;
        }

        $isOnline = !($pingTimeouts >= 4);
        $this->SetValue("OnlineStatus", $isOnline);
        $this->SetBuffer("pingTimeouts", $pingTimeouts);

        // Wenn das Gerät als offline erkannt wird, schließen wir den Socket genau einmal,
        // um unnötige ApplyChanges-Schleifen zu vermeiden.
        if (!$isOnline) {
            $closedByPing = (int)$this->GetBuffer("ClosedByPing");
            if ($closedByPing === 0) {
                IPS_SetProperty($connId, "Open", false);
                IPS_ApplyChanges($connId);
                $this->SetBuffer("ClosedByPing", 1);
                // Backoff, damit wir nicht sofort wieder reconnecten
                $this->SetBuffer("ReconnectBackoffUntil", time() + 15);
            }
        } else {
            $this->SetBuffer("ClosedByPing", 0);
        }
    }

    public function CommandTimeoutCheck()
    {
        // Wenn wir länger keine Daten empfangen haben, versuchen wir einen sauberen Reconnect.
        // Nicht zu aggressiv: auf SymBox kann ein zu kurzes Timeout zu Reconnect-Schleifen führen.
        $lastResponse = (int)$this->GetBuffer("LastDeviceResponse");
        if ($lastResponse === 0) {
            return;
        }

        if ($lastResponse >= time() - 45) {
            return;
        }

        $connId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $state = IPS_GetInstance($connId)["InstanceStatus"];

        // Wenn der Socket ohnehin inaktiv ist, nichts tun.
        if ($state == 104) {
            return;
        }

        // Reconnect mit Backoff
        $backoffUntil = (int)$this->GetBuffer("ReconnectBackoffUntil");
        if (time() < $backoffUntil) {
            return;
        }

        IPS_SetProperty($connId, "Open", false);
        IPS_ApplyChanges($connId);

        // nächster Versuch frühestens in 15s
        $this->SetBuffer("ReconnectBackoffUntil", time() + 15);

        $this->LogMessage("No device response for >45s. Triggering reconnect.", KL_WARNING);
    }

    public function RecallParameterSet($setNumber)
    {
        if ($setNumber < 1 || $setNumber > 32) {
            echo("Es können nur ParameterSets von 1 bis 32 ausgewählt werden.");
            return false;
        }

        $hex = dechex($setNumber);
        $this->SendCommand("SS " . $hex);

        return true;
    }

    public function UpdateLastParameterSet()
    {
        $this->SendCommand("GS");
    }

    public function SendCommand($msg)
    {
        if (!$this->GetValue("OnlineStatus")) {
            return false;
        }

        $connId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $state = IPS_GetInstance($connId)["InstanceStatus"];
        if (empty($state)) {
            return false;
        }

        // Port-Holdoff (z.B. wenn das Gerät während der Programmierung den Port schließt)
        $holdoffUntil = (int)$this->GetBuffer("PortHoldoffUntil");
        if (time() < $holdoffUntil) {
            // Nur letztes Kommando merken (Coalescing), damit Fades nicht in eine Queue laufen.
            $this->SetBuffer("PendingCommand", (string)$msg);
            return false;
        }

        // Serialisieren, damit bei vielen Fades nicht mehrere Children gleichzeitig senden.
        if (!IPS_SemaphoreEnter("BOSE_SendCommand_" . $this->InstanceID, 2000)) {
            $this->LogMessage("SendCommand semaphore timeout.", KL_WARNING);
            return false;
        }

        try {
            // Aktiv: sofort senden (keine Drosselung, damit Gains flüssig bleiben).
            if ($state == 102) {
                $this->SendDataToParent(json_encode([
                    'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                    'Buffer' => utf8_encode($msg . "\r")
                ]));
                return true;
            }

            // Inaktiv: Connect anstoßen und Kommando als "last wins" puffern.
            if ($state == 104) {
                $this->SetBuffer("PendingCommand", (string)$msg);

                $backoffUntil = (int)$this->GetBuffer("ReconnectBackoffUntil");
                if (time() < $backoffUntil) {
                    return false;
                }

                IPS_SetProperty($connId, "Open", true);
                IPS_ApplyChanges($connId);

                // kurzer Backoff, um ApplyChanges-Spam bei vielen Fades zu verhindern
                $this->SetBuffer("ReconnectBackoffUntil", time() + 2);
                return false;
            }

            // Fehlerzustand: typischerweise "Connection refused" wenn der Port temporär geschlossen ist.
            if ($state >= 200) {
                $this->SetBuffer("PendingCommand", (string)$msg);

                IPS_SetProperty($connId, "Open", false);
                IPS_ApplyChanges($connId);

                // Holdoff, damit wir während der Programmierung nicht dauernd reconnecten
                $this->SetBuffer("PortHoldoffUntil", time() + 60);
                $this->SetBuffer("ReconnectBackoffUntil", time() + 10);

                $this->LogMessage("Socket error state " . $state . ". Holding off reconnect attempts for 60s (device may be in programming mode).", KL_WARNING);
                return false;
            }

            // Sonstige Zustände: nichts tun, aber puffern.
            $this->SetBuffer("PendingCommand", (string)$msg);
            return false;

        } finally {
            IPS_SemaphoreLeave("BOSE_SendCommand_" . $this->InstanceID);
        }
    }


    
    function FlushPending()
    {
        $pending = (string)$this->GetBuffer("PendingCommand");
        if ($pending === "") {
            return;
        }

        $holdoffUntil = (int)$this->GetBuffer("PortHoldoffUntil");
        if (time() < $holdoffUntil) {
            return;
        }

        $connId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $state = IPS_GetInstance($connId)["InstanceStatus"];

        if ($state == 102) {
            // Sendet genau das zuletzt gepufferte Kommando, damit Fades nicht "stottern".
            $this->SetBuffer("PendingCommand", "");
            $this->SendCommand($pending);
            return;
        }

        if ($state == 104) {
            $backoffUntil = (int)$this->GetBuffer("ReconnectBackoffUntil");
            if (time() < $backoffUntil) {
                return;
            }
            IPS_SetProperty($connId, "Open", true);
            IPS_ApplyChanges($connId);
            $this->SetBuffer("ReconnectBackoffUntil", time() + 2);
            return;
        }

        if ($state >= 200) {
            // Gerät/Port noch nicht bereit
            $this->SetBuffer("PortHoldoffUntil", time() + 60);
            $this->SetBuffer("ReconnectBackoffUntil", time() + 10);
            return;
        }
    }

// Empfangene Daten vom Parent (RX Paket) vom Typ Simpel
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
//        $data['Buffer'] = utf8_decode($data['Buffer']);

        switch ($data["DataID"]) {
            case "{018EF6B5-AB94-40C6-AA53-46943E824ACF}":
                $this->SetBuffer("LastDeviceResponse", time());
                $this->handleSocketInput($data);
                $this->FlushPending();
                break;
        }
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
//        $data['Buffer'] = utf8_decode($data['Buffer']);

        switch ($data["DataID"]) {
            case "{E13A162B-3414-BD54-5C48-F802F8323D2B}":
                $this->SendCommand($data["Buffer"]);
                break;
        }
    }

    private function str_contains($haystack, $needle)
    {
        return strpos($haystack, $needle) !== false;
    }

    private function handleSocketInput($data)
    {
        $temp = $this->GetBuffer("incomingData");
        $temp .= utf8_decode($data['Buffer']);

        $data["hexBuffer"] = bin2hex(utf8_decode($data['Buffer']));
        $data["tempVariable"] = $temp;

        // Im Meldungsfenster zu Debug zwecken ausgeben
//        $this->LogMessage(print_r($data, true), KL_MESSAGE);

        while ($this->str_contains($temp, chr(0x15))) {
            $needlepos = strpos($temp, chr(0x15));

            if ($needlepos + 3 > strlen($temp)) {
                $this->SetBuffer("incomingData", $temp);
                return;
            }

            $errcode = substr($temp, $needlepos + 1, 2);
            $this->LogMessage("Command failed. Error: " . $errcode . ". Message: " . self::$errorCodes[$errcode], KL_ERROR);

            $new = "";
            if ($needlepos != 0)
                $new = substr($temp, 0, $needlepos);

            if ($needlepos + 3 != strlen($temp) - 1)
                $new .= substr($temp, $needlepos + 3);

            $temp = $new;
        }

        $cmds = explode("\r", $temp);
        $substringStart = 0;

        for ($i = 0; $i < count($cmds) - 1; $i++) {
            $connectedCmds = $cmds[$i];
            $innerCmds = explode(";", $connectedCmds);

            for ($j = 0; $j < count($innerCmds); $j++) {
                $cmd = $innerCmds[$j];

                if (strlen($cmd) == 0)
                    continue;

                if (substr($cmd, 0, 2) == "S ") {
                    $split = explode(" ", substr($cmd, 0, strlen($cmd) - 1));
                    $this->SetValue("ParameterSet", intval(hexdec($split[1])));
                } else if (substr($cmd, 0, 2) == "GA") {
                    unset($data["DataID"]);
                    $data["moduleName"] = substr($cmd, 3, strpos(substr($cmd, 3), "\""));
                    $data["IndexCount"] = 1;

                    $dataWithoutName = substr($cmd, strlen($data["moduleName"]) + 5);
                    $index2 = -1;
                    $index3 = -1;

                    if (strpos($dataWithoutName, ">") > 0) {
                        $index1 = substr($dataWithoutName, 0, strpos($dataWithoutName, ">"));
                        $dataWithoutName = substr($dataWithoutName, strpos($dataWithoutName, ">") + 1);
                        $data["IndexCount"]++;

                        if (strpos($dataWithoutName, ">") > 0) {
                            $index2 = substr($dataWithoutName, 0, strpos($dataWithoutName, ">"));
                            $dataWithoutName = substr($dataWithoutName, strpos($dataWithoutName, ">") + 1);
                            $data["IndexCount"]++;

                            $index3 = substr($dataWithoutName, 0, strpos($dataWithoutName, "="));
                        } else {
                            $index2 = substr($dataWithoutName, 0, strpos($dataWithoutName, "="));
                        }
                    } else {
                        $index1 = substr($dataWithoutName, 0, strpos($dataWithoutName, "="));
                    }

                    $data["Index1"] = intval($index1);
                    $data["Index2"] = intval($index2);
                    $data["Index3"] = intval($index3);

                    $data["Value"] = str_replace("\r", "", str_replace(";", "", substr($dataWithoutName, strpos($dataWithoutName, "=") + 1)));

//                $this->LogMessage(print_r($data, true), KL_MESSAGE);

                    $this->SendDataToChildren(json_encode([
                        "DataID" => "{CCC989DC-BF59-BBFD-C02F-BB7D4D815E68}",
                        "Buffer" => $data
                    ]));
                }
            }

            $substringStart += strlen($connectedCmds) + 1;
        }

        if ($substringStart < strlen($temp)) {
            $temp = substr($temp, $substringStart);
        } else if ($substringStart == strlen($temp)) {
            $temp = "";
        }

        $this->SetBuffer("incomingData", $temp);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "ParameterSet") {
            if (!$this->RecallParameterSet($Value))
                return false;
        }

        $this->SetValue($Ident, $Value);

        return true;
    }
}
