<?php

class BoseModuleCRR extends IPSModule
{
    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        // Profiles
        foreach (['BoseGainLevelDB', 'BoseMuteStatus'] as $p) {
            if (!IPS_VariableProfileExists($p)) {
                IPS_CreateVariableProfile($p, $p === 'BoseMuteStatus' ? 0 : 2);
            }
        }
        IPS_SetVariableProfileText('BoseGainLevelDB', '', 'dB');
        IPS_SetVariableProfileValues('BoseGainLevelDB', -60.5, 12.0, 0.5);
        IPS_SetVariableProfileDigits('BoseGainLevelDB', 1);
        IPS_SetVariableProfileIcon('BoseGainLevelDB', 'Speaker');
        IPS_SetVariableProfileAssociation('BoseMuteStatus', 0, 'Ton Aus', 'Power', 0xff0000);
        IPS_SetVariableProfileAssociation('BoseMuteStatus', 1, 'Ton Ein', 'Power', 0x00ff00);

        $this->RegisterPropertyString('ModuleName', '');
        $this->RegisterPropertyInteger('FarEndCount', 0);
        $this->RegisterPropertyBoolean('ShowPreAECMicMix', false);

        $this->RegisterTimer('PollCRR', 0, 'BOSE_PollCRR(' . $this->InstanceID . ');');
        $this->SetBuffer('LastModuleName', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $module = (string)$this->ReadPropertyString('ModuleName');

        if ($module === '') {
            $this->SetStatus(200);
            $this->SetTimerInterval('PollCRR', 0);
            return;
        }

        $farEndCount    = (int)$this->ReadPropertyInteger('FarEndCount');
        $showPreAEC     = (bool)$this->ReadPropertyBoolean('ShowPreAECMicMix');

        // Room/Output variables (Index1=1)
        $pos = 1;
        $this->MaintainVariable('MasterVolume',    'Master Volume',     VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, true); $this->EnableAction('MasterVolume');
        $this->MaintainVariable('MasterMute',      'Master Mute',       VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, true); $this->EnableAction('MasterMute');
        $this->MaintainVariable('MicMixLevel',     'Mic Mix Level',     VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, true); $this->EnableAction('MicMixLevel');
        $this->MaintainVariable('MicMixMute',      'Mic Mix Mute',      VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, true); $this->EnableAction('MicMixMute');
        $this->MaintainVariable('NonMicMixLevel',  'Non-Mic Mix Level', VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, true); $this->EnableAction('NonMicMixLevel');
        $this->MaintainVariable('NonMicMixMute',   'Non-Mic Mix Mute',  VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, true); $this->EnableAction('NonMicMixMute');
        $this->MaintainVariable('PreAECMicMixLevel', 'Pre-AEC Mic Mix Level', VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, $showPreAEC); if ($showPreAEC) $this->EnableAction('PreAECMicMixLevel');
        $this->MaintainVariable('PreAECMicMixMute',  'Pre-AEC Mic Mix Mute',  VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, $showPreAEC); if ($showPreAEC) $this->EnableAction('PreAECMicMixMute');

        // Program/Far End variables (Index1=2)
        $this->MaintainVariable('ProgramLevel', 'Program Level', VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, true); $this->EnableAction('ProgramLevel');
        $this->MaintainVariable('ProgramMute',  'Program Mute',  VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, true); $this->EnableAction('ProgramMute');

        for ($n = 1; $n <= 8; $n++) {
            $keep = $n <= $farEndCount;
            $this->MaintainVariable('FarEnd' . $n . 'Level', 'Far End ' . $n . ' Level', VARIABLETYPE_FLOAT,   'BoseGainLevelDB', $pos++, $keep);
            if ($keep) $this->EnableAction('FarEnd' . $n . 'Level');
            $this->MaintainVariable('FarEnd' . $n . 'Mute',  'Far End ' . $n . ' Mute',  VARIABLETYPE_BOOLEAN, 'BoseMuteStatus',  $pos++, $keep);
            if ($keep) $this->EnableAction('FarEnd' . $n . 'Mute');
        }

        // Update subscriptions when module name changes
        $lastModule = (string)$this->GetBuffer('LastModuleName');
        if ($lastModule !== $module) {
            $this->SetSubscriptions($lastModule, false);
            $this->SetSubscriptions($module, true);
            $this->SetBuffer('LastModuleName', $module);
        }

        $this->SetTimerInterval('PollCRR', 30000);
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
        if ($buf['IndexCount'] < 2) return;

        $idx1  = (int)$buf['Index1'];
        $idx2  = (int)$buf['Index2'];
        $value = (string)$buf['Value'];

        if ($idx1 === 1) {
            $this->HandleRoomOutput($idx2, $value);
        } elseif ($idx1 === 2) {
            $this->HandleProgramFarEnd($idx2, $value);
        }
    }

    // ── Public functions ──────────────────────────────────────

    public function PollCRR()
    {
        $module = $this->ReadPropertyString('ModuleName');
        if ($module === '') return;
        foreach ($this->GetParameterList() as [$idx1, $idx2]) {
            $this->SendCommand('GA"' . $module . '">' . $idx1 . '>' . $idx2);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $module = $this->ReadPropertyString('ModuleName');

        // Index1=1 — Room/Output
        $roomMap = [
            'MasterVolume'      => [1, 'float'],
            'MasterMute'        => [2, 'mute'],
            'MicMixLevel'       => [3, 'float'],
            'MicMixMute'        => [4, 'mute'],
            'NonMicMixLevel'    => [5, 'float'],
            'NonMicMixMute'     => [6, 'mute'],
            'PreAECMicMixLevel' => [7, 'float'],
            'PreAECMicMixMute'  => [8, 'mute'],
        ];
        if (isset($roomMap[$Ident])) {
            [$idx2, $type] = $roomMap[$Ident];
            $v = $type === 'mute' ? ($Value ? 'F' : 'O') : round((float)$Value, 1);
            $this->SendCommand('SA"' . $module . '">1>' . $idx2 . '=' . $v);
            $this->SetValueIfChanged($Ident, $Value);
            return true;
        }

        // Index1=2 — Program
        $progMap = [
            'ProgramLevel' => [1, 'float'],
            'ProgramMute'  => [2, 'mute'],
        ];
        if (isset($progMap[$Ident])) {
            [$idx2, $type] = $progMap[$Ident];
            $v = $type === 'mute' ? ($Value ? 'F' : 'O') : round((float)$Value, 1);
            $this->SendCommand('SA"' . $module . '">2>' . $idx2 . '=' . $v);
            $this->SetValueIfChanged($Ident, $Value);
            return true;
        }

        // Far End Level/Mute
        if (preg_match('/^FarEnd(\d+)(Level|Mute)$/', $Ident, $m)) {
            $n    = (int)$m[1];
            $type = $m[2];
            $idx2 = ($n - 1) * 2 + ($type === 'Level' ? 3 : 4);
            $v    = $type === 'Mute' ? ($Value ? 'F' : 'O') : round((float)$Value, 1);
            $this->SendCommand('SA"' . $module . '">2>' . $idx2 . '=' . $v);
            $this->SetValueIfChanged($Ident, $Value);
            return true;
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

    private function HandleRoomOutput(int $idx2, string $value)
    {
        $map = [
            1 => ['MasterVolume',      'float'],
            2 => ['MasterMute',        'mute'],
            3 => ['MicMixLevel',       'float'],
            4 => ['MicMixMute',        'mute'],
            5 => ['NonMicMixLevel',    'float'],
            6 => ['NonMicMixMute',     'mute'],
            7 => ['PreAECMicMixLevel', 'float'],
            8 => ['PreAECMicMixMute',  'mute'],
        ];
        if (!isset($map[$idx2])) return;
        [$ident, $type] = $map[$idx2];
        $this->SetValueIfChanged($ident, $type === 'mute' ? ($value === 'F') : (float)$value);
    }

    private function HandleProgramFarEnd(int $idx2, string $value)
    {
        if ($idx2 === 1) { $this->SetValueIfChanged('ProgramLevel', (float)$value); return; }
        if ($idx2 === 2) { $this->SetValueIfChanged('ProgramMute',  $value === 'F'); return; }

        // Far End: idx2=3→FE1 Level, idx2=4→FE1 Mute, idx2=5→FE2 Level, ...
        if ($idx2 >= 3) {
            $n    = (int)ceil(($idx2 - 2) / 2);
            $type = ($idx2 % 2 === 1) ? 'Level' : 'Mute';
            $ident = 'FarEnd' . $n . $type;
            $this->SetValueIfChanged($ident, $type === 'Mute' ? ($value === 'F') : (float)$value);
        }
    }

    private function SetSubscriptions(string $module, bool $enable)
    {
        if ($module === '') return;
        $prefix = $enable ? 'SUB "' : 'UNS "';
        foreach ($this->GetParameterList() as [$idx1, $idx2]) {
            $this->SendCommand($prefix . 'GA"' . $module . '">' . $idx1 . '>' . $idx2 . '"');
        }
    }

    private function GetParameterList(): array
    {
        $showPreAEC  = (bool)$this->ReadPropertyBoolean('ShowPreAECMicMix');
        $farEndCount = (int)$this->ReadPropertyInteger('FarEndCount');

        // Index1=1: Room/Output (idx2 1-8)
        $maxIdx2 = $showPreAEC ? 8 : 6;
        $list = [];
        for ($i = 1; $i <= $maxIdx2; $i++) {
            $list[] = [1, $i];
        }

        // Index1=2: Program + Far Ends
        $list[] = [2, 1]; // Program Level
        $list[] = [2, 2]; // Program Mute
        for ($n = 1; $n <= $farEndCount; $n++) {
            $list[] = [2, ($n - 1) * 2 + 3]; // Far End N Level
            $list[] = [2, ($n - 1) * 2 + 4]; // Far End N Mute
        }

        return $list;
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
