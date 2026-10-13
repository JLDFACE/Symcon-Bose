<?php

class BoseModuleMatrix extends IPSModule
{
    // Index 1 laut ControlSpace Serial Protocol (Matrix Mixer)
    const IDX_XP_STATE = 1;   // Crosspoint On/Off (O = On, F = Off)
    const IDX_XP_LEVEL = 2;   // Crosspoint Level (-60.5 .. 0.0 dB)
    const IDX_IN_MUTE  = 3;   // Input Channel Mute (O = Mute an)
    const IDX_OUT_MUTE = 4;   // Output Channel Mute (O = Mute an)

    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        if (!IPS_VariableProfileExists('BoseCrosspoint')) {
            IPS_CreateVariableProfile('BoseCrosspoint', 0);
        }
        IPS_SetVariableProfileIcon('BoseCrosspoint', 'Plug');
        IPS_SetVariableProfileAssociation('BoseCrosspoint', 0, 'Aus', '', -1);
        IPS_SetVariableProfileAssociation('BoseCrosspoint', 1, 'Ein', '', 0x2F72B8);

        if (!IPS_VariableProfileExists('BoseCrosspointLevelDB')) {
            IPS_CreateVariableProfile('BoseCrosspointLevelDB', 2);
        }
        IPS_SetVariableProfileText('BoseCrosspointLevelDB', '', ' dB');
        IPS_SetVariableProfileValues('BoseCrosspointLevelDB', -60.5, 0.0, 0.5);
        IPS_SetVariableProfileDigits('BoseCrosspointLevelDB', 1);
        IPS_SetVariableProfileIcon('BoseCrosspointLevelDB', 'Speaker');

        if (!IPS_VariableProfileExists('BoseMuteStatus')) {
            IPS_CreateVariableProfile('BoseMuteStatus', 0);
        }
        IPS_SetVariableProfileAssociation('BoseMuteStatus', 0, 'Ton Aus', 'Power', 0xff0000);
        IPS_SetVariableProfileAssociation('BoseMuteStatus', 1, 'Ton Ein', 'Power', 0x00ff00);

        $this->RegisterPropertyString('ModuleName', '');
        $this->RegisterPropertyInteger('Inputs', 1);
        $this->RegisterPropertyInteger('Outputs', 1);
        $this->RegisterPropertyString('InputNames', '');
        $this->RegisterPropertyString('OutputNames', '');
        $this->RegisterPropertyBoolean('ShowLevels', false);
        $this->RegisterPropertyBoolean('ShowChannelMutes', false);
        $this->RegisterPropertyInteger('IndexStride', 0);

        $this->RegisterTimer('PollMatrix', 0, 'BOSE_PollMatrix(' . $this->InstanceID . ');');
        $this->SetBuffer('LastSubscriptions', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $module = (string)$this->ReadPropertyString('ModuleName');
        $inputs = $this->GetInputCount();
        $outputs = $this->GetOutputCount();
        $showLevels = (bool)$this->ReadPropertyBoolean('ShowLevels');
        $showMutes = (bool)$this->ReadPropertyBoolean('ShowChannelMutes');

        // Variablen anlegen / benennen
        $inNames = $this->ParseNames($this->ReadPropertyString('InputNames'), $inputs, 'In ');
        $outNames = $this->ParseNames($this->ReadPropertyString('OutputNames'), $outputs, 'Out ');

        $wanted = [];
        $pos = 1;
        for ($i = 1; $i <= $inputs; $i++) {
            for ($o = 1; $o <= $outputs; $o++) {
                $label = $inNames[$i] . ' > ' . $outNames[$o];
                $ident = 'XP_' . $i . '_' . $o;
                $this->MaintainVariable($ident, $label, VARIABLETYPE_BOOLEAN, 'BoseCrosspoint', $pos++, true);
                $this->EnableAction($ident);
                $wanted[$ident] = true;

                if ($showLevels) {
                    $ident = 'XPL_' . $i . '_' . $o;
                    $this->MaintainVariable($ident, $label . ' Pegel', VARIABLETYPE_FLOAT, 'BoseCrosspointLevelDB', $pos++, true);
                    $this->EnableAction($ident);
                    $wanted[$ident] = true;
                }
            }
        }
        if ($showMutes) {
            for ($i = 1; $i <= $inputs; $i++) {
                $ident = 'InMute_' . $i;
                $this->MaintainVariable($ident, $inNames[$i] . ' Ton', VARIABLETYPE_BOOLEAN, 'BoseMuteStatus', $pos++, true);
                $this->EnableAction($ident);
                $wanted[$ident] = true;
            }
            for ($o = 1; $o <= $outputs; $o++) {
                $ident = 'OutMute_' . $o;
                $this->MaintainVariable($ident, $outNames[$o] . ' Ton', VARIABLETYPE_BOOLEAN, 'BoseMuteStatus', $pos++, true);
                $this->EnableAction($ident);
                $wanted[$ident] = true;
            }
        }
        $this->RemoveObsoleteVariables($wanted);

        // Abos nur für geänderte Parameter anpassen
        $newSubs = $module === '' ? [] : $this->BuildGetCommands($module);
        $oldSubs = json_decode((string)$this->GetBuffer('LastSubscriptions'), true);
        if (!is_array($oldSubs)) {
            $oldSubs = [];
        }
        foreach (array_diff($oldSubs, $newSubs) as $cmd) {
            $this->SendCommand('UNS "' . $cmd . '"');
        }
        foreach (array_diff($newSubs, $oldSubs) as $cmd) {
            $this->SendCommand('SUB "' . $cmd . '"');
        }
        $this->SetBuffer('LastSubscriptions', json_encode(array_values($newSubs)));

        if ($module === '') {
            $this->SetTimerInterval('PollMatrix', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('PollMatrix', 300000); // 5 min, Echtzeit kommt über SUB
        $this->SetStatus(102);
    }

    // ── Data from Splitter ────────────────────────────────────

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer']) || !is_array($data['Buffer'])) return;
        $buf = $data['Buffer'];

        if (!isset($buf['moduleName'])) return;
        if ($buf['moduleName'] !== $this->ReadPropertyString('ModuleName')) return;
        if ((int)$buf['IndexCount'] !== 2) return;

        $idx1 = (int)$buf['Index1'];
        $idx2 = (int)$buf['Index2'];
        $value = trim((string)$buf['Value']);

        switch ($idx1) {
            case self::IDX_XP_STATE:
                $xp = $this->IndexToCrosspoint($idx2);
                if ($xp !== null) {
                    $this->SetValueIfChanged('XP_' . $xp[0] . '_' . $xp[1], $value === 'O');
                }
                break;
            case self::IDX_XP_LEVEL:
                $xp = $this->IndexToCrosspoint($idx2);
                if ($xp !== null) {
                    $this->SetValueIfChanged('XPL_' . $xp[0] . '_' . $xp[1], (float)$value);
                }
                break;
            case self::IDX_IN_MUTE:
                $this->SetValueIfChanged('InMute_' . $idx2, $value === 'F');
                break;
            case self::IDX_OUT_MUTE:
                $this->SetValueIfChanged('OutMute_' . $idx2, $value === 'F');
                break;
        }
    }

    // ── Public functions ──────────────────────────────────────

    public function PollMatrix()
    {
        $module = $this->ReadPropertyString('ModuleName');
        if ($module === '') return;
        foreach ($this->BuildGetCommands($module) as $cmd) {
            $this->SendCommand($cmd);
        }
    }

    public function SetCrosspoint(int $input, int $output, bool $on)
    {
        if (!$this->CheckCrosspoint($input, $output)) return false;
        $this->SendCommand($this->SetPrefix(self::IDX_XP_STATE, $this->CrosspointToIndex($input, $output)) . ($on ? 'O' : 'F'));
        $this->SetValueIfChanged('XP_' . $input . '_' . $output, $on);
        return true;
    }

    public function SetCrosspointLevel(int $input, int $output, float $level)
    {
        if (!$this->CheckCrosspoint($input, $output)) return false;
        if ($level < -60.5 || $level > 0.0) {
            echo 'Der Crosspoint-Pegel muss zwischen -60.5 dB und 0.0 dB liegen.';
            return false;
        }
        $level = round($level * 2) / 2;
        $this->SendCommand($this->SetPrefix(self::IDX_XP_LEVEL, $this->CrosspointToIndex($input, $output)) . number_format($level, 1, '.', ''));
        $this->SetValueIfChanged('XPL_' . $input . '_' . $output, $level);
        return true;
    }

    public function SetInputActive(int $input, bool $active)
    {
        if ($input < 1 || $input > $this->GetInputCount()) {
            echo 'Ungültiger Eingang.';
            return false;
        }
        $this->SendCommand($this->SetPrefix(self::IDX_IN_MUTE, $input) . ($active ? 'F' : 'O'));
        $this->SetValueIfChanged('InMute_' . $input, $active);
        return true;
    }

    public function SetOutputActive(int $output, bool $active)
    {
        if ($output < 1 || $output > $this->GetOutputCount()) {
            echo 'Ungültiger Ausgang.';
            return false;
        }
        $this->SendCommand($this->SetPrefix(self::IDX_OUT_MUTE, $output) . ($active ? 'F' : 'O'));
        $this->SetValueIfChanged('OutMute_' . $output, $active);
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        if (preg_match('/^XP_(\d+)_(\d+)$/', $Ident, $m)) {
            return $this->SetCrosspoint((int)$m[1], (int)$m[2], (bool)$Value);
        }
        if (preg_match('/^XPL_(\d+)_(\d+)$/', $Ident, $m)) {
            return $this->SetCrosspointLevel((int)$m[1], (int)$m[2], (float)$Value);
        }
        if (preg_match('/^InMute_(\d+)$/', $Ident, $m)) {
            return $this->SetInputActive((int)$m[1], (bool)$Value);
        }
        if (preg_match('/^OutMute_(\d+)$/', $Ident, $m)) {
            return $this->SetOutputActive((int)$m[1], (bool)$Value);
        }
        return false;
    }

    public function SendCommand(string $msg)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => $msg . "\r"
        ]));
    }

    // ── Private ───────────────────────────────────────────────

    private function GetInputCount(): int
    {
        return max(1, min(32, (int)$this->ReadPropertyInteger('Inputs')));
    }

    private function GetOutputCount(): int
    {
        return max(1, min(32, (int)$this->ReadPropertyInteger('Outputs')));
    }

    private function GetStride(): int
    {
        $stride = (int)$this->ReadPropertyInteger('IndexStride');
        return $stride > 0 ? $stride : $this->GetOutputCount();
    }

    private function CrosspointToIndex(int $input, int $output): int
    {
        return ($input - 1) * $this->GetStride() + $output;
    }

    private function IndexToCrosspoint(int $index)
    {
        if ($index < 1) return null;
        $stride = $this->GetStride();
        $input = intdiv($index - 1, $stride) + 1;
        $output = ($index - 1) % $stride + 1;
        if ($input > $this->GetInputCount() || $output > $this->GetOutputCount()) return null;
        return [$input, $output];
    }

    private function CheckCrosspoint(int $input, int $output): bool
    {
        if ($input < 1 || $input > $this->GetInputCount() || $output < 1 || $output > $this->GetOutputCount()) {
            echo 'Ungültiger Crosspoint (' . $input . '/' . $output . ').';
            return false;
        }
        return true;
    }

    private function SetPrefix(int $idx1, int $idx2): string
    {
        return 'SA"' . $this->ReadPropertyString('ModuleName') . '">' . $idx1 . '>' . $idx2 . '=';
    }

    private function BuildGetCommands(string $module): array
    {
        $inputs = $this->GetInputCount();
        $outputs = $this->GetOutputCount();
        $showLevels = (bool)$this->ReadPropertyBoolean('ShowLevels');
        $showMutes = (bool)$this->ReadPropertyBoolean('ShowChannelMutes');

        $cmds = [];
        for ($i = 1; $i <= $inputs; $i++) {
            for ($o = 1; $o <= $outputs; $o++) {
                $idx2 = $this->CrosspointToIndex($i, $o);
                $cmds[] = 'GA"' . $module . '">' . self::IDX_XP_STATE . '>' . $idx2;
                if ($showLevels) {
                    $cmds[] = 'GA"' . $module . '">' . self::IDX_XP_LEVEL . '>' . $idx2;
                }
            }
        }
        if ($showMutes) {
            for ($i = 1; $i <= $inputs; $i++) {
                $cmds[] = 'GA"' . $module . '">' . self::IDX_IN_MUTE . '>' . $i;
            }
            for ($o = 1; $o <= $outputs; $o++) {
                $cmds[] = 'GA"' . $module . '">' . self::IDX_OUT_MUTE . '>' . $o;
            }
        }
        return $cmds;
    }

    private function ParseNames(string $csv, int $count, string $fallback): array
    {
        $parts = array_map('trim', explode(',', $csv));
        $names = [];
        for ($n = 1; $n <= $count; $n++) {
            $name = isset($parts[$n - 1]) ? $parts[$n - 1] : '';
            $names[$n] = $name !== '' ? $name : $fallback . $n;
        }
        return $names;
    }

    private function RemoveObsoleteVariables(array $wanted)
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== 2) continue;
            $ident = $obj['ObjectIdent'];
            if (!preg_match('/^(XP|XPL)_\d+_\d+$|^(InMute|OutMute)_\d+$/', $ident)) continue;
            if (!isset($wanted[$ident])) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    private function SetValueIfChanged(string $ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if (!$vid) return;
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float)$old, 3) === round((float)$value, 3)) return;
        } else {
            if ($old === $value) return;
        }
        $this->SetValue($ident, $value);
    }
}
