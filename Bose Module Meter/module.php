<?php

class BoseModuleMeter extends IPSModule
{
    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        // Meter channel list: [{Position, Slot, Channel, Label}]
        $this->RegisterPropertyString('MeterChannels', '[]');

        // Display settings
        $this->RegisterPropertyInteger('YellowThreshold', -24);
        $this->RegisterPropertyInteger('RedThreshold', -10);
        $this->RegisterPropertyInteger('MeterHeight', 180);
        $this->RegisterPropertyInteger('UpdateInterval', 200);
        $this->RegisterPropertyInteger('BackgroundColor', 0x1a1a2e);
        $this->RegisterPropertyInteger('BackgroundOpacity', 100);

        // Timer: poll GL + render HTML
        $this->RegisterTimer('MeterUpdate', 0, 'BOSE_UpdateMeterHTML(' . $this->InstanceID . ');');

        // Buffer for current meter levels, keyed by "slot:channel"
        $this->SetBuffer('MeterLevels', '{}');
        $this->SetBuffer('PeakLevels', '{}');
        $this->SetBuffer('PrevDisplayLevels', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $channels = $this->GetMeterChannels();

        if (count($channels) === 0) {
            $this->SetStatus(200);
            $this->SetTimerInterval('MeterUpdate', 0);
            return;
        }

        $this->MaintainVariable('LevelMeter', 'Level Meter', VARIABLETYPE_STRING, '~HTMLBox', 0, true);

        // Float variable per channel
        foreach ($channels as $ch) {
            $ident = 'Level_' . (int)$ch['Position'];
            $label = (string)$ch['Label'] !== '' ? (string)$ch['Label'] : 'Slot ' . $ch['Slot'] . ' Ch ' . $ch['Channel'];
            $this->MaintainVariable($ident, $label, VARIABLETYPE_FLOAT, 'BoseGainLevelDB', (int)$ch['Position'] + 1, true);
        }

        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('MeterUpdate', max(100, $interval));

        $this->RenderAndSetHTML();
        $this->SetStatus(102);
    }

    // ── Data from Splitter ────────────────────────────────────

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer']) || !is_array($data['Buffer'])) {
            return;
        }

        $buffer = $data['Buffer'];

        // Only handle GL responses
        if (!isset($buffer['command']) || $buffer['command'] !== 'GL') {
            return;
        }

        $slot = (int)$buffer['slot'];
        $levels = $buffer['levels'];

        $this->SendDebug('Meter.GL', 'slot=' . $slot . ' levels=' . json_encode($levels), 0);

        $channels = $this->GetMeterChannels();
        foreach ($channels as $ch) {
            if ((int)$ch['Slot'] === $slot) {
                $chNum = (int)$ch['Channel'];
                $idx = $chNum - 1;
                if (isset($levels[$idx])) {
                    $db = (float)$levels[$idx];
                    $key = $slot . ':' . $chNum;
                    $this->UpdateMeterLevel($key, $db);
                    $ident = 'Level_' . (int)$ch['Position'];
                    $this->SetValueIfChanged($ident, $db);
                }
            }
        }
    }

    // ── Public functions ──────────────────────────────────────

    public function UpdateMeterHTML()
    {
        $this->PollMeters();
        $this->RenderAndSetHTML();
    }

    // ── Private: Protocol ─────────────────────────────────────

    private function PollMeters()
    {
        // Collect unique slots and poll each once
        $slots = [];
        foreach ($this->GetMeterChannels() as $ch) {
            $slots[(int)$ch['Slot']] = true;
        }
        foreach (array_keys($slots) as $slot) {
            $cmd = 'GL ' . $slot;
            $this->SendCommand($cmd);
            $this->SendDebug('Meter.Poll', $cmd, 0);
        }
    }

    private function SendCommand(string $msg)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => $msg . "\r"
        ]));
    }

    // ── Private: State Management ─────────────────────────────

    private function UpdateMeterLevel(string $key, float $db)
    {
        $levels = json_decode($this->GetBuffer('MeterLevels'), true);
        if (!is_array($levels)) $levels = [];
        $levels[$key] = round($db, 1);
        $this->SetBuffer('MeterLevels', json_encode($levels));

        $peaks = json_decode($this->GetBuffer('PeakLevels'), true);
        if (!is_array($peaks)) $peaks = [];
        $currentPeak = isset($peaks[$key]) ? (float)$peaks[$key] : -60.0;
        if ($db > $currentPeak) {
            $peaks[$key] = round($db, 1);
        } else {
            $peaks[$key] = round($currentPeak - 0.3, 1);
        }
        $this->SetBuffer('PeakLevels', json_encode($peaks));
    }

    private function GetMeterChannels()
    {
        $channels = json_decode($this->ReadPropertyString('MeterChannels'), true);
        if (!is_array($channels)) return [];
        usort($channels, function ($a, $b) {
            return (int)$a['Position'] - (int)$b['Position'];
        });
        return $channels;
    }

    // ── Private: HTML Rendering ───────────────────────────────

    private function RenderAndSetHTML()
    {
        $channels = $this->GetMeterChannels();
        $levels = json_decode($this->GetBuffer('MeterLevels'), true);
        $peaks = json_decode($this->GetBuffer('PeakLevels'), true);
        $prevDisplay = json_decode($this->GetBuffer('PrevDisplayLevels'), true);
        if (!is_array($levels)) $levels = [];
        if (!is_array($peaks)) $peaks = [];
        if (!is_array($prevDisplay)) $prevDisplay = [];

        $yellowDb = (int)$this->ReadPropertyInteger('YellowThreshold');
        $redDb = (int)$this->ReadPropertyInteger('RedThreshold');
        $height = (int)$this->ReadPropertyInteger('MeterHeight');
        $interval = max(100, (int)$this->ReadPropertyInteger('UpdateInterval'));
        $bgInt = (int)$this->ReadPropertyInteger('BackgroundColor') & 0xFFFFFF;
        $opacity = max(0, min(100, (int)$this->ReadPropertyInteger('BackgroundOpacity'))) / 100.0;
        $bgColor = sprintf('rgba(%d,%d,%d,%.2f)', ($bgInt >> 16) & 0xFF, ($bgInt >> 8) & 0xFF, $bgInt & 0xFF, $opacity);

        // Ballistic display values: instant attack, linear dB decay (10 dB/s)
        $decayPerTick = 10.0 * $interval / 1000.0;
        $displayLevels = [];
        foreach ($channels as $ch) {
            $key = (int)$ch['Slot'] . ':' . (int)$ch['Channel'];
            $real = isset($levels[$key]) ? (float)$levels[$key] : -60.0;
            $prev = isset($prevDisplay[$key]) ? (float)$prevDisplay[$key] : $real;
            $displayLevels[$key] = $real >= $prev ? $real : max($real, $prev - $decayPerTick);
        }
        $this->SetBuffer('PrevDisplayLevels', json_encode($displayLevels));

        $html = $this->BuildMeterHTML($channels, $displayLevels, $peaks, $yellowDb, $redDb, $height, $bgColor);
        $this->SetValueIfChanged('LevelMeter', $html);
    }

    private function BuildMeterHTML(array $channels, array $displayLevels, array $peaks, $yellowDb, $redDb, $height, $bgColor)
    {
        $meterData = [];
        foreach ($channels as $ch) {
            $key = (int)$ch['Slot'] . ':' . (int)$ch['Channel'];
            $label = (string)$ch['Label'] !== '' ? (string)$ch['Label'] : 'S' . $ch['Slot'] . 'C' . $ch['Channel'];
            $meterData[] = [
                'label' => $label,
                'db'    => isset($displayLevels[$key]) ? (float)$displayLevels[$key] : -60.0,
                'peak'  => isset($peaks[$key]) ? (float)$peaks[$key] : -60.0,
            ];
        }

        $jsonData = json_encode($meterData);

        $html = '<div id="bose-meter-root" style="font-family:-apple-system,\'Segoe UI\',Roboto,sans-serif;background:' . htmlspecialchars($bgColor, ENT_QUOTES) . ';padding:12px 12px 8px;margin:0;box-sizing:border-box;width:100%;">'
            . '<canvas id="bose-meter-canvas" style="display:block;"></canvas>'
            . '<script>(function(){'
            . 'var DATA=' . $jsonData . ';'
            . 'var YELLOW_DB=' . $yellowDb . ';'
            . 'var RED_DB=' . $redDb . ';'
            . 'var H=' . $height . ';'
            . 'var DB_MIN=-60,DB_MAX=0;'
            . 'var GAP=10,PAD_LEFT=36,PAD_TOP=16,PAD_BOTTOM=40;'
            . 'var W_MIN=20,W_MAX=60;'
            . 'var COL_GREEN=\'#00c853\',COL_YELLOW=\'#ffd600\',COL_RED=\'#ff1744\';'
            . 'var COL_BG=\'#0a0a1e\',COL_BORDER=\'#1e1e3a\',COL_TEXT=\'#9999bb\',COL_DB=\'#ccccee\',COL_ROOT=\'' . addslashes($bgColor) . '\';'
            . 'var DPR=window.devicePixelRatio||1;'
            . 'var canvas=document.getElementById(\'bose-meter-canvas\');'
            . 'var root=document.getElementById(\'bose-meter-root\');'
            . 'var availW=Math.max(200,(root.offsetWidth||window.innerWidth||300))-24;'
            . 'var n=Math.max(1,DATA.length);'
            . 'var W=Math.min(W_MAX,Math.max(W_MIN,Math.floor((availW-PAD_LEFT-GAP*(n-1))/n)));'
            . 'var totalW=PAD_LEFT+n*(W+GAP)-GAP+10;'
            . 'var totalH=PAD_TOP+H+PAD_BOTTOM;'
            . 'canvas.width=totalW*DPR;canvas.height=totalH*DPR;'
            . 'canvas.style.width=totalW+\'px\';canvas.style.height=totalH+\'px\';'
            . 'var ctx=canvas.getContext(\'2d\');ctx.scale(DPR,DPR);'
            . 'function dbToY(db){var c=Math.max(DB_MIN,Math.min(DB_MAX,db));return((c-DB_MIN)/(DB_MAX-DB_MIN))*H;}'
            . 'function drawBar(x,fillH,w){'
            . 'if(fillH<=0)return;'
            . 'var yellowY=dbToY(YELLOW_DB);var redY=dbToY(RED_DB);var base=PAD_TOP+H;'
            . 'var gH=Math.min(fillH,yellowY);'
            . 'if(gH>0){ctx.fillStyle=COL_GREEN;ctx.fillRect(x,base-gH,w,gH);}'
            . 'if(fillH>yellowY){var yH=Math.min(fillH,redY)-yellowY;if(yH>0){ctx.fillStyle=COL_YELLOW;ctx.fillRect(x,base-Math.min(fillH,redY),w,yH);}}'
            . 'if(fillH>redY){var rH=fillH-redY;ctx.fillStyle=COL_RED;ctx.fillRect(x,base-fillH,w,rH);}}'
            . 'function draw(){'
            . 'ctx.fillStyle=COL_ROOT;ctx.fillRect(0,0,totalW,totalH);'
            . 'ctx.font=\'9px monospace\';ctx.textAlign=\'right\';'
            . 'var scaleVals=[0,RED_DB,YELLOW_DB,-40,-60];'
            . 'for(var s=0;s<scaleVals.length;s++){'
            . 'var sy=PAD_TOP+H-dbToY(scaleVals[s]);'
            . 'ctx.fillStyle=scaleVals[s]>=RED_DB?\'#ff174466\':(scaleVals[s]>=YELLOW_DB?\'#ffd60066\':\'#44445e\');'
            . 'ctx.fillText(scaleVals[s].toString(),PAD_LEFT-8,sy+3);}'
            . 'for(var i=0;i<DATA.length;i++){'
            . 'var m=DATA[i];var x=PAD_LEFT+i*(W+GAP);var base=PAD_TOP+H;'
            . 'var dv=m.db;'
            . 'ctx.fillStyle=COL_BG;ctx.strokeStyle=COL_BORDER;ctx.lineWidth=1;'
            . 'ctx.fillRect(x,PAD_TOP,W,H);ctx.strokeRect(x-0.5,PAD_TOP-0.5,W+1,H+1);'
            . 'drawBar(x,dbToY(dv),W);'
            . 'var peakY=dbToY(m.peak);'
            . 'if(peakY>0){var peakColor=m.peak>=RED_DB?COL_RED:(m.peak>=YELLOW_DB?COL_YELLOW:COL_GREEN);'
            . 'ctx.fillStyle=peakColor;ctx.fillRect(x,base-peakY-1,W,2);}'
            . 'var dotX=x+W/2;var dotY=PAD_TOP-8;'
            . 'ctx.beginPath();ctx.arc(dotX,dotY,4,0,Math.PI*2);'
            . 'ctx.fillStyle=dv>-1?COL_RED:\'#2a2a3a\';ctx.fill();'
            . 'if(dv>-1){ctx.shadowColor=COL_RED;ctx.shadowBlur=6;ctx.fill();ctx.shadowBlur=0;}'
            . 'ctx.fillStyle=COL_DB;ctx.font=\'10px monospace\';ctx.textAlign=\'center\';'
            . 'var dbText=dv<=-59?\'\\u2013\\u221e\':dv.toFixed(1);'
            . 'ctx.fillText(dbText,x+W/2,base+14);'
            . 'ctx.fillStyle=COL_TEXT;ctx.font=\'10px sans-serif\';'
            . 'ctx.fillText(m.label,x+W/2,base+28);}}'
            . 'draw();'
            . '})();</script></div>';

        return $html;
    }

    // ── Private: Helpers ──────────────────────────────────────

    private function SetValueIfChanged(string $ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid === 0) return;
        if (GetValue($vid) === $value) return;
        $this->SetValue($ident, $value);
    }
}
